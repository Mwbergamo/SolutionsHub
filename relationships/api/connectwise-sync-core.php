<?php
/**
 * relationships/api/connectwise-sync-core.php
 *
 * The actual ConnectWise -> customers/customer_services sync, shared by
 * the "Sync Now" HTTP endpoint (sync.php, driven by a logged-in CRC) and
 * the CLI cron script (connectwise-cron.php). Deliberately split into
 * start() + step() rather than one long-running function: Bluehost shared
 * hosting can (and does elsewhere in this app) cut off long-running HTTP
 * requests, and processing ~440 agreements one at a time can take several
 * minutes end to end. start() builds a queue of every agreement to
 * process (a handful of cheap list calls); step() drains a bounded batch
 * of it per call, so the caller loops until { done: true }. The CLI script
 * doesn't have that timeout constraint and just loops with a large batch
 * size until done.
 *
 * Only agreement types with a confirmed or best-effort pillar/service
 * mapping are synced -- see connectwise-classify.php's file comment for
 * which pillars are precisely classified (IT Services' cross-sell-tracked
 * services, Voice's cloud-voice, Premise Security) vs. best-effort
 * (Data Center) vs. not synced at all (Data Cabling -- no known agreement
 * type).
 */

declare(strict_types=1);

require_once __DIR__ . '/connectwise.php';
require_once __DIR__ . '/connectwise-classify.php';
require_once __DIR__ . '/catalog.php';

const RELATIONSHIPS_CW_AGREEMENT_TYPES = [65, 66, 67, 64];

/**
 * Rebuilds the sync queue from scratch: fetches every active agreement
 * (id + company) for each tracked agreement type and replaces
 * cw_sync_queue wholesale. Cheap -- this is ~5 list requests for ~440
 * rows, well within a single HTTP request's time budget.
 */
function relationships_cw_sync_start(PDO $pdo): array
{
    $pdo->exec('DELETE FROM cw_sync_queue');

    // is_peoplefirst and voip_hosted_elsewhere are both aggregates over
    // (potentially several) of a customer's agreements -- recomputed from
    // scratch each full sync rather than only ever set, so a customer who
    // no longer qualifies (agreement renamed/removed, or a previously
    // empty Voice Agreement now has real line items on it) actually loses
    // the flag instead of it sticking forever. Mock customers are
    // untouched.
    $pdo->exec('UPDATE customers SET is_peoplefirst = 0 WHERE is_mock = 0');
    $pdo->exec("UPDATE customers SET voip_hosted_elsewhere = 0, voip_hosted_agreement_name = NULL WHERE is_mock = 0");

    $insert = $pdo->prepare(
        'INSERT INTO cw_sync_queue (agreement_id, agreement_type_id, agreement_name, company_cw_id, company_name, status)
         VALUES (:id, :type, :aname, :cwid, :name, \'pending\')'
    );

    $total = 0;
    foreach (RELATIONSHIPS_CW_AGREEMENT_TYPES as $typeId) {
        $agreements = relationships_cw_list(
            '/finance/agreements',
            "type/id=$typeId and agreementStatus='Active'",
            ['id', 'name', 'company', 'type']
        );
        foreach ($agreements as $a) {
            if (!isset($a['id'], $a['company']['id'], $a['company']['name'])) {
                continue;
            }
            $insert->execute([
                ':id' => $a['id'],
                ':type' => $typeId,
                ':aname' => (string) ($a['name'] ?? ''),
                ':cwid' => (string) $a['company']['id'],
                ':name' => $a['company']['name'],
            ]);
            $total++;
        }
    }

    $pdo->prepare('INSERT OR REPLACE INTO cw_sync_meta (key, value) VALUES (\'started_at\', :v)')
        ->execute([':v' => date('c')]);
    $pdo->prepare('INSERT OR REPLACE INTO cw_sync_meta (key, value) VALUES (\'total_queued\', :v)')
        ->execute([':v' => (string) $total]);

    return ['total' => $total];
}

/**
 * Processes up to $batchSize pending queue rows: fetches each agreement's
 * active additions, classifies them, and upserts the corresponding
 * customer + customer_services rows. One agreement's failure (a
 * ConnectWise timeout, an unexpected response shape) is caught and
 * recorded on that queue row rather than aborting the whole batch.
 */
function relationships_cw_sync_step(PDO $pdo, int $batchSize = 20): array
{
    $catalog = relationships_catalog();

    $pending = $pdo->prepare('SELECT * FROM cw_sync_queue WHERE status = \'pending\' ORDER BY agreement_id LIMIT :n');
    $pending->bindValue(':n', $batchSize, PDO::PARAM_INT);
    $pending->execute();
    $rows = $pending->fetchAll(PDO::FETCH_ASSOC);

    $processed = 0;
    $errors = [];
    foreach ($rows as $row) {
        $agreementId = (int) $row['agreement_id'];
        try {
            relationships_cw_sync_one_agreement($pdo, $catalog, $agreementId, (int) $row['agreement_type_id'], (string) $row['agreement_name'], $row['company_cw_id'], $row['company_name']);
            $pdo->prepare('UPDATE cw_sync_queue SET status = \'done\', processed_at = datetime(\'now\'), error_message = NULL WHERE agreement_id = :id')
                ->execute([':id' => $agreementId]);
        } catch (Throwable $e) {
            $pdo->prepare('UPDATE cw_sync_queue SET status = \'error\', processed_at = datetime(\'now\'), error_message = :msg WHERE agreement_id = :id')
                ->execute([':id' => $agreementId, ':msg' => substr($e->getMessage(), 0, 500)]);
            $errors[] = ['agreement_id' => $agreementId, 'company_name' => $row['company_name'], 'error' => $e->getMessage()];
        }
        $processed++;
    }

    $counts = $pdo->query('SELECT status, COUNT(*) AS n FROM cw_sync_queue GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
    $remaining = (int) ($counts['pending'] ?? 0);

    return [
        'processed_this_batch' => $processed,
        'remaining' => $remaining,
        'done' => $remaining === 0,
        'totals' => [
            'pending' => (int) ($counts['pending'] ?? 0),
            'done' => (int) ($counts['done'] ?? 0),
            'error' => (int) ($counts['error'] ?? 0),
        ],
        'errors' => $errors,
    ];
}

function relationships_cw_sync_one_agreement(PDO $pdo, array $catalog, int $agreementId, int $agreementTypeId, string $agreementName, string $companyCwId, string $companyName): void
{
    $additions = relationships_cw_list(
        "/finance/agreements/$agreementId/additions",
        "additionStatus='Active'",
        ['product', 'description', 'invoiceDescription', 'quantity', 'uom']
    );

    // Resolve (and create/rename) the local customer row for this
    // ConnectWise company before touching customer_services, so every
    // addition -- even if none classify to anything -- at least gets its
    // customer registered as a real (non-mock) account.
    $custStmt = $pdo->prepare('SELECT id FROM customers WHERE connectwise_id = :cw');
    $custStmt->execute([':cw' => $companyCwId]);
    $existing = $custStmt->fetch(PDO::FETCH_ASSOC);
    if ($existing) {
        $customerId = (int) $existing['id'];
        $pdo->prepare('UPDATE customers SET name = :name, is_mock = 0 WHERE id = :id')
            ->execute([':name' => $companyName, ':id' => $customerId]);
    } else {
        $pdo->prepare('INSERT INTO customers (connectwise_id, name, is_mock) VALUES (:cw, :name, 0)')
            ->execute([':cw' => $companyCwId, ':name' => $companyName]);
        $customerId = (int) $pdo->lastInsertId();
    }

    // Replace this agreement's own rows only -- a customer can have
    // agreements of more than one type (e.g. IT + Voice), and each is
    // resynced independently.
    $pdo->prepare('DELETE FROM customer_services WHERE cw_agreement_id = :aid')
        ->execute([':aid' => $agreementId]);

    $insert = $pdo->prepare(
        'INSERT INTO customer_services (customer_id, pillar_id, pillar_name, service_id, service_name, product_label, qty, unit, source, cw_agreement_id)
         VALUES (:customer_id, :pillar_id, :pillar_name, :service_id, :service_name, :product_label, :qty, :unit, \'connectwise\', :aid)'
    );

    // PeopleFirst: CodeBlue's top-tier, most-inclusive IT Services package.
    // Two independent signals, either one is enough -- confirmed against
    // real ConnectWise data 2026-09-10: the agreement itself is literally
    // named "IT Services Agreement - PeopleFirst Support", and/or it
    // carries an active CBT-PF-MEMBER addition (per-person "PeopleFirst
    // Support Member" line item). These customers get little to no
    // cross-sell -- they already have most everything -- but are flagged
    // here so the dashboard can call out that they're due a quarterly risk
    // assessment / client visit instead.
    $isPeopleFirst = $agreementTypeId === 65 && stripos($agreementName, 'peoplefirst') !== false;

    foreach ($additions as $add) {
        $productId = (string) ($add['product']['identifier'] ?? '');
        $desc = (string) ($add['description'] ?? '');
        $invDesc = (string) ($add['invoiceDescription'] ?? '');

        if (strcasecmp($productId, 'CBT-PF-MEMBER') === 0) {
            $isPeopleFirst = true;
        }

        $classified = relationships_cw_classify($agreementTypeId, $productId, $desc, $invDesc);
        if ($classified === null) {
            continue; // unrecognized Premise Security item -- skip rather than guess
        }
        $pillarId = $classified['pillar_id'];
        $serviceId = $classified['service_id'];
        if (!isset($catalog[$pillarId]['services'][$serviceId])) {
            continue; // defensive -- classifier returned something not in the catalog
        }

        $label = $desc !== '' ? $desc : ($invDesc !== '' ? $invDesc : $productId);
        $qty = isset($add['quantity']) ? (float) $add['quantity'] : 1.0;

        $insert->execute([
            ':customer_id' => $customerId,
            ':pillar_id' => $pillarId,
            ':pillar_name' => $catalog[$pillarId]['name'],
            ':service_id' => $serviceId,
            ':service_name' => $catalog[$pillarId]['services'][$serviceId],
            ':product_label' => $label,
            ':qty' => $qty,
            ':unit' => $add['uom'] ?? null,
            ':aid' => $agreementId,
        ]);
    }

    if ($isPeopleFirst) {
        // Only ever sets it true here -- relationships_cw_sync_start()
        // reset everyone to false at the top of this run, so a customer
        // whose only PeopleFirst-flagged agreement no longer qualifies
        // correctly ends up false once the whole run completes.
        $pdo->prepare('UPDATE customers SET is_peoplefirst = 1 WHERE id = :id')
            ->execute([':id' => $customerId]);
    }

    // Manufacturer-hosted voice platform (e.g. Zultys Hosted): CodeBlue
    // represents this with an active Voice Agreement that's deliberately
    // left empty -- no additions at all, since CBT isn't selling anything
    // against it. That's distinct from a Voice Agreement whose items just
    // didn't classify (those still have additions, they'd just be
    // skipped/miscategorized) -- only a truly empty agreement counts.
    // Same reset-then-set pattern as is_peoplefirst above.
    if ($agreementTypeId === 66 && count($additions) === 0) {
        $pdo->prepare('UPDATE customers SET voip_hosted_elsewhere = 1, voip_hosted_agreement_name = :n WHERE id = :id')
            ->execute([':n' => $agreementName, ':id' => $customerId]);
    }
}
