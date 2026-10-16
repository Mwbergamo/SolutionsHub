#!/usr/bin/env python3
path = "relationships/api/dashboard.php"
with open(path, "r", encoding="utf-8") as f:
    src = f.read()
orig = src

old = """    $gauges = [
        ['key' => 'total_customers', 'label' => 'Active Clients', 'value' => $totalCustomers, 'format' => 'count'],
        ['key' => 'total_prospects', 'label' => 'Total Prospects', 'value' => $totalProspects, 'format' => 'count'],
        ['key' => 'total_residential', 'label' => 'Total Residential', 'value' => $totalResidential, 'format' => 'count'],
        ['key' => 'portfolio_billing_trend', 'label' => 'Portfolio Billing Trend', 'value' => null, 'format' => 'trend', 'trend' => $portfolioBillingTrend],
        ['key' => 'outgrow_stale', 'label' => '60+ Days Since Last OutGrow Touch', 'value' => $outgrowStaleCount, 'format' => 'count'],
    ];"""
assert src.count(old) == 1, "gauges anchor count=%d" % src.count(old)

new = """    // Global To-Do gauge (overdue / due today / future) -- added 2026-10-16
    // per Michael: a front-page tile (three clickable numbers in one card,
    // not just one value like the gauges above) showing the company-wide
    // to-do picture across BOTH to-do sources this app has -- general_todos
    // (the per-customer "To-Do List" card, 2026-10-15) and meeting_tasks
    // (meeting-sourced to-dos) -- same two sources todo_summary/todo_list
    // already merge per customer. An undated to-do counts as not-yet-due,
    // same rule as the per-customer badge (AskUserQuestion 2026-10-15), so
    // it folds into "future" here rather than a 4th bucket Michael didn't
    // ask for. Same territory scoping as every other cross-customer figure
    // on this page, built fresh here (alias 'c', not 'customers') because
    // these two tables don't use the `customers` alias the rest of this
    // query does.
    $todoTerritoryFilter = $allowedTerritories === null
        ? ['sql' => '', 'params' => []]
        : relationships_territory_filter_sql($allowedTerritories, 'c');
    $todayEasternStr = $todayEastern->format('Y-m-d');
    $todoOverdue = 0;
    $todoDueToday = 0;
    $todoFuture = 0;
    foreach ([
        ['table' => 'general_todos', 'extra' => 'AND t.removed_at IS NULL'],
        ['table' => 'meeting_tasks', 'extra' => ''],
    ] as $todoSrc) {
        $todoCountStmt = $pdo->prepare(
            "SELECT
                SUM(CASE WHEN t.due_date IS NOT NULL AND t.due_date < :today1 THEN 1 ELSE 0 END) AS overdue,
                SUM(CASE WHEN t.due_date = :today2 THEN 1 ELSE 0 END) AS due_today,
                SUM(CASE WHEN t.due_date IS NULL OR t.due_date > :today3 THEN 1 ELSE 0 END) AS future
             FROM {$todoSrc['table']} t
             JOIN customers c ON c.id = t.customer_id
             WHERE t.completed_at IS NULL {$todoSrc['extra']} {$todoTerritoryFilter['sql']}"
        );
        $todoCountStmt->execute(array_merge(
            [':today1' => $todayEasternStr, ':today2' => $todayEasternStr, ':today3' => $todayEasternStr],
            $todoTerritoryFilter['params']
        ));
        $todoCountRow = $todoCountStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $todoOverdue += (int) ($todoCountRow['overdue'] ?? 0);
        $todoDueToday += (int) ($todoCountRow['due_today'] ?? 0);
        $todoFuture += (int) ($todoCountRow['future'] ?? 0);
    }

    $gauges = [
        ['key' => 'total_customers', 'label' => 'Active Clients', 'value' => $totalCustomers, 'format' => 'count'],
        ['key' => 'total_prospects', 'label' => 'Total Prospects', 'value' => $totalProspects, 'format' => 'count'],
        ['key' => 'total_residential', 'label' => 'Total Residential', 'value' => $totalResidential, 'format' => 'count'],
        ['key' => 'portfolio_billing_trend', 'label' => 'Portfolio Billing Trend', 'value' => null, 'format' => 'trend', 'trend' => $portfolioBillingTrend],
        ['key' => 'outgrow_stale', 'label' => '60+ Days Since Last OutGrow Touch', 'value' => $outgrowStaleCount, 'format' => 'count'],
        ['key' => 'todo_overview', 'label' => 'To-Do List', 'format' => 'todo3', 'overdue' => $todoOverdue, 'due_today' => $todoDueToday, 'future' => $todoFuture],
    ];"""
assert src.count(old) == 1
src = src.replace(old, new, 1)

assert src != orig
with open(path, "w", encoding="utf-8") as f:
    f.write(src)
print("dashboard.php updated: todo_overview gauge added")
