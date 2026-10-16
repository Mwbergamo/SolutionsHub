<?php
/**
 * client-finance/api/sync.php
 *
 * POST ?action=start   body { cw_company_id: N, company_name?: string }
 *        Wipes this company's previously-synced data and queues all 5
 *        sections (standard / product_sales / agreements / credits /
 *        opportunities) fresh. Call once when a rep opens a customer's
 *        dashboard (or hits "Refresh").
 * POST ?action=step    body {} -- processes the next pending section
 *        (any company; the queue is small and short-lived so this never
 *        needs to be scoped). Call this repeatedly from the frontend
 *        until `done` comes back true -- each call is one ConnectWise
 *        round-trip's worth of work, kept small on purpose so a single
 *        slow company can't run into Bluehost's execution-time limit.
 * GET  ?action=status&cw_company_id=N
 *        Per-section synced_at + last_error for this company, same shape
 *        dashboard.php?action=summary already returns, plus how many
 *        sections are still queued.
 */

declare(strict_types=1);

require_once __DIR__ . '/_util.php';
require_once __DIR__ . '/sync-core.php';

client_finance_require_access();
$pdo = client_finance_db();
$action = $_GET['action'] ?? '';

function client_finance_company_status(PDO $pdo, int $cwCompanyId): array
{
    $stmt = $pdo->prepare(
        'SELECT standard_synced_at, product_sales_synced_at, agreements_synced_at,
                credits_synced_at, opportunities_synced_at, last_error
         FROM company_sync_state WHERE cw_company_id = :id'
    );
    $stmt->execute([':id' => $cwCompanyId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [
        'standard_synced_at' => null, 'product_sales_synced_at' => null,
        'agreements_synced_at' => null, 'credits_synced_at' => null,
        'opportunities_synced_at' => null, 'last_error' => null,
    ];
    $pending = $pdo->prepare("SELECT COUNT(*) FROM sync_queue WHERE cw_company_id = :id AND status = 'pending'");
    $pending->execute([':id' => $cwCompanyId]);
    $row['pending_sections'] = (int) $pending->fetchColumn();
    return $row;
}

try {
    if ($action === 'status') {
        $cwCompanyId = (int) ($_GET['cw_company_id'] ?? 0);
        if ($cwCompanyId <= 0) {
            client_finance_respond(400, ['ok' => false, 'error' => 'Missing cw_company_id.']);
        }
        client_finance_respond(200, ['ok' => true, 'sync' => client_finance_company_status($pdo, $cwCompanyId)]);
    }

    client_finance_require_post();
    $body = client_finance_read_json_body();

    if ($action === 'start') {
        $cwCompanyId = (int) ($body['cw_company_id'] ?? 0);
        if ($cwCompanyId <= 0) {
            client_finance_respond(400, ['ok' => false, 'error' => 'Missing cw_company_id.']);
        }
        $result = client_finance_sync_start($pdo, $cwCompanyId, (string) ($body['company_name'] ?? ''));
        client_finance_respond(200, ['ok' => true] + $result + ['sync' => client_finance_company_status($pdo, $cwCompanyId)]);
    }

    if ($action === 'step') {
        $result = client_finance_sync_step($pdo);
        client_finance_respond(200, ['ok' => true] + $result);
    }
} catch (RelationshipsConnectWiseError $e) {
    client_finance_respond(502, ['ok' => false, 'error' => 'ConnectWise: ' . $e->getMessage()]);
} catch (Throwable $e) {
    client_finance_respond(500, ['ok' => false, 'error' => $e->getMessage()]);
}

client_finance_respond(400, ['ok' => false, 'error' => 'Unknown action.']);
