<?php
/**
 * commissions/api/manager.php
 *
 * The sales manager's commission: 1.25% of GROSS PROFIT on every invoice in
 * the period, whoever the rep or territory (house accounts included). Private
 * to Michael Bergamo -- every other user, including the other allow-listed
 * Commissions users, gets a 403 and no data.
 *
 * GET ?action=summary -> { pending, current, last: { gp, commission, invoices, loss_lines, loss_amount, needs_review }, rate }
 * GET ?action=lines&bucket=pending|current|last|all   -> same row shape as report.php
 *      (rep = { kind: 'manager' }) so the app's report/print view is reused.
 *
 * Buckets match the dashboard: pending = not yet Closed; current / last =
 * Closed or "Closed - Emailed" invoices dated this month / last month.
 * Losing lines reduce the total (negative gross profit).
 */

declare(strict_types=1);

require_once __DIR__ . '/_util.php';
require_once __DIR__ . '/calc.php';
require_once __DIR__ . '/sync-core.php';

const COMMISSIONS_MANAGER_PCT = 1.25;

$user = commissions_require_access();
if (!commissions_is_manager($user['email'])) {
    commissions_respond(403, ['ok' => false, 'error' => 'Not available.']);
}
$pdo = commissions_db();
commissions_run_pending_migration($pdo);
$action = $_GET['action'] ?? 'summary';
$cur = commissions_month_key(0);
$last = commissions_month_key(1);
$bucketSql = "CASE WHEN i.is_closed = 0 THEN 'pending' WHEN i.month = :cur THEN 'current' WHEN i.month = :last THEN 'last' ELSE 'other' END";

if ($action === 'summary') {
    $stmt = $pdo->prepare(
        "SELECT $bucketSql AS bucket, COUNT(DISTINCT i.id) AS invoices, SUM(l.gp) AS gp, SUM(l.is_loss) AS loss_lines,
                SUM(CASE WHEN l.is_loss = 1 THEN l.gp ELSE 0 END) AS loss_amount,
                COUNT(DISTINCT CASE WHEN i.detail_state != 'ok' THEN i.id END) AS needs_review
         FROM invoices i JOIN invoice_lines l ON l.invoice_id = i.id GROUP BY bucket"
    );
    $stmt->execute([':cur' => $cur, ':last' => $last]);
    $out = [];
    foreach (['pending', 'current', 'last'] as $b) {
        $out[$b] = ['gp' => 0.0, 'commission' => 0.0, 'invoices' => 0, 'loss_lines' => 0, 'loss_amount' => 0.0, 'needs_review' => 0];
    }
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $b = (string) $r['bucket'];
        if (!isset($out[$b])) {
            continue;
        }
        $gp = round((float) $r['gp'], 2);
        $out[$b] = [
            'gp' => $gp,
            'commission' => round($gp * COMMISSIONS_MANAGER_PCT / 100, 2),
            'invoices' => (int) $r['invoices'],
            'loss_lines' => (int) $r['loss_lines'],
            'loss_amount' => round((float) $r['loss_amount'], 2),
            'needs_review' => (int) $r['needs_review'],
        ];
    }
    commissions_respond(200, ['ok' => true, 'rate' => COMMISSIONS_MANAGER_PCT] + $out + [
        'periods' => ['current' => commissions_period_label($cur), 'last' => commissions_period_label($last)],
    ]);
}

if ($action === 'lines') {
    $bucket = (string) ($_GET['bucket'] ?? '');
    $params = [];
    if ($bucket === 'pending') {
        $where = 'i.is_closed = 0';
        $title = 'Pending (invoices not yet Closed)';
        $label = 'Pending';
    } elseif ($bucket === 'current' || $bucket === 'last') {
        $m = $bucket === 'current' ? $cur : $last;
        $where = 'i.is_closed = 1 AND i.month = :m';
        $params[':m'] = $m;
        $title = ($bucket === 'current' ? 'Current month' : 'Last month') . ' — ' . commissions_period_label($m);
        $label = commissions_period_label($m);
    } elseif ($bucket === 'all') {
        $where = '(i.is_closed = 0 OR (i.is_closed = 1 AND i.month IN (:mc, :ml)))';
        $params[':mc'] = $cur;
        $params[':ml'] = $last;
        $title = 'Pending + ' . commissions_period_label($cur) . ' + ' . commissions_period_label($last);
        $label = 'Pending, Current and Last Month';
    } else {
        commissions_respond(400, ['ok' => false, 'error' => 'bucket required.']);
    }
    $stmt = $pdo->prepare(
        "SELECT i.id AS invoice_id, i.invoice_number, i.invoice_date, i.company_name, i.territory, i.status_name, i.is_closed,
                i.detail_state, i.detail_note, i.agreement_start, 0 AS rep_id, 'Sales manager' AS rep_name,
                l.id AS line_id, l.kind, l.item, l.ticket_id, l.ticket_summary, l.hours, l.qty, l.price, l.cost, l.cost_note,
                l.gp, l.over_year, l.is_loss
         FROM invoice_lines l JOIN invoices i ON i.id = l.invoice_id
         WHERE $where ORDER BY i.invoice_date, i.invoice_number, l.id"
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $totals = ['commission' => 0.0, 'revenue' => 0.0, 'cost' => 0.0, 'gp' => 0.0, 'loss_lines' => 0, 'loss_amount' => 0.0, 'invoices' => 0, 'needs_review' => 0];
    $seen = [];
    foreach ($rows as &$row) {
        foreach (['hours', 'qty', 'price', 'cost', 'gp'] as $f) {
            if ($row[$f] !== null) {
                $row[$f] = (float) $row[$f];
            }
        }
        $row['is_loss'] = (int) $row['is_loss'];
        $row['over_year'] = (int) $row['over_year'];
        $row['pct'] = COMMISSIONS_MANAGER_PCT;
        $row['commission'] = round($row['gp'] * COMMISSIONS_MANAGER_PCT / 100, 4);
        $totals['commission'] += $row['commission'];
        $totals['revenue'] += $row['price'];
        $totals['cost'] += $row['cost'];
        $totals['gp'] += $row['gp'];
        if ($row['is_loss']) {
            $totals['loss_lines']++;
            $totals['loss_amount'] += $row['gp'];
        }
        if (!isset($seen[$row['invoice_id']])) {
            $seen[$row['invoice_id']] = true;
            $totals['invoices']++;
            if ($row['detail_state'] !== 'ok') {
                $totals['needs_review']++;
            }
        }
    }
    unset($row);
    foreach ($totals as $k => $v) {
        if (is_float($v)) {
            $totals[$k] = round($v, 2);
        }
    }
    commissions_respond(200, [
        'ok' => true,
        'rep' => ['kind' => 'manager', 'id' => null, 'name' => 'Michael Bergamo — Sales Manager', 'base_pct' => COMMISSIONS_MANAGER_PCT],
        'title' => $title,
        'period_label' => $label,
        'rows' => $rows,
        'totals' => $totals,
        'late_after_lock' => 0,
        'labor_cost_per_hour' => (float) commissions_setting($pdo, 'labor_cost_per_hour', '90'),
        'generated_at' => gmdate('c'),
    ]);
}

commissions_respond(400, ['ok' => false, 'error' => 'Unknown action.']);
