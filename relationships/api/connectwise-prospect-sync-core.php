<?php
/**
 * relationships/api/connectwise-prospect-sync-core.php
 *
 * Company Status sync -- pulls in EVERY non-Vendor ConnectWise Company
 * (regardless of status) and classifies each one into exactly one of three
 * buckets: Active, Prospect, or Residential (see
 * relationships_cw_classify_company_bucket() below). Originally (2026-09-10)
 * this only imported companies with no active agreement as zero-service
 * "Prospect" rows; broadened 2026-09-26 per Michael:
 *
 *   "Currently we call active customers anyone with an agreement. I need
 *   to expand that to include customers in the following statuses,
 *   leaving the rest to Prospects.
 *
 *   Active Connectwise Company statuses: Delinquent, Active, Special Info
 *   Prospect Companies with statuses: Inactive, Inactive-still approved,
 *   Prospect, not-approved should all fall under Prospects in Relationships
 *   hub. Reps should be able to toggle on/off each status to make their
 *   lists.
 *   I want to add another block for Residential customers. These are
 *   companies with the company status of Residential in ConnectWise."
 *
 * A company becomes a real (`is_mock = 0`) customer row with zero
 * `customer_services` the same way the original Prospect feature worked --
 * the dashboard/checklist/report code already renders a zero-service
 * customer as 100% missing across every pillar, exactly what "market every
 * service to them" means, so a company that's now Active-by-status-only
 * (no agreement, but ConnectWise shows it Active/Delinquent/Special Info)
 * needs no new UI logic either.
 *
 * `Active` = has a real agreement (unchanged from before this expansion) OR
 * ConnectWise status is Active/Delinquent/Special Info (new) -- an OR, not
 * a replacement, so an existing paying customer is never demoted to
 * Prospect just because ConnectWise shows an unexpected status.
 * `Residential` wins over everything else, per Michael's own answer when
 * asked directly: a company whose status is literally "Residential" lands
 * in the new Residential block even if it also holds a real agreement.
 * `Prospect` is the catch-all for everything else -- Michael named four
 * statuses explicitly (Inactive, Inactive-still approved, Prospect,
 * not-approved), but this bucket also covers any OTHER status not
 * recognized as Active or Residential, so an unnamed or future ConnectWise
 * status never silently vanishes from every list or wrongly counts as
 * Active. See relationships_cw_classify_company_bucket().
 *
 * Company Type is deliberately NOT a ConnectWise condition, and neither
 * (as of 2026-09-26) is Company Status -- both are filtered/classified in
 * PHP after the fetch. Company Type has been this way since 2026-09-10:
 * it's a many-valued field (a company can have several), and this
 * integration was burned three separate times (commits `a92a409`,
 * `2edc985`, `6340e8d` -- see claude/relationships-connectwise-sync.md)
 * by an unverified condition against a related/array field silently
 * matching nothing instead of erroring. Status moved to PHP-side for a
 * related but distinct reason: this sync used to fetch only companies
 * matching a small, known-working status condition
 * (`status/name='Active' or ...`); now that Prospect/Residential/Active
 * all need to be classified from a company's real status, the sync needs
 * to see EVERY non-Vendor company regardless of status, so there is no
 * status condition left to build at all -- relying on plain field
 * equality after the fetch also means the exact spelling ConnectWise uses
 * for "Inactive-still approved" etc. never has to be guessed or hardcoded;
 * relationships_cw_classify_company_bucket() only needs to recognize the
 * small Active/Residential set, and everything else falls through to
 * Prospect by construction.
 *
 * Same queue-based start()/step() shape as connectwise-sync-core.php and
 * connectwise-billing-sync-core.php, for the same Bluehost execution-time
 * reason -- but unlike those two, a step() here needs NO further
 * ConnectWise round-trip: start() already has everything (id, name,
 * status) from the one /company/companies list call, so step() is pure DB
 * work and can safely use a larger batch size.
 */

declare(strict_types=1);

require_once __DIR__ . '/connectwise.php';

/**
 * The three ConnectWise Company statuses that make a company "Active"
 * even with zero recorded agreements -- Michael's own list, 2026-09-26.
 * Matched case-insensitively (relationships_cw_status_matches()) since
 * ConnectWise's own casing hasn't been independently confirmed here.
 */
const RELATIONSHIPS_CW_ACTIVE_STATUSES = ['Active', 'Delinquent', 'Special Info'];

/**
 * The one ConnectWise Company status that gets its own Residential block,
 * regardless of agreements -- Michael's own answer, 2026-09-26, when asked
 * directly whether a Residential-status company with a real agreement
 * should stay Active or move to the new block: "Residential always wins."
 */
const RELATIONSHIPS_CW_RESIDENTIAL_STATUS = 'Residential';

/**
 * Case-insensitive, trimmed equality -- the one place a raw ConnectWise
 * status string ever gets compared in this file, so a stray leading/
 * trailing space or a casing difference (which this integration has been
 * burned by before -- see the board-name saga in
 * claude/relationships-connectwise-sync.md) doesn't silently misclassify
 * a company.
 */
function relationships_cw_status_matches(string $a, string $b): bool
{
    return strcasecmp(trim($a), trim($b)) === 0;
}

/**
 * Classifies one company into exactly one of 'active' | 'prospect' |
 * 'residential', per the rules in this file's header. $hasRealAgreement
 * is whether this company already has any real customer_services rows
 * (an existing agreement-backed customer) -- irrelevant once Residential
 * is matched, since Residential wins regardless.
 */
function relationships_cw_classify_company_bucket(string $statusName, bool $hasRealAgreement): string
{
    if (relationships_cw_status_matches($statusName, RELATIONSHIPS_CW_RESIDENTIAL_STATUS)) {
        return 'residential';
    }
    if ($hasRealAgreement) {
        return 'active';
    }
    foreach (RELATIONSHIPS_CW_ACTIVE_STATUSES as $activeName) {
        if (relationships_cw_status_matches($statusName, $activeName)) {
            return 'active';
        }
    }
    return 'prospect';
}

/**
 * True if this company's `types` array (as returned by /company/companies
 * with types in the fields list) contains anything matching "Vendor" --
 * a substring match, not exact, done here in PHP rather than as a
 * ConnectWise condition -- see file header for why.
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
 * Rebuilds the company-status queue from scratch: every non-Vendor
 * ConnectWise Company, whatever its status. Also resets is_prospect_only
 * AND is_residential back to 0 for every customer currently flagged --
 * same reset-then-recompute pattern as is_peoplefirst/voip_hosted_elsewhere
 * in connectwise-sync-core.php -- so a company that no longer matches its
 * old bucket (status changed, or picked up a Vendor type) correctly loses
 * the old flag once this full run completes, rather than it sticking
 * forever.
 */
function relationships_cw_prospect_sync_start(PDO $pdo): array
{
    $pdo->exec('DELETE FROM cw_prospect_sync_queue');
    $pdo->exec('UPDATE customers SET is_prospect_only = 0 WHERE is_prospect_only = 1');
    $pdo->exec('UPDATE customers SET is_residential = 0 WHERE is_residential = 1');

    $companies = relationships_cw_list(
        '/company/companies',
        '',
        ['id', 'identifier', 'name', 'status', 'types'],
        200
    );

    $insert = $pdo->prepare(
        "INSERT OR REPLACE INTO cw_prospect_sync_queue (connectwise_id, company_name, cw_status_name, status)
         VALUES (:cwid, :name, :status_name, 'pending')"
    );

    // Prospecting's 90-day claims (prospecting.php): a claimed company whose
    // ConnectWise Status is no longer "Prospect" has been promoted by the
    // Sales Manager -- close the claim so its countdown/alerts stop.
    $markPromoted = $pdo->prepare("UPDATE prospect_claims SET status = 'promoted' WHERE cw_company_id = :cwid AND status = 'active'");

    $total = 0;
    foreach ($companies as $c) {
        if (!isset($c['id'], $c['name'])) {
            continue;
        }
        if (relationships_cw_prospect_is_vendor($c)) {
            continue;
        }
        $statusName = is_array($c['status'] ?? null) ? (string) ($c['status']['name'] ?? '') : '';
        if ($statusName !== '' && strcasecmp($statusName, 'Prospect') !== 0) {
            $markPromoted->execute([':cwid' => (string) $c['id']]);
        }
        $insert->execute([':cwid' => (string) $c['id'], ':name' => (string) $c['name'], ':status_name' => $statusName]);
        $total++;
    }

    $pdo->prepare("INSERT OR REPLACE INTO cw_sync_meta (key, value) VALUES ('prospect_started_at', :v)")
        ->execute([':v' => date('c')]);
    $pdo->prepare("INSERT OR REPLACE INTO cw_sync_meta (key, value) VALUES ('prospect_total_queued', :v)")
        ->execute([':v' => (string) $total]);

    return ['total' => $total];
}

/**
 * Processes up to $batchSize pending queue rows. No ConnectWise round-trip
 * per row -- start() already has everything needed -- so this is just a
 * classify-then-upsert per company:
 *
 * - A customer WITH real synced services (an existing agreement-backed
 *   customer) never has its name/is_mock/services touched here -- the
 *   Agreement sync owns those. Its cw_status_name is still refreshed, and
 *   is_residential is still set/cleared, because Residential wins even
 *   over a real agreement (see file header); is_prospect_only is forced
 *   back to 0 defensively (it should already be 0 for these).
 * - A customer with NO real synced services is fully owned by this sync,
 *   same as before this feature -- name, cw_status_name, is_prospect_only,
 *   and is_residential are all (re)written from the live classification.
 */
function relationships_cw_prospect_sync_step(PDO $pdo, int $batchSize = 50): array
{
    $pending = $pdo->prepare("SELECT * FROM cw_prospect_sync_queue WHERE status = 'pending' ORDER BY connectwise_id LIMIT :n");
    $pending->bindValue(':n', $batchSize, PDO::PARAM_INT);
    $pending->execute();
    $rows = $pending->fetchAll(PDO::FETCH_ASSOC);

    $findCustomer = $pdo->prepare('SELECT id FROM customers WHERE connectwise_id = :cw');
    $hasServices = $pdo->prepare('SELECT 1 FROM customer_services WHERE customer_id = :id LIMIT 1');

    $updateServiced = $pdo->prepare(
        'UPDATE customers SET cw_status_name = :status_name, is_prospect_only = 0, is_residential = :is_res WHERE id = :id'
    );
    $updateUnserviced = $pdo->prepare(
        'UPDATE customers SET name = :name, is_mock = 0, cw_status_name = :status_name, is_prospect_only = :is_prospect, is_residential = :is_res WHERE id = :id'
    );
    $insertNew = $pdo->prepare(
        'INSERT INTO customers (connectwise_id, name, is_mock, cw_status_name, is_prospect_only, is_residential)
         VALUES (:cw, :name, 0, :status_name, :is_prospect, :is_res)'
    );

    $processed = 0;
    $errors = [];
    foreach ($rows as $row) {
        $cwId = (string) $row['connectwise_id'];
        $statusName = (string) ($row['cw_status_name'] ?? '');
        try {
            $findCustomer->execute([':cw' => $cwId]);
            $existing = $findCustomer->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                $customerId = (int) $existing['id'];
                $hasServices->execute([':id' => $customerId]);
                $hasReal = $hasServices->fetch() !== false;
                $bucket = relationships_cw_classify_company_bucket($statusName, $hasReal);

                if ($hasReal) {
                    $updateServiced->execute([
                        ':status_name' => $statusName,
                        ':is_res' => $bucket === 'residential' ? 1 : 0,
                        ':id' => $customerId,
                    ]);
                } else {
                    $updateUnserviced->execute([
                        ':name' => $row['company_name'],
                        ':status_name' => $statusName,
                        ':is_prospect' => $bucket === 'prospect' ? 1 : 0,
                        ':is_res' => $bucket === 'residential' ? 1 : 0,
                        ':id' => $customerId,
                    ]);
                }
            } else {
                $bucket = relationships_cw_classify_company_bucket($statusName, false);
                $insertNew->execute([
                    ':cw' => $cwId,
                    ':name' => $row['company_name'],
                    ':status_name' => $statusName,
                    ':is_prospect' => $bucket === 'prospect' ? 1 : 0,
                    ':is_res' => $bucket === 'residential' ? 1 : 0,
                ]);
            }

            $pdo->prepare("UPDATE cw_prospect_sync_queue SET status = 'done', processed_at = datetime('now'), error_message = NULL WHERE connectwise_id = :cw")
                ->execute([':cw' => $cwId]);
        } catch (Throwable $e) {
            $pdo->prepare("UPDATE cw_prospect_sync_queue SET status = 'error', processed_at = datetime('now'), error_message = :msg WHERE connectwise_id = :cw")
                ->execute([':cw' => $cwId, ':msg' => substr($e->getMessage(), 0, 500)]);
            $errors[] = ['connectwise_id' => $cwId, 'company_name' => $row['company_name'], 'error' => $e->getMessage()];
        }
        $processed++;
    }

    $counts = $pdo->query("SELECT status, COUNT(*) AS n FROM cw_prospect_sync_queue GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
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
