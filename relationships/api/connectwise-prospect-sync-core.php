<?php
/**
 * relationships/api/connectwise-prospect-sync-core.php
 *
 * ===== REDEFINED 2026-10-05 (read this first; the history below is older) =====
 * Per Michael, ConnectWise's Company Status is now the ONLY thing that
 * decides which list a company is in (all locations, Vendor types excluded):
 *
 *   Active      = status Active, Delinquent or Special Info
 *   Prospect    = status Inactive or Inactive - Still Approved
 *   Residential = status Residential
 *   anything else (Credit Hold, Lead Pursuit, Not Approved, ...), or a
 *   Vendor type, = excluded: hidden from every list and count.
 *
 * Having an agreement no longer makes a company Active, and Active no longer
 * hides companies that have no synced contacts (dashboard.php). The result
 * is stored in customers.cw_bucket. One exception: a company a rep claimed
 * through Prospecting (status "Prospect" in ConnectWise, active 90-day
 * claim) stays a Prospect while the claim is active.
 * Status names are matched ignoring case, spaces and punctuation, so
 * "Inactive-still approved" == "Inactive - Still Approved".
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
 *
 * NARROWED 2026-09-26, same day as the above, once Michael saw the actual
 * effect of fetching EVERY non-Vendor company regardless of status: it was
 * pulling in every permanently-inactive ConnectWise status too (anything
 * ConnectWise has ever used that isn't Active/Delinquent/Special Info or
 * Residential), each becoming its own zero-service "Prospect" customer row
 * -- a big part of what was overwhelming the Contacts sync (every row in
 * `customers` gets its own ConnectWise round-trip there). Per Michael:
 * "Company Statuses of Credit Hold, Inactive, Inactive - Still Approved,
 * Lead Pursuit and Prospect should be the ONLY company statuses considered
 * for Sync to Relationships. Any others should be ignored until their
 * status changes in ConnectWise." See RELATIONSHIPS_CW_PROSPECT_STATUSES
 * and the new 'ignore' bucket in relationships_cw_classify_company_bucket()
 * below -- a status outside that allowlist (and outside Active/Residential)
 * now gets NO new customer row created for it, and an existing zero-service
 * customer whose status moved out of the allowlist simply stops being
 * flagged is_prospect_only (already reset to 0 for everyone at the top of
 * start(), then only re-set for rows that still classify as 'prospect') --
 * it drops out of the Prospects list without anything being deleted.
 */

declare(strict_types=1);

require_once __DIR__ . '/connectwise.php';

/** ConnectWise Company statuses that make a company "Active". */
const RELATIONSHIPS_CW_ACTIVE_STATUSES = ['Active', 'Delinquent', 'Special Info'];

/** ConnectWise Company statuses that make a company a "Prospect". */
const RELATIONSHIPS_CW_PROSPECT_STATUSES = ['Inactive', 'Inactive - Still Approved'];

/** The ConnectWise Company status that makes a company "Residential". */
const RELATIONSHIPS_CW_RESIDENTIAL_STATUS = 'Residential';

/**
 * Status comparison key: lower-cased with everything except letters/digits
 * removed, so "Inactive-still approved", "Inactive - Still Approved" and
 * "inactive still approved" all compare equal.
 */
function relationships_cw_status_key(string $status): string
{
    return strtolower(preg_replace('/[^A-Za-z0-9]+/', '', $status) ?? '');
}

function relationships_cw_status_matches(string $a, string $b): bool
{
    return relationships_cw_status_key($a) === relationships_cw_status_key($b);
}

function relationships_cw_status_in(string $status, array $names): bool
{
    foreach ($names as $name) {
        if (relationships_cw_status_matches($status, (string) $name)) {
            return true;
        }
    }
    return false;
}

/** True if $statusName is one of the Prospect statuses above. */
function relationships_cw_prospect_status_allowed(string $statusName): bool
{
    return relationships_cw_status_in($statusName, RELATIONSHIPS_CW_PROSPECT_STATUSES);
}

/**
 * Classifies one company into exactly one of 'active' | 'prospect' |
 * 'residential' | 'excluded' from its ConnectWise Company Status and
 * Vendor flag ONLY -- see the REDEFINED note in this file's header.
 */
function relationships_cw_classify_company_bucket(string $statusName, bool $isVendor = false): string
{
    if ($isVendor) {
        return 'excluded';
    }
    if (relationships_cw_status_matches($statusName, RELATIONSHIPS_CW_RESIDENTIAL_STATUS)) {
        return 'residential';
    }
    if (relationships_cw_status_in($statusName, RELATIONSHIPS_CW_ACTIVE_STATUSES)) {
        return 'active';
    }
    if (relationships_cw_prospect_status_allowed($statusName)) {
        return 'prospect';
    }
    return 'excluded';
}

/**
 * True if this company's `types` array contains anything matching "Vendor"
 * (a substring match, same as ConnectWise's "Type NOT CONTAINS Vendor"),
 * done here in PHP rather than as a ConnectWise condition -- see the file
 * header history for why.
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
 * Rebuilds the company-status queue from scratch: EVERY ConnectWise Company
 * (Vendors included, flagged is_vendor, so their existing rows can be hidden).
 * Nothing is reset up front any more -- step() rewrites each company's flags
 * from its live status, and the last step hides rows ConnectWise no longer
 * returns -- so an interrupted run leaves the previous classification in
 * place instead of dumping everyone into Active.
 */
function relationships_cw_prospect_sync_start(PDO $pdo): array
{
    $pdo->exec('DELETE FROM cw_prospect_sync_queue');

    $companies = relationships_cw_list(
        '/company/companies',
        '',
        ['id', 'identifier', 'name', 'status', 'types'],
        200
    );

    // Demo/mock rows only exist before the first real sync; once ConnectWise
    // answers they are no longer part of any real list or count.
    $pdo->exec("UPDATE customers SET cw_bucket = 'excluded', is_prospect_only = 0, is_residential = 0 WHERE is_mock = 1");

    $insert = $pdo->prepare(
        "INSERT OR REPLACE INTO cw_prospect_sync_queue (connectwise_id, company_name, cw_status_name, is_vendor, status)
         VALUES (:cwid, :name, :status_name, :vendor, 'pending')"
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
        $statusName = is_array($c['status'] ?? null) ? (string) ($c['status']['name'] ?? '') : '';
        if ($statusName !== '' && strcasecmp($statusName, 'Prospect') !== 0) {
            $markPromoted->execute([':cwid' => (string) $c['id']]);
        }
        $insert->execute([
            ':cwid' => (string) $c['id'],
            ':name' => (string) $c['name'],
            ':status_name' => $statusName,
            ':vendor' => relationships_cw_prospect_is_vendor($c) ? 1 : 0,
        ]);
        $total++;
    }

    $pdo->prepare("INSERT OR REPLACE INTO cw_sync_meta (key, value) VALUES ('prospect_started_at', :v)")
        ->execute([':v' => date('c')]);
    $pdo->prepare("INSERT OR REPLACE INTO cw_sync_meta (key, value) VALUES ('prospect_total_queued', :v)")
        ->execute([':v' => (string) $total]);

    return ['total' => $total];
}

/**
 * Processes up to $batchSize pending queue rows (pure DB work -- start()
 * already fetched everything): classify each company from its status and
 * write cw_bucket + the is_prospect_only / is_residential flags the rest of
 * the app reads. Applies to every customer row, with or without synced
 * services -- an agreement no longer overrides the status.
 */
function relationships_cw_prospect_sync_step(PDO $pdo, int $batchSize = 50): array
{
    $pending = $pdo->prepare("SELECT * FROM cw_prospect_sync_queue WHERE status = 'pending' ORDER BY connectwise_id LIMIT :n");
    $pending->bindValue(':n', $batchSize, PDO::PARAM_INT);
    $pending->execute();
    $rows = $pending->fetchAll(PDO::FETCH_ASSOC);

    $findCustomer = $pdo->prepare('SELECT id FROM customers WHERE connectwise_id = :cw');
    $hasActiveClaim = $pdo->prepare("SELECT 1 FROM prospect_claims WHERE status = 'active' AND (cw_company_id = :cw OR customer_id = :cid) LIMIT 1");
    $update = $pdo->prepare(
        'UPDATE customers SET name = :name, is_mock = 0, cw_status_name = :status_name, cw_bucket = :bucket,
                is_prospect_only = :is_prospect, is_residential = :is_res WHERE id = :id'
    );
    $insertNew = $pdo->prepare(
        'INSERT INTO customers (connectwise_id, name, is_mock, cw_status_name, cw_bucket, is_prospect_only, is_residential)
         VALUES (:cw, :name, 0, :status_name, :bucket, :is_prospect, :is_res)'
    );

    $processed = 0;
    $errors = [];
    foreach ($rows as $row) {
        $cwId = (string) $row['connectwise_id'];
        $statusName = (string) ($row['cw_status_name'] ?? '');
        $isVendor = (int) ($row['is_vendor'] ?? 0) === 1;
        try {
            $bucket = relationships_cw_classify_company_bucket($statusName, $isVendor);

            $findCustomer->execute([':cw' => $cwId]);
            $existing = $findCustomer->fetch(PDO::FETCH_ASSOC);
            $customerId = $existing ? (int) $existing['id'] : 0;

            // A company claimed through Prospecting (ConnectWise status
            // "Prospect") stays a Prospect while its 90-day claim is active.
            if ($bucket === 'excluded' && !$isVendor && relationships_cw_status_matches($statusName, 'Prospect')) {
                $hasActiveClaim->execute([':cw' => $cwId, ':cid' => $customerId]);
                if ($hasActiveClaim->fetch() !== false) {
                    $bucket = 'prospect';
                }
            }

            $isProspect = $bucket === 'prospect' ? 1 : 0;
            $isRes = $bucket === 'residential' ? 1 : 0;

            if ($existing) {
                $update->execute([
                    ':name' => $row['company_name'],
                    ':status_name' => $statusName,
                    ':bucket' => $bucket,
                    ':is_prospect' => $isProspect,
                    ':is_res' => $isRes,
                    ':id' => $customerId,
                ]);
            } elseif ($bucket !== 'excluded') {
                $insertNew->execute([
                    ':cw' => $cwId,
                    ':name' => $row['company_name'],
                    ':status_name' => $statusName,
                    ':bucket' => $bucket,
                    ':is_prospect' => $isProspect,
                    ':is_res' => $isRes,
                ]);
            }
            // else: excluded and no row yet -- deliberately create nothing.

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

    // Last batch: any real customer row whose company ConnectWise no longer
    // returns (deleted/merged) drops out of every list.
    if ($remaining === 0 && array_sum(array_map('intval', $counts)) > 0) {
        $pdo->exec(
            "UPDATE customers SET cw_bucket = 'excluded', is_prospect_only = 0, is_residential = 0
             WHERE is_mock = 0 AND COALESCE(connectwise_id, '') != ''
               AND connectwise_id NOT IN (SELECT connectwise_id FROM cw_prospect_sync_queue)"
        );
    }

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

/**
 * Reconciliation numbers for the ConnectWise Sync screen: the stored bucket
 * totals plus a per-status breakdown, so they can be compared against
 * ConnectWise's own company counts. Pure DB read.
 */
function relationships_cw_company_counts(PDO $pdo): array
{
    $bucketRows = $pdo->query(
        "SELECT COALESCE(cw_bucket, 'unclassified') AS b, COUNT(*) AS n FROM customers WHERE is_mock = 0 GROUP BY b"
    )->fetchAll(PDO::FETCH_KEY_PAIR);
    $statusRows = $pdo->query(
        "SELECT COALESCE(cw_bucket, 'unclassified') AS b, COALESCE(NULLIF(TRIM(cw_status_name), ''), '(no status)') AS s, COUNT(*) AS n
         FROM customers WHERE is_mock = 0 GROUP BY b, s ORDER BY b, n DESC"
    )->fetchAll(PDO::FETCH_ASSOC);
    $claimed = (int) $pdo->query(
        "SELECT COUNT(*) FROM customers c JOIN prospect_claims pc ON pc.customer_id = c.id
         WHERE pc.status = 'active' AND c.cw_bucket = 'prospect' AND LOWER(COALESCE(c.cw_status_name, '')) = 'prospect'"
    )->fetchColumn();
    $activeNoContacts = (int) $pdo->query(
        "SELECT COUNT(*) FROM customers WHERE is_mock = 0 AND COALESCE(cw_bucket, 'active') = 'active' AND COALESCE(active_contact_count, 0) = 0"
    )->fetchColumn();
    $meta = $pdo->query('SELECT key, value FROM cw_sync_meta')->fetchAll(PDO::FETCH_KEY_PAIR);

    $buckets = [];
    foreach (['active', 'prospect', 'residential', 'excluded', 'unclassified'] as $b) {
        $buckets[$b] = (int) ($bucketRows[$b] ?? 0);
    }
    $byStatus = [];
    foreach ($statusRows as $r) {
        $byStatus[] = ['bucket' => $r['b'], 'status' => $r['s'], 'count' => (int) $r['n']];
    }

    return [
        'buckets' => $buckets,
        'by_status' => $byStatus,
        'prospect_claimed' => $claimed,
        'active_without_contacts' => $activeNoContacts,
        'last_company_sync' => $meta['prospect_started_at'] ?? null,
        'companies_seen_last_sync' => isset($meta['prospect_total_queued']) ? (int) $meta['prospect_total_queued'] : null,
    ];
}
