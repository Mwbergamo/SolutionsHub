<?php
/**
 * commissions/api/sync.php
 *
 * POST ?action=start      body { months_back?: 1-24, force?: bool }
 *        Lists ConnectWise invoices and queues the ones needing work.
 *        months_back 1 (default) = last month + this month (+ any open
 *        invoice from the last 180 days). Use 12-24 once, on first deploy,
 *        to backfill history for the trend reports. force = also redo
 *        invoices in locked (frozen) months.
 * POST ?action=step       processes the next small batch -- call until done
 * GET  ?action=status     progress + last finished time
 * POST ?action=recompute  re-applies current rates/labor cost/territory map
 *                         to every UNLOCKED month (no ConnectWise calls)
 * GET  ?action=probe&invoice_id=N[&path=/finance/...]
 *        Diagnostic: what ConnectWise really returns for one invoice
 *        (raw invoice, products, time entries, agreement additions). Use it
 *        if an invoice shows "no lines" / "mismatch" to find the wrong
 *        assumption.
 */

declare(strict_types=1);

require_once __DIR__ . '/_util.php';
require_once __DIR__ . '/sync-core.php';

$user = commissions_require_access();
$pdo = commissions_db();
commissions_run_pending_migration($pdo);
$action = $_GET['action'] ?? '';

try {
    if ($action === 'status') {
        commissions_respond(200, ['ok' => true] + commissions_sync_status($pdo));
    }

    if ($action === 'probe') {
        $id = (int) ($_GET['invoice_id'] ?? 0);
        if ($id <= 0) {
            commissions_respond(400, ['ok' => false, 'error' => 'invoice_id required.']);
        }
        $out = ['ok' => true, 'invoice_id' => $id];
        $trim = static function ($v) { return json_decode((string) json_encode($v), true); };
        foreach ([
            'invoice' => static fn () => relationships_cw_request("/finance/invoices/$id"),
            'products' => static fn () => commissions_cw_list_all('/procurement/products', "invoice/id=$id"),
            'products_batched_in' => static fn () => commissions_cw_list_all('/procurement/products', "invoice/id in ($id)"),
            'time_entries' => static fn () => commissions_cw_list_all('/time/entries', "invoice/id=$id"),
            'expense_entries' => static fn () => commissions_cw_list_all('/expense/entries', "invoice/id=$id"),
        ] as $key => $fn) {
            try {
                $out[$key] = $trim($fn());
            } catch (Throwable $e) {
                $out[$key] = ['error' => $e->getMessage()];
            }
        }
        // Free-form look-around: &path=/finance/invoices/123/... (GET only, read-only areas).
        $path = (string) ($_GET['path'] ?? '');
        if ($path !== '' && preg_match('#^/(finance|procurement|time|expense|service|company)/[A-Za-z0-9/_\-]*$#', $path) === 1) {
            try {
                $out['path_result'] = $trim(relationships_cw_request($path, ['pageSize' => '25']));
            } catch (Throwable $e) {
                $out['path_result'] = ['error' => $e->getMessage()];
            }
        }
        if (is_array($out['invoice'] ?? null) && !empty($out['invoice']['applyToId']) && stripos((string) ($out['invoice']['applyToType'] ?? ''), 'agreement') !== false) {
            try {
                $out['agreement_additions'] = commissions_agreement_lookup($pdo, (int) $out['invoice']['applyToId']);
            } catch (Throwable $e) {
                $out['agreement_additions'] = ['error' => $e->getMessage()];
            }
        }
        $json = json_encode($out, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);
        header('Content-Type: application/json; charset=utf-8');
        echo strlen((string) $json) > 400000 ? substr((string) $json, 0, 400000) : $json;
        exit;
    }

    commissions_require_post();
    $body = commissions_read_json_body();

    if ($action === 'start') {
        $result = commissions_sync_start($pdo, (int) ($body['months_back'] ?? 1), !empty($body['force']));
        commissions_respond(200, ['ok' => true] + $result);
    }
    if ($action === 'step') {
        $result = commissions_sync_step($pdo, (int) ($body['batch_size'] ?? COMMISSIONS_STEP_BATCH));
        commissions_respond(200, ['ok' => true] + $result + ['status' => commissions_sync_status($pdo)]);
    }
    if ($action === 'recompute') {
        $n = commissions_recompute($pdo);
        commissions_respond(200, ['ok' => true, 'lines_recomputed' => $n]);
    }
} catch (RelationshipsConnectWiseError $e) {
    commissions_respond(502, ['ok' => false, 'error' => 'ConnectWise: ' . $e->getMessage()]);
} catch (Throwable $e) {
    commissions_respond(500, ['ok' => false, 'error' => $e->getMessage()]);
}

commissions_respond(400, ['ok' => false, 'error' => 'Unknown action.']);
