<?php
/**
 * relationships/api/connectwise-billing-sync-core.php
 *
 * Nightly-synced version of the Monthly Billing panel's 6-month series.
 * Added 2026-09-10 per Michael, after the live-query design (see
 * connectwise-activity.php) turned out to cost a ConnectWise round-trip on
 * every single dashboard open. Decision: Monthly Billing moves to the same
 * nightly sync cadence as the agreement/addition sync below, since a CRC
 * doesn't need it accurate to the second; Service Tickets YTD stays live
 * (unchanged, still in connectwise-activity.php) since that's the kind of
 * number a CRC plausibly wants as-of-right-now on a support call. See
 * claude/relationships-connectwise-sync.md in the project for the full
 * write-up of this trade-off.
 *
 * Same queue-based start()/step() shape as connectwise-sync-core.php, for
 * the same reason (Bluehost's execution-time limit on a single HTTP
 * request) -- but queued by CUSTOMER rather than by agreement, since one
 * /finance/invoices call (via relationships_cw_activity_monthly_billing(),
 * reused as-is from the live path so both stay on exactly the same, tested
 * field mapping -- see that file's header for the invoiceDate/applyToType
 * fixes this inherits automatically) covers a customer's whole 6-month
 * series in one shot.
 *
 * 2026-09-27: batched -- see RELATIONSHIPS_CW_SYNC_BATCH_SIZE's comment in
 * connectwise.php. This stage (like Ticket History and Contacts) was
 * making one ConnectWise call per customer, sequentially; at this
 * integration's current customer volume that was the real cause of a
 * 2+ hour sync runtime and, for the Contacts stage specifically, a ~70%
 * failure rate -- see claude/relationships-connectwise-sync.md for the
 * full write-up. relationships_cw_activity_monthly_billing_batch()/
 * relationships_cw_activity_yearly_billing_batch() (connectwise-activity.php)
 * now cover up to RELATIONSHIPS_CW_SYNC_BATCH_SIZE customers per call.
 *
 * 2026-09-15: gained annual-cadence detection, per Michael, after he
 * noticed Evolution Divorce & Family Law's Monthly Billing panel showing
 * $0 across all 6 months despite real Agreement invoices existing in
 * ConnectWise -- turned out to be a real, non-erroring $0: that customer
 * bills once a year (in March), which the trailing-6-month window simply
 * never catches. When the normal monthly series comes back all-$0, this
 * step now also checks a wider 3-year window (relationships_cw_activity_yearly_billing())
 * and, if THAT finds real billing, stores it in customer_yearly_billing
 * and marks customers.billing_cadence = 'annual' instead of writing a
 * silently-empty monthly chart. See claude/relationships-annual-billing-cadence.md
 * in the project for the full write-up.
 */

declare(strict_types=1);

require_once __DIR__ . '/connectwise-activity.php';

/**
 * Rebuilds the billing sync queue: every real (non-mock), ConnectWise-
 * linked customer -- the same "available" rule activity.php already uses
 * (relationships_activity_cw_id()) to decide whether a customer has
 * anything to look up.
 */
function relationships_cw_billing_sync_start(PDO $pdo): array
{
    $pdo->exec('DELETE FROM cw_billing_sync_queue');

    $rows = $pdo->query(
        "SELECT id, connectwise_id, name FROM customers
         WHERE is_mock = 0 AND connectwise_id IS NOT NULL AND connectwise_id != ''
           AND connectwise_id NOT LIKE 'MOCK-%'"
    )->fetchAll(PDO::FETCH_ASSOC);

    $insert = $pdo->prepare(
        'INSERT INTO cw_billing_sync_queue (customer_id, connectwise_id, company_name, status)
         VALUES (:id, :cwid, :name, \'pending\')'
    );
    foreach ($rows as $r) {
        $insert->execute([':id' => $r['id'], ':cwid' => $r['connectwise_id'], ':name' => $r['name']]);
    }

    $total = count($rows);
    $pdo->prepare('INSERT OR REPLACE INTO cw_sync_meta (key, value) VALUES (\'billing_started_at\', :v)')
        ->execute([':v' => date('c')]);
    $pdo->prepare('INSERT OR REPLACE INTO cw_sync_meta (key, value) VALUES (\'billing_total_queued\', :v)')
        ->execute([':v' => (string) $total]);

    return ['total' => $total];
}

/**
 * Processes up to $batchSize pending queue rows: pulls each customer's
 * live 6-month Agreement-invoice series and upserts it into
 * customer_monthly_billing (month-by-month -- a customer with real
 * invoices only in 4 of the last 6 months simply gets 4 rows written, and
 * relationships_cw_billing_stored_series() below zero-fills the rest on
 * read, same as the live path already did). One customer's failure is
 * caught and recorded on its own queue row rather than aborting the batch.
 */
function relationships_cw_billing_sync_step(PDO $pdo, int $batchSize = 20): array
{
    $pending = $pdo->prepare('SELECT * FROM cw_billing_sync_queue WHERE status = \'pending\' ORDER BY customer_id LIMIT :n');
    $pending->bindValue(':n', $batchSize, PDO::PARAM_INT);
    $pending->execute();
    $rows = $pending->fetchAll(PDO::FETCH_ASSOC);

    $upsertMonth = $pdo->prepare(
        'INSERT INTO customer_monthly_billing (customer_id, month, total, synced_at)
         VALUES (:cid, :month, :total, datetime(\'now\'))
         ON CONFLICT(customer_id, month) DO UPDATE SET total = excluded.total, synced_at = excluded.synced_at'
    );
    $upsertYear = $pdo->prepare(
        'INSERT INTO customer_yearly_billing (customer_id, year, total, synced_at)
         VALUES (:cid, :year, :total, datetime(\'now\'))
         ON CONFLICT(customer_id, year) DO UPDATE SET total = excluded.total, synced_at = excluded.synced_at'
    );
    $deleteYears = $pdo->prepare('DELETE FROM customer_yearly_billing WHERE customer_id = :cid');
    $setCadence = $pdo->prepare('UPDATE customers SET billing_cadence = :cadence WHERE id = :cid');
    $setRecentBilling = $pdo->prepare('UPDATE customers SET has_recent_billing = :v WHERE id = :cid');
    $markDone = $pdo->prepare('UPDATE cw_billing_sync_queue SET status = \'done\', processed_at = datetime(\'now\'), error_message = NULL WHERE customer_id = :id');
    $markError = $pdo->prepare('UPDATE cw_billing_sync_queue SET status = \'error\', processed_at = datetime(\'now\'), error_message = :msg WHERE customer_id = :id');

    $processed = 0;
    $errors = [];

    // Batched -- added 2026-09-27, see RELATIONSHIPS_CW_SYNC_BATCH_SIZE's
    // comment in connectwise.php: one ConnectWise call now covers up to
    // RELATIONSHIPS_CW_SYNC_BATCH_SIZE customers instead of one call per
    // customer (previously the dominant cost of this sync stage at this
    // integration's current customer volume). A whole chunk's ConnectWise
    // call failing (network error, wrong condition, etc.) marks every
    // customer in that chunk 'error' rather than just one -- a wider
    // blast radius than before, but each is still individually retryable
    // via the existing "Retry Failed" button, and the alternative
    // (silently succeeding with no data) is worse -- see
    // relationships_cw_group_batch_rows() in connectwise.php.
    foreach (array_chunk($rows, RELATIONSHIPS_CW_SYNC_BATCH_SIZE) as $chunk) {
        $cwIds = array_map(static fn (array $r): string => (string) $r['connectwise_id'], $chunk);
        try {
            $billingByCompany = relationships_cw_activity_monthly_billing_batch($cwIds);
        } catch (Throwable $e) {
            foreach ($chunk as $row) {
                $markError->execute([':id' => (int) $row['customer_id'], ':msg' => substr($e->getMessage(), 0, 500)]);
                $errors[] = ['customer_id' => (int) $row['customer_id'], 'company_name' => $row['company_name'], 'error' => $e->getMessage()];
                $processed++;
            }
            continue;
        }

        // Annual-cadence detection (2026-09-15, per Michael -- see
        // claude/relationships-annual-billing-cadence.md), batched too:
        // only the customers THIS chunk found at $0 for the trailing 6
        // months get a second, wider 3-year lookup, and that lookup is
        // itself one batched call for all of them, not one call each.
        $zeroMonthlyIds = [];
        foreach ($chunk as $row) {
            $cwId = (string) $row['connectwise_id'];
            $billing = $billingByCompany[$cwId] ?? ['series' => [], 'trend' => ['direction' => 'flat', 'percent' => null]];
            $monthlyTotal = array_sum(array_column($billing['series'], 'total'));
            if ($monthlyTotal <= 0.0) {
                $zeroMonthlyIds[] = $cwId;
            }
        }
        $yearlyByCompany = $zeroMonthlyIds !== [] ? relationships_cw_activity_yearly_billing_batch($zeroMonthlyIds, 3) : [];

        foreach ($chunk as $row) {
            $customerId = (int) $row['customer_id'];
            $cwId = (string) $row['connectwise_id'];
            try {
                $billing = $billingByCompany[$cwId] ?? ['series' => [], 'trend' => ['direction' => 'flat', 'percent' => null]];
                foreach ($billing['series'] as $point) {
                    $upsertMonth->execute([':cid' => $customerId, ':month' => $point['month'], ':total' => $point['total']]);
                }

                $monthlyTotal = array_sum(array_column($billing['series'], 'total'));
                $cadence = 'monthly';
                if ($monthlyTotal <= 0.0 && isset($yearlyByCompany[$cwId])) {
                    $yearly = $yearlyByCompany[$cwId];
                    $yearlyTotal = array_sum(array_column($yearly['series'], 'total'));
                    if ($yearlyTotal > 0.0) {
                        $cadence = 'annual';
                        $deleteYears->execute([':cid' => $customerId]);
                        foreach ($yearly['series'] as $point) {
                            $upsertYear->execute([':cid' => $customerId, ':year' => $point['year'], ':total' => $point['total']]);
                        }
                    }
                }
                if ($cadence === 'monthly') {
                    // Recomputed from scratch every run (same reasoning as
                    // is_peoplefirst/is_prospect_only in db.php) -- a
                    // customer that no longer qualifies as annual loses
                    // stale yearly rows too, rather than being stuck
                    // showing an old 3-year chart forever.
                    $deleteYears->execute([':cid' => $customerId]);
                }
                $setCadence->execute([':cadence' => $cadence, ':cid' => $customerId]);

                // See has_recent_billing's comment above $setRecentBilling's
                // declaration for exactly what this approximates and why.
                $hasRecentBilling = $monthlyTotal > 0.0 || $cadence === 'annual';
                $setRecentBilling->execute([':v' => $hasRecentBilling ? 1 : 0, ':cid' => $customerId]);

                $markDone->execute([':id' => $customerId]);
            } catch (Throwable $e) {
                $markError->execute([':id' => $customerId, ':msg' => substr($e->getMessage(), 0, 500)]);
                $errors[] = ['customer_id' => $customerId, 'company_name' => $row['company_name'], 'error' => $e->getMessage()];
            }
            $processed++;
        }
    }

    $counts = $pdo->query('SELECT status, COUNT(*) AS n FROM cw_billing_sync_queue GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
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

/**
 * Reads back the stored billing series for one customer, for
 * activity.php's summary action. Always includes a `mode` key
 * ('monthly' | 'annual') alongside the usual { series, trend } shape, so
 * the frontend knows which chart to render -- see billing_cadence's
 * doc comment in db.php and connectwise-billing-sync-core.php's
 * cadence-detection step above.
 *
 * 'monthly' (the default, and every customer before 2026-09-15): exactly
 * $months entries oldest -> newest, zero-filled for any month with no
 * synced row (a genuinely $0 month and "never synced" both read as 0
 * here -- callers that need to tell those apart should check
 * relationships_cw_billing_last_synced_at() too, which is null only for
 * the latter). Trend uses the exact same recent-3-vs-prior-3 math as the
 * live path (shared helper in connectwise-activity.php), so switching
 * from live to synced never changes what the trend badge means.
 *
 * 'annual': exactly $years entries oldest -> newest from
 * customer_yearly_billing instead, same zero-fill/trend-sharing idea via
 * relationships_cw_activity_yearly_billing_series_from_totals().
 */
function relationships_cw_billing_stored_series(PDO $pdo, int $customerId, int $months = 6, int $years = 3): array
{
    $cadenceStmt = $pdo->prepare('SELECT billing_cadence FROM customers WHERE id = :id');
    $cadenceStmt->execute([':id' => $customerId]);
    $cadence = (string) ($cadenceStmt->fetchColumn() ?: 'monthly');

    if ($cadence === 'annual') {
        $stmt = $pdo->prepare('SELECT year, total FROM customer_yearly_billing WHERE customer_id = :id');
        $stmt->execute([':id' => $customerId]);
        $byYear = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        return ['mode' => 'annual'] + relationships_cw_activity_yearly_billing_series_from_totals($byYear, $years);
    }

    $stmt = $pdo->prepare('SELECT month, total FROM customer_monthly_billing WHERE customer_id = :id');
    $stmt->execute([':id' => $customerId]);
    $byMonth = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    return ['mode' => 'monthly'] + relationships_cw_activity_billing_series_from_totals($byMonth, $months);
}

/**
 * Most recent synced_at across this customer's stored billing rows, or
 * null if a billing sync has never covered this customer (a brand new
 * customer since the last sync run, or the billing sync has simply never
 * been run at all). The UI uses null to show "not yet synced" instead of
 * a chart that looks like a real, confirmed $0.
 */
function relationships_cw_billing_last_synced_at(PDO $pdo, int $customerId): ?string
{
    $stmt = $pdo->prepare('SELECT MAX(synced_at) FROM customer_monthly_billing WHERE customer_id = :id');
    $stmt->execute([':id' => $customerId]);
    $value = $stmt->fetchColumn();
    return ($value === false || $value === null) ? null : (string) $value;
}
