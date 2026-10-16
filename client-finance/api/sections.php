<?php
/**
 * client-finance/api/sections.php
 *
 * Read-only data for the 5 dashboard sections -- everything here reads
 * ONLY from client-finance.sqlite (already populated by sync-core.php via
 * sync.php's start/step). No ConnectWise calls happen in this file; if a
 * customer hasn't been synced yet every action here just returns empty
 * arrays, which the frontend already knows how to show as "Not yet
 * synced" (see api/dashboard.php?action=summary).
 *
 * GET ?action=standard&cw_company_id=N
 *   -> { months: [{month, total}], invoices: [{id, invoice_number,
 *        invoice_date, total}] }  -- 6-month T&M trend + the list behind it.
 * GET ?action=product_sales&cw_company_id=N
 *   -> { years: [{year, total}], invoices: [{..., lines: [...]}] } --
 *      YoY dollars (one bar per calendar year present) + line-level
 *      drill-down per Sales-Order-sourced invoice.
 * GET ?action=agreements&cw_company_id=N
 *   -> { agreements: [{..., months: [...], additions: [...]}] } -- every
 *      agreement, its own invoice trend, and addition-level detail.
 * GET ?action=credits&cw_company_id=N
 *   -> { months: [...], invoices: [{..., lines: [...]}] } -- same shape
 *      as product_sales; `lines` is empty for a credit memo that wasn't
 *      tied to specific catalog items (see sync-core.php's header).
 * GET ?action=opportunities&cw_company_id=N
 *   -> { opportunities: [...], open_count: N } -- the Opportunity scan
 *      results, newest-sold-first... actually oldest-sold-first (the
 *      ones furthest overdue for a refresh conversation lead).
 */

declare(strict_types=1);

require_once __DIR__ . '/_util.php';

client_finance_require_access();
$pdo = client_finance_db();
$action = $_GET['action'] ?? '';
$cwCompanyId = (int) ($_GET['cw_company_id'] ?? 0);
if ($cwCompanyId <= 0) {
    client_finance_respond(400, ['ok' => false, 'error' => 'Missing cw_company_id.']);
}

/** invoice_lines of kind='product' for a set of invoice ids, grouped by invoice id. */
function client_finance_lines_for_invoices(PDO $pdo, array $invoiceIds): array
{
    $invoiceIds = array_values(array_unique(array_filter(array_map('intval', $invoiceIds))));
    if ($invoiceIds === []) {
        return [];
    }
    $stmt = $pdo->prepare(
        'SELECT invoice_id, description, product_identifier, qty, unit_cost, unit_price, cost, price,
                catalog_category, catalog_subcategory, sales_order_identifier, opportunity_name
         FROM invoice_lines WHERE kind = \'product\' AND invoice_id IN (' . implode(',', $invoiceIds) . ')
         ORDER BY id'
    );
    $stmt->execute();
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $l) {
        $out[(int) $l['invoice_id']][] = [
            'description' => (string) $l['description'],
            'product_identifier' => (string) $l['product_identifier'],
            'qty' => (float) $l['qty'],
            'unit_cost' => (float) $l['unit_cost'],
            'unit_price' => (float) $l['unit_price'],
            'cost' => (float) $l['cost'],
            'price' => (float) $l['price'],
            'catalog_category' => $l['catalog_category'],
            'catalog_subcategory' => $l['catalog_subcategory'],
            'sales_order_identifier' => (string) $l['sales_order_identifier'],
            'opportunity_name' => (string) $l['opportunity_name'],
        ];
    }
    return $out;
}

if ($action === 'standard') {
    $months = $pdo->prepare(
        "SELECT month, SUM(total) AS total FROM invoices WHERE cw_company_id = :c AND category = 'standard' GROUP BY month ORDER BY month"
    );
    $months->execute([':c' => $cwCompanyId]);
    $invoices = $pdo->prepare(
        "SELECT id, invoice_number, invoice_date, total FROM invoices WHERE cw_company_id = :c AND category = 'standard' ORDER BY invoice_date DESC"
    );
    $invoices->execute([':c' => $cwCompanyId]);
    client_finance_respond(200, [
        'ok' => true,
        'months' => $months->fetchAll(PDO::FETCH_ASSOC),
        'invoices' => $invoices->fetchAll(PDO::FETCH_ASSOC),
    ]);
}

if ($action === 'product_sales') {
    $years = $pdo->prepare(
        "SELECT substr(month, 1, 4) AS year, SUM(total) AS total FROM invoices
         WHERE cw_company_id = :c AND category = 'sales_order' GROUP BY year ORDER BY year"
    );
    $years->execute([':c' => $cwCompanyId]);
    $invStmt = $pdo->prepare(
        "SELECT id, invoice_number, invoice_date, total FROM invoices WHERE cw_company_id = :c AND category = 'sales_order' ORDER BY invoice_date DESC"
    );
    $invStmt->execute([':c' => $cwCompanyId]);
    $invoices = $invStmt->fetchAll(PDO::FETCH_ASSOC);
    $lines = client_finance_lines_for_invoices($pdo, array_column($invoices, 'id'));
    foreach ($invoices as &$inv) {
        $inv['lines'] = $lines[(int) $inv['id']] ?? [];
    }
    unset($inv);
    client_finance_respond(200, ['ok' => true, 'years' => $years->fetchAll(PDO::FETCH_ASSOC), 'invoices' => $invoices]);
}

if ($action === 'agreements') {
    $agStmt = $pdo->prepare(
        'SELECT id, name, type_name, status, start_date, cancelled_date FROM agreements WHERE cw_company_id = :c ORDER BY status = \'Cancelled\' ASC, name'
    );
    $agStmt->execute([':c' => $cwCompanyId]);
    $agreements = $agStmt->fetchAll(PDO::FETCH_ASSOC);

    $monthsStmt = $pdo->prepare("SELECT month, SUM(total) AS total FROM invoices WHERE agreement_id = :a GROUP BY month ORDER BY month");
    $addStmt = $pdo->prepare(
        'SELECT product_identifier, description, qty, unit_cost, unit_price, start_date, cancelled_date
         FROM agreement_additions WHERE agreement_id = :a ORDER BY (cancelled_date IS NOT NULL), start_date DESC'
    );
    foreach ($agreements as &$ag) {
        $monthsStmt->execute([':a' => $ag['id']]);
        $ag['months'] = $monthsStmt->fetchAll(PDO::FETCH_ASSOC);
        $addStmt->execute([':a' => $ag['id']]);
        $ag['additions'] = $addStmt->fetchAll(PDO::FETCH_ASSOC);
    }
    unset($ag);
    client_finance_respond(200, ['ok' => true, 'agreements' => $agreements]);
}

if ($action === 'credits') {
    $months = $pdo->prepare(
        "SELECT month, SUM(total) AS total FROM invoices WHERE cw_company_id = :c AND category = 'credit' GROUP BY month ORDER BY month"
    );
    $months->execute([':c' => $cwCompanyId]);
    $invStmt = $pdo->prepare(
        "SELECT id, invoice_number, invoice_date, total FROM invoices WHERE cw_company_id = :c AND category = 'credit' ORDER BY invoice_date DESC"
    );
    $invStmt->execute([':c' => $cwCompanyId]);
    $invoices = $invStmt->fetchAll(PDO::FETCH_ASSOC);
    $lines = client_finance_lines_for_invoices($pdo, array_column($invoices, 'id'));
    foreach ($invoices as &$inv) {
        $inv['lines'] = $lines[(int) $inv['id']] ?? [];
    }
    unset($inv);
    client_finance_respond(200, ['ok' => true, 'months' => $months->fetchAll(PDO::FETCH_ASSOC), 'invoices' => $invoices]);
}

if ($action === 'opportunities') {
    $stmt = $pdo->prepare(
        "SELECT o.id, o.category, o.subcategory, o.match_confidence, o.sold_date, o.years_ago, o.status,
                o.relationships_todo_id, il.description, il.product_identifier, il.price,
                i.invoice_number, i.invoice_date
         FROM opportunities o
         JOIN invoice_lines il ON il.id = o.invoice_line_id
         JOIN invoices i ON i.id = il.invoice_id
         WHERE o.cw_company_id = :c
         ORDER BY o.sold_date ASC"
    );
    $stmt->execute([':c' => $cwCompanyId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $openCount = 0;
    foreach ($rows as $r) {
        if ($r['status'] === 'open') {
            $openCount++;
        }
    }
    client_finance_respond(200, ['ok' => true, 'opportunities' => $rows, 'open_count' => $openCount]);
}

client_finance_respond(400, ['ok' => false, 'error' => 'Unknown action.']);
