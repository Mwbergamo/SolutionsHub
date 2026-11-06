<?php
/**
 * client-finance/api/gauges.php
 *
 * Portfolio-wide "gauge" visualizations for the Client Finance front
 * page (added 2026-10-21, per Michael: "I want to build gauges one at a
 * time, in charts, graphs, numeric summary and others but each will be
 * on the front page of Client Finance under customer search" -- see
 * claude/client-finance-gauges.md for the full decision trail, in
 * particular why this required reversing client-finance's original
 * on-demand-only sync design (see db.php's header) in favor of a new
 * nightly bulk sync across the whole customer book --
 * client-finance-cron.php, added alongside this file).
 *
 * Every gauge here reads ONLY from client-finance.sqlite, already
 * populated by the nightly cron (or by whatever customers have been
 * individually opened/synced on demand in the meantime -- the two write
 * to the same tables). None of these make a live ConnectWise call, so
 * they stay fast even while scanning the whole portfolio.
 *
 * Because this app's sync is still fundamentally per-customer rather
 * than a single all-customers snapshot, every gauge response includes
 * `coverage`: how many of CodeBlue's real (ConnectWise-linked,
 * non-prospect) customers have ever been synced here, vs. the full
 * eligible count -- so the UI can caveat "N of M customers synced"
 * instead of presenting a partial scan as if it were the whole book.
 *
 * Rebuilt 2026-11-06 for Michael's 3-gauge overhaul (client-finance-
 * gauges.md): each gauge now also has a *_detail action returning the
 * sortable (total desc), line-expandable drill-down the front end pops
 * over when the card is clicked.
 *
 * GET ?action=recurring_revenue (was ?action=mrr before 2026-11-06)
 *   -> { ok: true, recurring_revenue: { month, total, customers_with_revenue,
 *        coverage: {synced, eligible}, bulk_sync: {started_at, finished_at} } }
 *
 *   "Agreement/recurring revenue" -- the portfolio's total Agreement-
 *   category invoiced dollars for the most recently fully-completed
 *   calendar month. This is real invoiced revenue, NOT a computed
 *   (addition unit price x billing frequency) estimate -- no sync in
 *   this codebase has ever captured a ConnectWise agreement addition's
 *   billing frequency, so there is no confirmed field to compute a
 *   theoretical MRR from. See claude/client-finance-gauges.md's "MRR
 *   definition" section for the one known caveat this choice carries: a
 *   customer billed quarterly or annually will show as a one-month
 *   spike in whatever month that invoice lands, not smoothed across the
 *   period it actually covers -- this mirrors the raw ConnectWise
 *   invoice data as-is, nothing is being smoothed or assumed on top of
 *   it. Flag to Michael if a smoothed definition is what he actually
 *   wants instead.
 *
 * GET ?action=recurring_revenue_detail
 *   -> { ok: true, month, invoices: [{id, invoice_number, invoice_date,
 *        total, cw_company_id, company_name, agreement_name, lines}] }
 *
 * GET ?action=annual_revenue
 *   -> { ok: true, annual_revenue: { year, total, invoice_count,
 *        coverage: {synced, eligible}, bulk_sync: {...} } }
 *
 *   All invoice types (Standard, Agreement, Sales-Order-sourced,
 *   Credit), calendar year to date, NET of credits -- Michael's explicit
 *   answer (2026-11-06): credit memos reduce this total. See
 *   client-finance-gauges.md for why credit totals are subtracted via
 *   -ABS() here rather than trusting ConnectWise's own sign on
 *   CreditMemo invoices (never confirmed against real data either way).
 *   Relies on sync-core.php's client_finance_ytd_safe_cutoff() so
 *   Standard/Credits reliably reach back to January all year round.
 *
 * GET ?action=annual_revenue_detail
 *   -> { ok: true, invoices: [{id, invoice_number, invoice_date, total
 *        (signed -- negative for a credit), category, cw_company_id,
 *        company_name, lines}] }
 *
 * GET ?action=sales_order_revenue
 *   -> { ok: true, sales_order_revenue: { year, total, order_count,
 *        coverage: {synced, eligible}, bulk_sync: {...} } }
 *
 *   Sum of real ConnectWise Sales Order entities' own `total` (the new
 *   `sales_orders` table/sync, sync-core.php's
 *   client_finance_sync_sales_orders(), 2026-11-06) for orders dated in
 *   the current calendar year -- the time window Michael chose
 *   explicitly (year-to-date, matching Annual Revenue) when asked, since
 *   his original spec didn't name one. NOT the same as
 *   invoices.category='sales_order' -- a single order's invoices can
 *   span more than one invoice row (probe findings doc #3), so this is
 *   the order's own total/date, not a re-derivation from invoice data.
 *
 * GET ?action=sales_order_revenue_detail
 *   -> { ok: true, orders: [{id, order_date, total, status, po_number,
 *        cw_company_id, company_name, lines}] }
 */

declare(strict_types=1);

require_once __DIR__ . '/_util.php';
require_once __DIR__ . '/../../relationships/api/db.php';

client_finance_require_access();
$pdo = client_finance_db();
$action = $_GET['action'] ?? '';

/** Real, ConnectWise-linked, non-prospect customers -- shared with client-finance-cron.php and bulk-sync.php via _util.php's client_finance_eligible_customers(). */
function client_finance_eligible_customer_count(): int
{
    return count(client_finance_eligible_customers());
}

/** Company id -> name, for a batch of ConnectWise company ids (cross-db lookup into relationships.sqlite). */
function client_finance_company_names(array $cwCompanyIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $cwCompanyIds))));
    if ($ids === []) {
        return [];
    }
    $rpdo = relationships_db();
    $stmt = $rpdo->query(
        'SELECT connectwise_id, name FROM customers WHERE connectwise_id IN (' . implode(',', $ids) . ')'
    );
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[(int) $r['connectwise_id']] = (string) $r['name'];
    }
    return $out;
}

/** bulk_sync_started_at / bulk_sync_finished_at, written by client-finance-cron.php each run. */
function client_finance_bulk_sync_state(PDO $pdo): array
{
    $stmt = $pdo->prepare('SELECT value FROM sync_state WHERE key = :k');
    $get = static function (string $k) use ($stmt): ?string {
        $stmt->execute([':k' => $k]);
        $v = $stmt->fetchColumn();
        return $v === false ? null : (string) $v;
    };
    return ['started_at' => $get('bulk_sync_started_at'), 'finished_at' => $get('bulk_sync_finished_at')];
}

if ($action === 'recurring_revenue') {
    // Strictly before the current month, so a month that's only
    // partially elapsed never shows as an artificially low number.
    $cutoffMonth = gmdate('Y-m');
    $stmt = $pdo->prepare(
        "SELECT month, SUM(total) AS total, COUNT(DISTINCT cw_company_id) AS customers
         FROM invoices WHERE category = 'agreement' AND month < :cutoff
         GROUP BY month ORDER BY month DESC LIMIT 1"
    );
    $stmt->execute([':cutoff' => $cutoffMonth]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $synced = (int) $pdo->query('SELECT COUNT(*) FROM company_sync_state WHERE agreements_synced_at IS NOT NULL')->fetchColumn();

    client_finance_respond(200, ['ok' => true, 'recurring_revenue' => [
        'month' => $row['month'] ?? null,
        'total' => $row ? (float) $row['total'] : 0.0,
        'customers_with_revenue' => $row ? (int) $row['customers'] : 0,
        'coverage' => ['synced' => $synced, 'eligible' => client_finance_eligible_customer_count()],
        'bulk_sync' => client_finance_bulk_sync_state($pdo),
    ]]);
}

/**
 * Drill-down for Recurring Revenue (added 2026-11-06, Michael's gauges
 * overhaul -- client-finance-gauges.md): every Agreement-category
 * invoice from that same most-recently-completed month, across the
 * whole portfolio, sortable by total desc, each expandable to its own
 * product-line detail (real data when an agreement invoice happens to
 * carry a catalog line -- see sync-core.php's sync_agreements() 2026-11-06
 * extension -- "no line items" otherwise, which is the honest answer for
 * a pure recurring-service invoice).
 */
if ($action === 'recurring_revenue_detail') {
    $cutoffMonth = gmdate('Y-m');
    $monthRow = $pdo->query(
        "SELECT month FROM invoices WHERE category = 'agreement' AND month < '$cutoffMonth' ORDER BY month DESC LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);
    $month = $monthRow['month'] ?? null;
    if ($month === null) {
        client_finance_respond(200, ['ok' => true, 'month' => null, 'invoices' => []]);
    }

    $stmt = $pdo->prepare(
        "SELECT i.id, i.invoice_number, i.invoice_date, i.total, i.cw_company_id, ag.name AS agreement_name
         FROM invoices i LEFT JOIN agreements ag ON ag.id = i.agreement_id
         WHERE i.category = 'agreement' AND i.month = :m
         ORDER BY i.total DESC"
    );
    $stmt->execute([':m' => $month]);
    $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $names = client_finance_company_names(array_column($invoices, 'cw_company_id'));
    $lines = client_finance_lines_for_invoices($pdo, array_column($invoices, 'id'));
    foreach ($invoices as &$inv) {
        $inv['company_name'] = $names[(int) $inv['cw_company_id']] ?? ('Company #' . $inv['cw_company_id']);
        $inv['lines'] = $lines[(int) $inv['id']] ?? [];
    }
    unset($inv);
    client_finance_respond(200, ['ok' => true, 'month' => $month, 'invoices' => $invoices]);
}

/**
 * Annual Revenue -- all invoice types (Standard, Agreement, Sales-Order-
 * sourced, Credit), calendar year to date, net of credits (Michael,
 * 2026-11-06: "Include credits (net revenue)" -- credit totals are
 * explicitly subtracted below via -ABS() rather than trusting
 * ConnectWise's own sign on CreditMemo invoices, which was never
 * confirmed one way or the other against real data -- see
 * client-finance-gauges.md). Relies on sync-core.php's
 * client_finance_ytd_safe_cutoff() (2026-11-06) so Standard/Credits
 * reliably reach back to January even late in the year.
 */
if ($action === 'annual_revenue') {
    $yearStart = gmdate('Y') . '-01';
    $stmt = $pdo->prepare(
        "SELECT SUM(CASE WHEN category = 'credit' THEN -ABS(total) ELSE total END) AS total, COUNT(*) AS invoice_count
         FROM invoices WHERE category IN ('standard','agreement','sales_order','credit') AND month >= :start"
    );
    $stmt->execute([':start' => $yearStart]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $synced = (int) $pdo->query(
        'SELECT COUNT(*) FROM company_sync_state
         WHERE standard_synced_at IS NOT NULL AND agreements_synced_at IS NOT NULL
           AND credits_synced_at IS NOT NULL AND product_sales_synced_at IS NOT NULL'
    )->fetchColumn();

    client_finance_respond(200, ['ok' => true, 'annual_revenue' => [
        'year' => gmdate('Y'),
        'total' => $row ? (float) $row['total'] : 0.0,
        'invoice_count' => $row ? (int) $row['invoice_count'] : 0,
        'coverage' => ['synced' => $synced, 'eligible' => client_finance_eligible_customer_count()],
        'bulk_sync' => client_finance_bulk_sync_state($pdo),
    ]]);
}

if ($action === 'annual_revenue_detail') {
    $yearStart = gmdate('Y') . '-01';
    $stmt = $pdo->prepare(
        "SELECT id, invoice_number, invoice_date, total, category, cw_company_id
         FROM invoices WHERE category IN ('standard','agreement','sales_order','credit') AND month >= :start
         ORDER BY ABS(total) DESC"
    );
    $stmt->execute([':start' => $yearStart]);
    $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $names = client_finance_company_names(array_column($invoices, 'cw_company_id'));
    $lines = client_finance_lines_for_invoices($pdo, array_column($invoices, 'id'));
    foreach ($invoices as &$inv) {
        // Signed so a credit sorts/reads as a negative, matching the
        // gauge's own net-of-credits total above.
        $inv['total'] = $inv['category'] === 'credit' ? -abs((float) $inv['total']) : (float) $inv['total'];
        $inv['company_name'] = $names[(int) $inv['cw_company_id']] ?? ('Company #' . $inv['cw_company_id']);
        $inv['lines'] = $lines[(int) $inv['id']] ?? [];
    }
    unset($inv);
    // Re-sort by the now-signed total, high to low, same ordering rule
    // as every other gauge's drill-down.
    usort($invoices, static fn($a, $b) => $b['total'] <=> $a['total']);
    client_finance_respond(200, ['ok' => true, 'invoices' => $invoices]);
}

/**
 * Sales Order Revenue -- real ConnectWise Sales Order entities (the new
 * `sales_orders` table, client_finance_sync_sales_orders(), 2026-11-06),
 * NOT a re-derivation of invoices.category='sales_order' -- a single
 * order's invoiceIds can span more than one invoice (probe findings
 * doc #3), so this is the order's own total and order date, not a sum
 * over whichever invoices happened to bill it. Window: calendar
 * year-to-date, per Michael's explicit answer (client-finance-gauges.md).
 */
if ($action === 'sales_order_revenue') {
    $yearStart = gmdate('Y') . '-01';
    $stmt = $pdo->prepare(
        'SELECT SUM(total) AS total, COUNT(*) AS order_count FROM sales_orders WHERE month >= :start'
    );
    $stmt->execute([':start' => $yearStart]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $synced = (int) $pdo->query('SELECT COUNT(*) FROM company_sync_state WHERE sales_orders_synced_at IS NOT NULL')->fetchColumn();

    client_finance_respond(200, ['ok' => true, 'sales_order_revenue' => [
        'year' => gmdate('Y'),
        'total' => $row ? (float) $row['total'] : 0.0,
        'order_count' => $row ? (int) $row['order_count'] : 0,
        'coverage' => ['synced' => $synced, 'eligible' => client_finance_eligible_customer_count()],
        'bulk_sync' => client_finance_bulk_sync_state($pdo),
    ]]);
}

if ($action === 'sales_order_revenue_detail') {
    $yearStart = gmdate('Y') . '-01';
    $stmt = $pdo->prepare(
        'SELECT id, order_date, total, status, po_number, cw_company_id FROM sales_orders
         WHERE month >= :start ORDER BY total DESC'
    );
    $stmt->execute([':start' => $yearStart]);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $names = client_finance_company_names(array_column($orders, 'cw_company_id'));
    $lines = client_finance_lines_for_sales_orders($pdo, array_column($orders, 'id'));
    foreach ($orders as &$o) {
        $o['company_name'] = $names[(int) $o['cw_company_id']] ?? ('Company #' . $o['cw_company_id']);
        $o['lines'] = $lines[(int) $o['id']] ?? [];
    }
    unset($o);
    client_finance_respond(200, ['ok' => true, 'orders' => $orders]);
}

client_finance_respond(400, ['ok' => false, 'error' => 'Unknown action.']);
