<?php
/**
 * relationships/api/connectwise-prospect-sync-core.php
 *
 * Pulls in ConnectWise Companies that have a real, active-ish relationship
 * with CBT but NO active agreement of any tracked type (see
 * RELATIONSHIPS_CW_AGREEMENT_TYPES in connectwise-sync-core.php) -- e.g. a
 * one-off hardware sale, a company mid-onboarding, or simply a lead
 * ConnectWise already has a record for. Added 2026-09-10 per Michael, so
 * Relationship Coordinators can see (and market EVERY service to) these
 * companies the same way they already do for an existing customer missing
 * a few cross-sell services -- a prospect with zero recorded services just
 * shows every pillar/service as "NOT IN USE" already, via the exact same
 * dashboard/checklist/report code every other customer uses. No special-
 * case UI logic was needed for that part; is_prospect_only only exists to
 * badge these clearly as "zero existing relationship" rather than "missing
 * a few things" (see customers.php, app.js).
 *
 * Filter (Michael's own ConnectWise saved view, screenshotted 2026-09-10):
 * Status = Active, Delinquent, or Special Info, AND Type does not include
 * Vendor. The Status half is a plain related-field condition, matching the
 * single-quote equality style already proven working elsewhere in this
 * integration (connectwise-sync-core.php's agreementStatus='Active',
 * connectwise-activity.php's board/name= after three hard-won passes at
 * getting that syntax right). The Type half is NOT built as a ConnectWise
 * condition at all, deliberately: Company Type is a many-valued field
 * (a company can have several), and this integration has now been burned
 * three separate times by an unverified condition against a related/array
 * field silently matching nothing instead of erroring. Types is instead
 * requested as a plain field and filtered in PHP after the fact -- slower
 * per row, but impossible to get subtly wrong the way board/name was.
 *
 * Same queue-based start()/step() shape as connectwise-sync-core.php and
 * connectwise-billing-sync-core.php, for the same Bluehost execution-time
 * reason -- but unlike those two, a step() here needs NO further
 * ConnectWise round-trip: start() already has everything (id, name) from
 * the one /company/companies list call, so step() is pure DB work and can
 * safely use a larger batch size.
 */

declare(strict_types=1);

require_once __DIR__ . '/connectwise.php';

const RELATIONSHIPS_CW_PROSPECT_STATUSES = ['Active', 'Delinquent', 'Special Info'];

/**
 * `(status/name='Active' or status/name='Delinquent' or status/name='Special Info')`
 * -- single-quote equality, the one style in this integration actually
 * proven against real ConnectWise data (see file header).
 */
function relationships_cw_prospect_status_condition(): string
{
    $clauses = array_map(
        static function (string $name): string {
            $escaped = str_replace("'", "\\'", $name);
            return "status/name='$escaped'";
        },
        RELATIONSHIPS_CW_PROSPECT_STATUSES
    );
    return '(' . implode(' or ', $clauses) . ')';
}

/**
 * True if this company's `types` array (as returned by /company/companies
 * with types in the fields list) contains anything matching "Vendor" --
 * mirrors the saved view's "Type NOT CONTAINS Vendor" (a substring match,
 * not exact), done here in PHP rather than as a ConnectWise condition --
 * see file header for why.
 */
function relationships_cw_prospect_is_vendor(array $company): bool
{
    $types = $company['types'] ?? [];
    if (!is_array($types)) {
        return false;
    }
    foreach ($types as $t) {
        $name = is_array($t) ? (string) ($t['name'] ?? '') : (string) $t;
        if (stripos($name, 'vendor') !== false) {
            return true;
        }
    }
    return false;
}

/**
 * Rebuilds the prospect queue from scratch: every ConnectWise Company
 * matching the status filter and not a Vendor type. Also resets
 * is_prospect_only back to 0 for every customer currently flagged, same
 * reset-then-recompute pattern as is_peoplefirst/voip_hosted_elsewhere in
 * connectwise-sync-core.php -- a company that no longer matches the filter
 * (status changed, or picked up a Vendor type) correctly loses the flag
 * once this full run completes, rather than it sticking forever. Never
 * touches a customer with real synced services (see relationships_cw_prospect_sync_step()).
 */
function relationships_cw_prospect_sync_start(PDO $pdo): array
{
    $pdo->exec('DELETE FROM cw_prospect_sync_queue');
    $pdo->exec('UPDATE customers SET is_prospect_only = 0 WHERE is_prospect_only = 1');

    $companies = relationships_cw_list(
        '/company/companies',
        relationships_cw_prospect_status_condition(),
        ['id', 'identifier', 'name', 'status', 'types'],
        200
    );

    $insert = $pdo->prepare(
        'INSERT OR REPLACE INTO cw_prospect_sync_queue (connectwise_id, company_name, status)
         VALUES (:cwid, :name, \'pending\')'
    );

    $total = 0;
    foreach ($companies as $c) {
        if (!isset($c['id'], $c['name'])) {
            continue;
        }
        if (relationships_cw_prospect_is_vendor($c)) {
            continue;
        }
        $insert->execute([':cwid' => (string) $c['id'], ':name' => (string) $c['name']]);
        $total++;
    }

    $pdo->prepare('INSERT OR REPLACE INTO cw_sync_meta (key, value) VALUES (\'prospect_started_at\', :v)')
        ->execute([':v' => date('c')]);
    $pdo->prepare('INSERT OR REPLACE INTO cw_sync_meta (key, value) VALUES (\'prospect_total_queued\', :v)')
        ->execute([':v' => (string) $total]);

    return ['total' => $total];
}

/**
 * Processes up to $batchSize pending queue rows. No ConnectWise round-trip
 * per row -- start() already has everything needed -- so this is just:
 * for each queued company, leave a customer with real synced services
 * (any customer_services row) completely untouched, otherwise create or
 * refresh a zero-service prospect customer row. A customer that later gets
 * a real agreement is picked up correctly by the NEXT agreement sync
 * (relationships_cw_sync_one_agreement() clears is_prospect_only whenever
 * it upserts a customer), not by this one.
 */
function relationships_cw_prospect_sync_step(PDO $pdo, int $batchSize = 50): array
{
    $pending = $pdo->prepare('SELECT * FROM cw_prospect_sync_queue WHERE status = \'pending\' ORDER BY connectwise_id LIMIT :n');
    $pending->bindValue(':n', $batchSize, PDO::PARAM_INT);
    $pending->execute();
    $rows = $pending->fetchAll(PDO::FETCH_ASSOC);

    $findCustomer = $pdo->prepare('SELECT id FROM customers WHERE connectwise_id = :cw');
    $hasServices = $pdo->prepare('SELECT 1 FROM customer_services WHERE customer_id = :id LIMIT 1');
    $updateExisting = $pdo->prepare('UPDATE customers SET name = :name, is_mock = 0, is_prospect_only = 1 WHERE id = :id');
    $insertNew = $pdo->prepare('INSERT INTO customers (connectwise_id, name, is_mock, is_prospect_only) VALUES (:cw, :name, 0, 1)');

    $processed = 0;
    $errors = [];
    foreach ($rows as $row) {
        $cwId = (string) $row['connectwise_id'];
        try {
            $findCustomer->execute([':cw' => $cwId]);
            $existing = $findCustomer->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                $customerId = (int) $existing['id'];
                $hasServices->execute([':id' => $customerId]);
                if ($hasServices->fetch() === false) {
                    // No real services recorded for this customer -- safe
                    // to (re)mark as a prospect. A customer WITH services
                    // is a real agreement customer and is left alone.
                    $updateExisting->execute([':name' => $row['company_name'], ':id' => $customerId]);
                }
            } else {
                $insertNew->execute([':cw' => $cwId, ':name' => $row['company_name']]);
            }

            $pdo->prepare('UPDATE cw_prospect_sync_queue SET status = \'done\', processed_at = datetime(\'now\'), error_message = NULL WHERE connectwise_id = :cw')
                ->execute([':cw' => $cwId]);
        } catch (Throwable $e) {
            $pdo->prepare('UPDATE cw_prospect_sync_queue SET status = \'error\', processed_at = datetime(\'now\'), error_message = :msg WHERE connectwise_id = :cw')
                ->execute([':cw' => $cwId, ':msg' => substr($e->getMessage(), 0, 500)]);
            $errors[] = ['connectwise_id' => $cwId, 'company_name' => $row['company_name'], 'error' => $e->getMessage()];
        }
        $processed++;
    }

    $counts = $pdo->query('SELECT status, COUNT(*) AS n FROM cw_prospect_sync_queue GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
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
