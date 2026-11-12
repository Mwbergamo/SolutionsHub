<?php
/**
 * client-finance/api/gauges-core.php
 *
 * Pure revenue-aggregation logic behind gauges.php's three portfolio
 * gauges (Recurring/Annual/Sales Order Revenue), extracted 2026-11-12 so
 * a second caller -- the new cross-app "My Day" personal dashboard
 * (myday/api/digest.php) -- can compute the exact same numbers scoped to
 * a subset of companies (one rep's own territory) without a second,
 * drift-prone copy of this SQL. gauges.php's own three gauge actions now
 * call these with $companyIds = null (unchanged behavior, whole
 * portfolio); myday passes the ConnectWise company ids for one rep's
 * allowed territories instead.
 *
 * Every function here does exactly what gauges.php's inline queries used
 * to do -- same categories, same time windows, same credit-memo sign
 * handling -- just with an optional company-id restriction layered on
 * top. See gauges.php's own docblock for what each number means and why
 * (Michael's 2026-11-06 answers on credits/time windows).
 */

declare(strict_types=1);

/**
 * Builds " AND <column> IN (...)" plus its bound params, or '' when
 * $companyIds is null (no filter -- the whole portfolio). A non-null but
 * EMPTY array is a real "this rep's territory has zero companies with
 * financial data" result, not "no filter" -- so it resolves to a clause
 * that is always false (id = -1) rather than an invalid empty IN() or,
 * worse, silently matching everything.
 */
function client_finance_company_id_filter(?array $companyIds, string $column = 'cw_company_id'): array
{
    if ($companyIds === null) {
        return ['sql' => '', 'params' => []];
    }
    $ids = array_values(array_unique(array_map('intval', $companyIds)));
    if ($ids === []) {
        return ['sql' => " AND {$column} = -1", 'params' => []];
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    return ['sql' => " AND {$column} IN ({$placeholders})", 'params' => $ids];
}

/** Recurring Revenue: most recently fully-completed month's Agreement-category invoice total. */
function client_finance_recurring_revenue_core(PDO $pdo, ?array $companyIds = null): array
{
    $cutoffMonth = gmdate('Y-m');
    $filter = client_finance_company_id_filter($companyIds);
    $stmt = $pdo->prepare(
        "SELECT month, SUM(total) AS total, COUNT(DISTINCT cw_company_id) AS customers
         FROM invoices WHERE category = 'agreement' AND month < ?{$filter['sql']}
         GROUP BY month ORDER BY month DESC LIMIT 1"
    );
    $stmt->execute(array_merge([$cutoffMonth], $filter['params']));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return [
        'month' => $row['month'] ?? null,
        'total' => $row ? (float) $row['total'] : 0.0,
        'customers_with_revenue' => $row ? (int) $row['customers'] : 0,
    ];
}

/** Annual Revenue: all invoice types, calendar year to date, net of credits. */
function client_finance_annual_revenue_core(PDO $pdo, ?array $companyIds = null): array
{
    $yearStart = gmdate('Y') . '-01';
    $filter = client_finance_company_id_filter($companyIds);
    $stmt = $pdo->prepare(
        "SELECT SUM(CASE WHEN category = 'credit' THEN -ABS(total) ELSE total END) AS total, COUNT(*) AS invoice_count
         FROM invoices WHERE category IN ('standard','agreement','sales_order','credit') AND month >= ?{$filter['sql']}"
    );
    $stmt->execute(array_merge([$yearStart], $filter['params']));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return [
        'year' => gmdate('Y'),
        'total' => $row ? (float) $row['total'] : 0.0,
        'invoice_count' => $row ? (int) $row['invoice_count'] : 0,
    ];
}

/** Sales Order Revenue: real ConnectWise Sales Order entities, calendar year to date. */
function client_finance_sales_order_revenue_core(PDO $pdo, ?array $companyIds = null): array
{
    $yearStart = gmdate('Y') . '-01';
    $filter = client_finance_company_id_filter($companyIds);
    $stmt = $pdo->prepare(
        "SELECT SUM(total) AS total, COUNT(*) AS order_count FROM sales_orders WHERE month >= ?{$filter['sql']}"
    );
    $stmt->execute(array_merge([$yearStart], $filter['params']));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return [
        'year' => gmdate('Y'),
        'total' => $row ? (float) $row['total'] : 0.0,
        'order_count' => $row ? (int) $row['order_count'] : 0,
    ];
}
