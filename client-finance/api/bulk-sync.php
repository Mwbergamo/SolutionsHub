<?php
/**
 * client-finance/api/bulk-sync.php
 *
 * Browser-triggerable portfolio-wide sync -- added 2026-11-06 per
 * Michael: "The sync for finance data ... should be in Client Finance.
 * That should be able to be run by anyone with access to that card"
 * (see claude/client-finance-gauges.md). Until now the only way to
 * refresh the whole book was client-finance-cron.php, which is CLI-only
 * and depends on a cPanel cron actually being scheduled -- if that was
 * never set up, the portfolio gauges just show "nightly sync not yet
 * run" forever. This endpoint reuses the exact same
 * client_finance_sync_start()/client_finance_sync_step() functions and
 * the same shared eligibility query (client_finance_eligible_customers(),
 * _util.php) so the two paths can never drift apart, and both write to
 * the same tables/queue/sync_state keys -- whichever ran most recently
 * for a given company wins, same as the on-demand single-customer sync
 * already coexists with the nightly cron.
 *
 * "Anyone with access to that card" is enforced the same way every other
 * client-finance endpoint enforces it: client_finance_require_access(),
 * the same 4-person allow-list (auth/client-finance-access.php). This is
 * NOT open to anyone who can reach the URL.
 *
 * Same KNOWN COST as client-finance-cron.php (see that file's header):
 * every eligible company gets a full wipe + re-sync of all 6 sections,
 * so a full run is several thousand ConnectWise API calls across the
 * whole book. Driven in small step() calls from the browser (same
 * pattern sync.php already uses for a single customer) so no single
 * HTTP request risks Bluehost's execution-time limit.
 *
 * GET  ?action=status
 *   -> { ok: true, bulk_sync: {started_at, finished_at},
 *        pending_sections: N, eligible: N }
 * POST ?action=start   body {}
 *   -> queues every eligible company fresh (full wipe + re-sync, same as
 *      the nightly cron) and records bulk_sync_started_at.
 *      -> { ok: true, queued_companies: N, failed_to_queue: [...] }
 * POST ?action=step    body {}
 *   -> processes the next pending section (any company). Call this
 *      repeatedly from the frontend until `done` comes back true; each
 *      call is one ConnectWise round-trip's worth of work. Records
 *      bulk_sync_finished_at the moment the queue empties.
 *   -> { ok: true, done, remaining, section, cw_company_id, error? }
 */

declare(strict_types=1);

require_once __DIR__ . '/_util.php';
require_once __DIR__ . '/sync-core.php';

client_finance_require_access();
$pdo = client_finance_db();
$action = $_GET['action'] ?? '';

function client_finance_bulk_sync_status(PDO $pdo): array
{
    $stmt = $pdo->prepare('SELECT value FROM sync_state WHERE key = :k');
    $get = static function (string $k) use ($stmt): ?string {
        $stmt->execute([':k' => $k]);
        $v = $stmt->fetchColumn();
        return $v === false ? null : (string) $v;
    };
    $pending = (int) $pdo->query("SELECT COUNT(*) FROM sync_queue WHERE status = 'pending'")->fetchColumn();
    return [
        'bulk_sync' => ['started_at' => $get('bulk_sync_started_at'), 'finished_at' => $get('bulk_sync_finished_at')],
        'pending_sections' => $pending,
        'eligible' => count(client_finance_eligible_customers()),
    ];
}

try {
    if ($action === 'status') {
        client_finance_respond(200, ['ok' => true] + client_finance_bulk_sync_status($pdo));
    }

    client_finance_require_post();

    if ($action === 'start') {
        $pdo->prepare("INSERT OR REPLACE INTO sync_state (key, value) VALUES ('bulk_sync_started_at', :v)")
            ->execute([':v' => gmdate('c')]);
        $pdo->prepare("DELETE FROM sync_state WHERE key = 'bulk_sync_finished_at'")->execute();

        $queued = 0;
        $failed = [];
        foreach (client_finance_eligible_customers() as $c) {
            $cwCompanyId = (int) $c['connectwise_id'];
            if ($cwCompanyId <= 0) {
                continue;
            }
            try {
                client_finance_sync_start($pdo, $cwCompanyId, (string) $c['name']);
                $queued++;
            } catch (Throwable $e) {
                $failed[] = ['company_name' => (string) $c['name'], 'error' => $e->getMessage()];
            }
        }
        client_finance_respond(200, ['ok' => true, 'queued_companies' => $queued, 'failed_to_queue' => $failed]);
    }

    if ($action === 'step') {
        $result = client_finance_sync_step($pdo);
        if ($result['done']) {
            $pdo->prepare("INSERT OR REPLACE INTO sync_state (key, value) VALUES ('bulk_sync_finished_at', :v)")
                ->execute([':v' => gmdate('c')]);
        }
        client_finance_respond(200, ['ok' => true] + $result);
    }
} catch (RelationshipsConnectWiseError $e) {
    client_finance_respond(502, ['ok' => false, 'error' => 'ConnectWise: ' . $e->getMessage()]);
} catch (Throwable $e) {
    client_finance_respond(500, ['ok' => false, 'error' => $e->getMessage()]);
}

client_finance_respond(400, ['ok' => false, 'error' => 'Unknown action.']);
