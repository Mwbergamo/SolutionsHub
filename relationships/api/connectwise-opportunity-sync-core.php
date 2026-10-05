<?php
/**
 * relationships/api/connectwise-opportunity-sync-core.php
 *
 * "Customer-Experience ticket issue" sync -- added 2026-10-05, the one
 * genuinely new piece of Michael's "routine recommendation agent" request
 * (see claude/relationships-account-opportunity-ranking.md for the full
 * design writeup). The other three signals that feed the Account
 * Opportunity/Risk ranking -- change in agreement revenue, change in
 * ticket volume, change in active contacts -- already exist as derived,
 * request-time math over customer_monthly_billing/
 * customer_ticket_count_history/customer_contact_count_history (see
 * dashboard.php's overview action); nothing new to sync for those.
 *
 * This sync answers one question per customer: of their Service Tickets
 * in the trailing 90 days, how many needed more than one scheduled
 * dispatch? Michael's own words: "One of the leading indicators of
 * trouble are how many scheduled events are in the single case. If
 * dispatch has to assign the ticket multiple times, it is likely due to
 * repeat visits or the engineer is missing the appointment." Two
 * ConnectWise calls per customer (ticket list, then schedule entries for
 * those tickets) -- same queue-based start()/step() shape as every other
 * sync in this app, for the same Bluehost execution-time reason, batched
 * from day one (RELATIONSHIPS_CW_SYNC_BATCH_SIZE) given this integration's
 * hard-won lesson about per-customer round trips at ~1,800+ customers (see
 * claude/relationships-connectwise-sync.md's "Batched Contacts/Ticket
 * History/Billing sync calls" section).
 *
 * UNVERIFIED, same standing limitation as every new ConnectWise
 * endpoint/field this integration has ever added (no network path to
 * connect.codebluetechnology.com from the build environment) -- see
 * connectwise-activity.php's relationships_cw_activity_schedule_entry_counts_batch()
 * file comment for exactly what's assumed and how it degrades if wrong.
 * Needs a real "Run Sync Now" plus a spot-check against a ticket Michael
 * already knows was dispatched more than once.
 */

declare(strict_types=1);

require_once __DIR__ . '/connectwise-activity.php';

/** How many days back to look for "recent" tickets -- see the design doc for why 90 was chosen over 12 months/open-only. */
const RELATIONSHIPS_CW_OPPORTUNITY_LOOKBACK_DAYS = 90;

/**
 * Rebuilds the opportunity sync queue: every real (non-mock),
 * ConnectWise-linked customer -- same "available" rule as every other
 * per-customer sync in this app.
 */
function relationships_cw_opportunity_sync_start(PDO $pdo): array
{
    $pdo->exec('DELETE FROM cw_opportunity_sync_queue');

    $rows = $pdo->query(
        "SELECT id, connectwise_id, name FROM customers
         WHERE is_mock = 0 AND connectwise_id IS NOT NULL AND connectwise_id != ''
           AND connectwise_id NOT LIKE 'MOCK-%'"
    )->fetchAll(PDO::FETCH_ASSOC);

    $insert = $pdo->prepare(
        'INSERT INTO cw_opportunity_sync_queue (customer_id, connectwise_id, company_name, status)
         VALUES (:id, :cwid, :name, \'pending\')'
    );
    foreach ($rows as $r) {
        $insert->execute([':id' => $r['id'], ':cwid' => $r['connectwise_id'], ':name' => $r['name']]);
    }

    $total = count($rows);
    $pdo->prepare('INSERT OR REPLACE INTO cw_sync_meta (key, value) VALUES (\'opportunity_started_at\', :v)')
        ->execute([':v' => date('c')]);

    return ['total' => $total];
}

/**
 * Processes up to $batchSize pending queue rows, two ConnectWise calls per
 * chunk of RELATIONSHIPS_CW_SYNC_BATCH_SIZE customers: first their recent
 * ticket ids (relationships_cw_activity_recent_tickets_batch()), then
 * dispatch counts for all of those ticket ids
 * (relationships_cw_activity_schedule_entry_counts_batch(), itself
 * sub-chunked to RELATIONSHIPS_CW_SYNC_BATCH_SIZE ticket ids per call,
 * since one customer chunk's tickets can easily add up to more than that).
 *
 * Failure handling is two-tiered, same spirit as every other batched sync
 * in this app (a wrong guess should show up as a loud, retryable error,
 * never a silent wrong zero):
 *   - If the ticket-list call for a customer chunk fails, every customer
 *     in that chunk is marked 'error' (retryable) -- nothing to count yet.
 *   - If a schedule-entries sub-chunk fails, only the customers who
 *     actually own one of THAT sub-chunk's tickets are marked 'error';
 *     customers whose tickets all live in successfully-fetched sub-chunks
 *     still get marked 'done' with real counts this run.
 */
function relationships_cw_opportunity_sync_step(PDO $pdo, int $batchSize = 20): array
{
    $pending = $pdo->prepare('SELECT * FROM cw_opportunity_sync_queue WHERE status = \'pending\' ORDER BY customer_id LIMIT :n');
    $pending->bindValue(':n', $batchSize, PDO::PARAM_INT);
    $pending->execute();
    $rows = $pending->fetchAll(PDO::FETCH_ASSOC);

    $deleteTickets = $pdo->prepare('DELETE FROM ticket_dispatch_counts WHERE customer_id = :id');
    $insertTicket = $pdo->prepare(
        'INSERT OR REPLACE INTO ticket_dispatch_counts
            (cw_ticket_id, customer_id, ticket_summary, date_entered, schedule_entry_count, is_cx_issue, synced_at)
         VALUES (:tid, :cid, :summary, :entered, :count, :is_issue, datetime(\'now\'))'
    );
    $updateCustomer = $pdo->prepare(
        'UPDATE customers SET cx_issue_ticket_count_90d = :n, cx_issue_synced_at = datetime(\'now\') WHERE id = :id'
    );
    $markDone = $pdo->prepare('UPDATE cw_opportunity_sync_queue SET status = \'done\', processed_at = datetime(\'now\'), error_message = NULL WHERE customer_id = :id');
    $markError = $pdo->prepare('UPDATE cw_opportunity_sync_queue SET status = \'error\', processed_at = datetime(\'now\'), error_message = :msg WHERE customer_id = :id');

    $processed = 0;
    $errors = [];

    foreach (array_chunk($rows, RELATIONSHIPS_CW_SYNC_BATCH_SIZE) as $chunk) {
        $cwIdByCustomer = []; // customer_id => cwId, for this chunk
        $cwIds = [];
        foreach ($chunk as $row) {
            $cwIdByCustomer[(int) $row['customer_id']] = (string) $row['connectwise_id'];
            $cwIds[] = (string) $row['connectwise_id'];
        }

        try {
            $ticketsByCompany = relationships_cw_activity_recent_tickets_batch($cwIds, RELATIONSHIPS_CW_OPPORTUNITY_LOOKBACK_DAYS);
        } catch (Throwable $e) {
            foreach ($chunk as $row) {
                $markError->execute([':id' => (int) $row['customer_id'], ':msg' => substr($e->getMessage(), 0, 500)]);
                $errors[] = ['customer_id' => (int) $row['customer_id'], 'company_name' => $row['company_name'], 'error' => $e->getMessage()];
                $processed++;
            }
            continue;
        }

        // Flatten every ticket in this chunk, remembering which customer
        // (by cwId) owns it, so a failed schedule-entries sub-chunk can be
        // attributed back to the right customers below.
        $ticketOwnerCwId = []; // ticket id (string) => cwId
        $allTicketIds = [];
        foreach ($ticketsByCompany as $cwId => $tickets) {
            foreach ($tickets as $t) {
                $tid = (string) $t['id'];
                $ticketOwnerCwId[$tid] = $cwId;
                $allTicketIds[] = $tid;
            }
        }

        $scheduleCounts = []; // ticket id (string) => dispatch count
        $failedCwIds = []; // cwId => true, schedule-entries fetch failed for at least one of their tickets
        foreach (array_chunk($allTicketIds, RELATIONSHIPS_CW_SYNC_BATCH_SIZE) as $ticketSubChunk) {
            try {
                $counts = relationships_cw_activity_schedule_entry_counts_batch($ticketSubChunk);
                foreach ($counts as $tid => $n) {
                    $scheduleCounts[$tid] = $n;
                }
            } catch (Throwable $e) {
                foreach ($ticketSubChunk as $tid) {
                    $failedCwIds[$ticketOwnerCwId[$tid] ?? ''] = $e->getMessage();
                }
            }
        }

        foreach ($chunk as $row) {
            $customerId = (int) $row['customer_id'];
            $cwId = $cwIdByCustomer[$customerId];
            if (isset($failedCwIds[$cwId])) {
                $markError->execute([':id' => $customerId, ':msg' => substr((string) $failedCwIds[$cwId], 0, 500)]);
                $errors[] = ['customer_id' => $customerId, 'company_name' => $row['company_name'], 'error' => $failedCwIds[$cwId]];
                $processed++;
                continue;
            }

            try {
                $deleteTickets->execute([':id' => $customerId]);
                $cxCount = 0;
                foreach ($ticketsByCompany[$cwId] ?? [] as $t) {
                    $scheduleCount = $scheduleCounts[(string) $t['id']] ?? 0;
                    $isCxIssue = $scheduleCount > 1 ? 1 : 0;
                    if ($isCxIssue) {
                        $cxCount++;
                    }
                    $insertTicket->execute([
                        ':tid' => $t['id'],
                        ':cid' => $customerId,
                        ':summary' => $t['summary'],
                        ':entered' => $t['date_entered'] !== '' ? $t['date_entered'] : null,
                        ':count' => $scheduleCount,
                        ':is_issue' => $isCxIssue,
                    ]);
                }
                $updateCustomer->execute([':n' => $cxCount, ':id' => $customerId]);
                $markDone->execute([':id' => $customerId]);
            } catch (Throwable $e) {
                $markError->execute([':id' => $customerId, ':msg' => substr($e->getMessage(), 0, 500)]);
                $errors[] = ['customer_id' => $customerId, 'company_name' => $row['company_name'], 'error' => $e->getMessage()];
            }
            $processed++;
        }
    }

    $counts = $pdo->query('SELECT status, COUNT(*) AS n FROM cw_opportunity_sync_queue GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
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
