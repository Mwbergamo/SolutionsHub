<?php
/**
 * client-finance/api/dashboard.php
 *
 * The per-customer dashboard shell: resolves which customer was picked
 * and reports what's synced so far for each of the five sections (Standard
 * Invoices, Product Sales, Agreement Invoicing, Credits, Opportunities).
 * The actual sync (ConnectWise -> client-finance.sqlite) lives in
 * sync-core.php, triggered from the frontend via sync.php, not here --
 * this endpoint only ever reads company_sync_state, never writes to it
 * except to seed the row with today's company name on first open.
 *
 * GET ?action=summary&cw_company_id=N
 *   -> { ok: true, company: { cw_company_id, name, is_peoplefirst,
 *                              is_prospect_only, is_residential },
 *        sync: { standard_synced_at, product_sales_synced_at,
 *                agreements_synced_at, credits_synced_at,
 *                opportunities_synced_at, last_error } }
 *
 * Resolves the company's name fresh from relationships/ every time
 * (rather than trusting a possibly-stale company_sync_state.company_name)
 * so a customer renamed in ConnectWise/relationships since this app last
 * opened their dashboard never shows a stale name.
 */

declare(strict_types=1);

require_once __DIR__ . '/_util.php';
require_once __DIR__ . '/../../relationships/api/db.php';

client_finance_require_access();
$pdo = client_finance_db();

$action = $_GET['action'] ?? '';

if ($action === 'summary') {
    $cwCompanyId = (int) ($_GET['cw_company_id'] ?? 0);
    if ($cwCompanyId <= 0) {
        client_finance_respond(400, ['ok' => false, 'error' => 'Missing cw_company_id.']);
    }

    $rpdo = relationships_db();
    $custStmt = $rpdo->prepare(
        "SELECT name, is_peoplefirst, is_prospect_only, is_residential
         FROM customers WHERE connectwise_id = :cw LIMIT 1"
    );
    $custStmt->execute([':cw' => (string) $cwCompanyId]);
    $cust = $custStmt->fetch(PDO::FETCH_ASSOC);
    if ($cust === false) {
        client_finance_respond(404, ['ok' => false, 'error' => 'That customer was not found.']);
    }

    $upsert = $pdo->prepare(
        'INSERT INTO company_sync_state (cw_company_id, company_name)
         VALUES (:id, :name)
         ON CONFLICT(cw_company_id) DO UPDATE SET company_name = :name2'
    );
    $upsert->execute([':id' => $cwCompanyId, ':name' => $cust['name'], ':name2' => $cust['name']]);

    $stateStmt = $pdo->prepare(
        'SELECT standard_synced_at, product_sales_synced_at, agreements_synced_at,
                credits_synced_at, opportunities_synced_at, last_error
         FROM company_sync_state WHERE cw_company_id = :id'
    );
    $stateStmt->execute([':id' => $cwCompanyId]);
    $sync = $stateStmt->fetch(PDO::FETCH_ASSOC) ?: [
        'standard_synced_at' => null, 'product_sales_synced_at' => null,
        'agreements_synced_at' => null, 'credits_synced_at' => null,
        'opportunities_synced_at' => null, 'last_error' => null,
    ];

    client_finance_respond(200, ['ok' => true, 'company' => [
        'cw_company_id' => $cwCompanyId,
        'name' => $cust['name'],
        'is_peoplefirst' => (bool) $cust['is_peoplefirst'],
        'is_prospect_only' => (bool) $cust['is_prospect_only'],
        'is_residential' => (bool) $cust['is_residential'],
    ], 'sync' => $sync]);
}

client_finance_respond(400, ['ok' => false, 'error' => 'Unknown action.']);
