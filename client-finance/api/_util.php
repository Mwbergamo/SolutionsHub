<?php
/**
 * client-finance/api/_util.php
 *
 * Shared by every client-finance/api/*.php endpoint, same shape as
 * commissions/api/_util.php: JSON responses and the access check. EVERY
 * endpoint calls client_finance_require_access(), which responds 401 when
 * nobody is signed in and 403 for anyone not on the allow-list (currently
 * the same four people as Commissions -- see
 * auth/client-finance-access.php). Hiding the Finance-page card is
 * cosmetic; this is what actually keeps customer financials private.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../../auth/session.php';
require_once __DIR__ . '/../../auth/client-finance-access.php';

/**
 * Real, ConnectWise-linked, non-prospect customers -- the one eligibility
 * filter every bulk-ish operation in this app shares (gauges.php's
 * coverage count, client-finance-cron.php's nightly queue, and
 * bulk-sync.php's on-demand queue, added 2026-11-06). Centralized here
 * so the three can never drift out of sync with each other.
 *
 * @return array<int,array{connectwise_id:string,name:string}>
 */
function client_finance_eligible_customers(): array
{
    require_once __DIR__ . '/../../relationships/api/db.php';
    $rpdo = relationships_db();
    return $rpdo->query(
        "SELECT connectwise_id, name FROM customers
         WHERE connectwise_id IS NOT NULL AND TRIM(connectwise_id) <> '' AND connectwise_id GLOB '[0-9]*'
           AND COALESCE(is_duplicate_hidden, 0) = 0 AND COALESCE(is_prospect_only, 0) = 0
         ORDER BY name"
    )->fetchAll(PDO::FETCH_ASSOC);
}

function client_finance_respond(int $httpCode, array $payload): never
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    http_response_code($httpCode);
    echo json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

function client_finance_read_json_body(int $maxBytes = 262144): array
{
    $raw = file_get_contents('php://input', false, null, 0, $maxBytes);
    $data = json_decode((string) $raw, true);
    return is_array($data) ? $data : [];
}

/**
 * Signed-in AND allow-listed, or the request ends here. The PHP session is
 * released immediately so a long sync step doesn't block the user's other
 * requests (PHP file sessions are exclusive-locked) -- same reasoning as
 * commissions_require_access().
 *
 * @return array{name: string, email: string}
 */
function client_finance_require_access(): array
{
    $user = auth_current_user();
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    if ($user === null) {
        client_finance_respond(401, ['ok' => false, 'error' => 'Not signed in.']);
    }
    if (!client_finance_email_allowed($user['email'] ?? '')) {
        client_finance_respond(403, ['ok' => false, 'error' => 'You do not have access to Client Finance.']);
    }
    return ['name' => (string) $user['name'], 'email' => (string) $user['email']];
}

/** Same-site check for state-changing requests (defense in depth, like commissions_require_post()). */
function client_finance_require_post(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        client_finance_respond(405, ['ok' => false, 'error' => 'Method not allowed.']);
    }
    $host = $_SERVER['HTTP_HOST'] ?? '';
    foreach (['HTTP_ORIGIN', 'HTTP_REFERER'] as $h) {
        $v = $_SERVER[$h] ?? '';
        if ($v !== '') {
            $vh = parse_url($v, PHP_URL_HOST);
            $hh = parse_url('//' . $host, PHP_URL_HOST);
            if (!is_string($vh) || !is_string($hh) || strcasecmp($vh, $hh) !== 0) {
                client_finance_respond(403, ['ok' => false, 'error' => 'Cross-site request refused.']);
            }
            break;
        }
    }
}

/**
 * invoice_lines of kind='product' for a set of invoice ids, grouped by
 * invoice id. Moved here 2026-11-06 (was sections.php-only) so
 * gauges.php's Annual Revenue/Recurring Revenue drill-downs can reuse
 * the exact same query instead of a second copy.
 */
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

/**
 * invoice_lines of kind='product' for a set of SALES ORDER ids (not
 * invoice ids), grouped by sales_order_id. Added 2026-11-06 for the
 * Sales Order Revenue gauge's drill-down -- an order's product lines
 * were already captured (with sales_order_id populated) by whichever
 * invoice-level sync first saw them, so this needs no new ConnectWise
 * call, just a different grouping key on data that's already there.
 */
function client_finance_lines_for_sales_orders(PDO $pdo, array $salesOrderIds): array
{
    $salesOrderIds = array_values(array_unique(array_filter(array_map('intval', $salesOrderIds))));
    if ($salesOrderIds === []) {
        return [];
    }
    $stmt = $pdo->prepare(
        'SELECT sales_order_id, description, product_identifier, qty, unit_cost, unit_price, cost, price,
                catalog_category, catalog_subcategory, opportunity_name
         FROM invoice_lines WHERE kind = \'product\' AND sales_order_id IN (' . implode(',', $salesOrderIds) . ')
         ORDER BY id'
    );
    $stmt->execute();
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $l) {
        $out[(int) $l['sales_order_id']][] = [
            'description' => (string) $l['description'],
            'product_identifier' => (string) $l['product_identifier'],
            'qty' => (float) $l['qty'],
            'unit_cost' => (float) $l['unit_cost'],
            'unit_price' => (float) $l['unit_price'],
            'cost' => (float) $l['cost'],
            'price' => (float) $l['price'],
            'catalog_category' => $l['catalog_category'],
            'catalog_subcategory' => $l['catalog_subcategory'],
            'opportunity_name' => (string) $l['opportunity_name'],
        ];
    }
    return $out;
}
