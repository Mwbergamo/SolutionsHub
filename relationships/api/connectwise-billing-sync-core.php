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

    $upsert = $pdo->prepare(
        'INSERT INTO customer_monthly_billing (customer_id, month, total, synced_at)
         VALUES (:cid, :month, :total, datetime(\'now\'))
         ON CONFLICT(customer_id, month) DO UPDATE SET total = excluded.total, synced_at = excluded.synced_at'
    );

    $processed = 0;
    $errors = [];
    foreach ($rows as $row) {
        $customerId = (int) $row['customer_id'];
        try {
            $billing = relationships_cw_activity_monthly_billing((string) $row['connectwise_id']);
            foreach ($billing['series'] as $point) {
                $upsert->execute([':cid' => $customerId, ':month' => $point['month'], ':total' => $point['total']]);
            }
            $pdo->prepare('UPDATE cw_billing_sync_queue SET status = \'done\', processed_at = datetime(\'now\'), error_message = NULL WHERE customer_id = :id')
                ->execute([':id' => $customerId]);
        } catch (Throwable $e) {
            $pdo->prepare('UPDATE cw_billing_sync_queue SET status = \'error\', processed_at = datetime(\'now\'), error_message = :msg WHERE customer_id = :id')
                ->execute([':id' => $customerId, ':msg' => substr($e->getMessage(), 0, 500)]);
            $errors[] = ['customer_id' => $customerId, 'company_name' => $row['company_name'], 'error' => $e->getMessage()];
        }
        $processed++;
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
 * Reads back the stored 6-month series for one customer, for
 * activity.php's summary action. Always returns exactly $months entries
 * oldest -> newest, zero-filled for any month with no synced row (a
 * genuinely $0 month and "never synced" both read as 0 here -- callers
 * that need to tell those apart should check relationships_cw_billing_last_synced_at()
 * too, which is null only for the latter). Trend uses the exact same
 * recent-3-vs-prior-3 math as the live path (shared helper in
 * connectwise-activity.php), so switching from live to synced never
 * changes what the trend badge means.
 */
function relationships_cw_billing_stored_series(PDO $pdo, int $customerId, int $months = 6): array
{
    $stmt = $pdo->prepare('SELECT month, total FROM customer_monthly_billing WHERE customer_id = :id');
    $stmt->execute([':id' => $customerId]);
    $byMonth = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    return relationships_cw_activity_billing_series_from_totals($byMonth, $months);
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
