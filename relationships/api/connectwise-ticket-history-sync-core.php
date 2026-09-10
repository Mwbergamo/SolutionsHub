<?php
/**
 * relationships/api/connectwise-ticket-history-sync-core.php
 *
 * Nightly-synced Service Ticket volume for the front-page Primary
 * Relationship Dashboard (dashboard.php) -- added 2026-09-10 per Michael.
 * The customer's own dashboard still shows a LIVE Service Tickets YTD
 * count (connectwise-activity.php, unchanged) since that's the kind of
 * number a CRC wants as-of-right-now on a support call and it's only ever
 * one customer's worth of live query per dashboard open. The front page is
 * different: it needs a ticket count + trend for EVERY customer at once,
 * so a live ConnectWise round-trip per customer per page load doesn't
 * scale the same way Monthly Billing didn't (see
 * connectwise-billing-sync-core.php's file header for that precedent) --
 * same fix, same shape.
 *
 * Same queue-based start()/step() shape as the Agreement, Billing, and
 * Prospect syncs, for the same Bluehost execution-time reason. One list
 * call per customer (relationships_cw_activity_ticket_sync_data()) covers
 * both the YTD count and the trailing-6-month trend, reusing the exact
 * same board condition and "Professional Services" definition as the live
 * query, and the exact same recent-3-vs-prior-3 trend math as Monthly
 * Billing (relationships_cw_activity_billing_series_from_totals() -- the
 * function name says "billing" but it only ever operates on a generic
 * {month: number} map, so it's reused here unchanged for ticket counts).
 */

declare(strict_types=1);

require_once __DIR__ . '/connectwise-activity.php';

/**
 * Rebuilds the ticket-history sync queue: every real (non-mock),
 * ConnectWise-linked customer -- same "available" rule as the billing sync.
 */
function relationships_cw_ticket_history_sync_start(PDO $pdo): array
{
    $pdo->exec('DELETE FROM cw_ticket_history_sync_queue');

    $rows = $pdo->query(
        "SELECT id, connectwise_id, name FROM customers
         WHERE is_mock = 0 AND connectwise_id IS NOT NULL AND connectwise_id != ''
           AND connectwise_id NOT LIKE 'MOCK-%'"
    )->fetchAll(PDO::FETCH_ASSOC);

    $insert = $pdo->prepare(
        'INSERT INTO cw_ticket_history_sync_queue (customer_id, connectwise_id, company_name, status)
         VALUES (:id, :cwid, :name, \'pending\')'
    );
    foreach ($rows as $r) {
        $insert->execute([':id' => $r['id'], ':cwid' => $r['connectwise_id'], ':name' => $r['name']]);
    }

    $total = count($rows);
    $pdo->prepare('INSERT OR REPLACE INTO cw_sync_meta (key, value) VALUES (\'ticket_history_started_at\', :v)')
        ->execute([':v' => date('c')]);

    return ['total' => $total];
}

/**
 * Processes up to $batchSize pending queue rows: pulls each customer's YTD
 * count + trailing-6-month bucket via one list call, updates
 * customers.ticket_count_ytd, and upserts customer_ticket_count_history
 * month-by-month (a customer with tickets in only some months just gets
 * those months' rows -- relationships_cw_activity_billing_series_from_totals()
 * zero-fills the rest on read, same as billing). One customer's failure is
 * caught and recorded on its own queue row rather than aborting the batch.
 */
function relationships_cw_ticket_history_sync_step(PDO $pdo, int $batchSize = 20): array
{
    $pending = $pdo->prepare('SELECT * FROM cw_ticket_history_sync_queue WHERE status = \'pending\' ORDER BY customer_id LIMIT :n');
    $pending->bindValue(':n', $batchSize, PDO::PARAM_INT);
    $pending->execute();
    $rows = $pending->fetchAll(PDO::FETCH_ASSOC);

    $upsert = $pdo->prepare(
        'INSERT INTO customer_ticket_count_history (customer_id, month, count, synced_at)
         VALUES (:cid, :month, :count, datetime(\'now\'))
         ON CONFLICT(customer_id, month) DO UPDATE SET count = excluded.count, synced_at = excluded.synced_at'
    );
    $updateYtd = $pdo->prepare('UPDATE customers SET ticket_count_ytd = :ytd WHERE id = :id');

    $processed = 0;
    $errors = [];
    foreach ($rows as $row) {
        $customerId = (int) $row['customer_id'];
        try {
            $data = relationships_cw_activity_ticket_sync_data((string) $row['connectwise_id']);
            foreach ($data['by_month'] as $month => $count) {
                $upsert->execute([':cid' => $customerId, ':month' => $month, ':count' => $count]);
            }
            $updateYtd->execute([':ytd' => $data['ytd_count'], ':id' => $customerId]);
            $pdo->prepare('UPDATE cw_ticket_history_sync_queue SET status = \'done\', processed_at = datetime(\'now\'), error_message = NULL WHERE customer_id = :id')
                ->execute([':id' => $customerId]);
        } catch (Throwable $e) {
            $pdo->prepare('UPDATE cw_ticket_history_sync_queue SET status = \'error\', processed_at = datetime(\'now\'), error_message = :msg WHERE customer_id = :id')
                ->execute([':id' => $customerId, ':msg' => substr($e->getMessage(), 0, 500)]);
            $errors[] = ['customer_id' => $customerId, 'company_name' => $row['company_name'], 'error' => $e->getMessage()];
        }
        $processed++;
    }

    $counts = $pdo->query('SELECT status, COUNT(*) AS n FROM cw_ticket_history_sync_queue GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
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
 * Reads back this customer's trend from the stored 6-month history --
 * same shared helper (and so the same zero-fill/percent-null degeneracy)
 * as relationships_cw_billing_stored_series().
 */
function relationships_cw_ticket_history_trend(PDO $pdo, int $customerId): array
{
    $stmt = $pdo->prepare('SELECT month, count FROM customer_ticket_count_history WHERE customer_id = :id');
    $stmt->execute([':id' => $customerId]);
    $byMonth = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    return relationships_cw_activity_billing_series_from_totals($byMonth, 6)['trend'];
}
