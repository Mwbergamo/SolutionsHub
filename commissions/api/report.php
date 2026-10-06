<?php
/**
 * commissions/api/report.php
 *
 * GET ?action=lines&rep_id=<id|house|all>&bucket=pending|current|last
 * GET ?action=lines&rep_id=...&month=YYYY-MM          (closed invoices of that month: History)
 *      -> every commission line behind a dashboard number, with invoice,
 *         customer, territory, item, ticket summary, hours, assumed cost,
 *         price, rep %, and commission $. Lines that lost money have is_loss.
 * GET ?action=months
 *      -> months that have saved data (closed invoices), with per-rep totals
 *         and whether the month is locked (frozen).
 * GET ?action=trends&rep_id=<id>
 *      -> month-by-month (13 months), month-over-month, year-to-date vs last
 *         year, per-customer trends, and the money-losing lines -- revenue
 *         won alongside commission earned.
 */

declare(strict_types=1);

require_once __DIR__ . '/_util.php';
require_once __DIR__ . '/calc.php';
require_once __DIR__ . '/sync-core.php';

commissions_require_access();
$pdo = commissions_db();
commissions_run_pending_migration($pdo);
$action = $_GET['action'] ?? '';

function commissions_rep_param(PDO $pdo, string $raw): array
{
    if ($raw === 'all') {
        return ['kind' => 'all', 'id' => null, 'name' => 'All Reps'];
    }
    if ($raw === 'house' || $raw === 'unassigned' || $raw === '0') {
        return ['kind' => 'house', 'id' => null, 'name' => 'House accounts (no commission)'];
    }
    $stmt = $pdo->prepare('SELECT id, name, base_pct, agreement_after_year_pct FROM reps WHERE id = :id');
    $stmt->execute([':id' => (int) $raw]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($r === false) {
        commissions_respond(404, ['ok' => false, 'error' => 'Unknown rep.']);
    }
    return ['kind' => 'rep', 'id' => (int) $r['id'], 'name' => $r['name'], 'base_pct' => (float) $r['base_pct'], 'agreement_after_year_pct' => $r['agreement_after_year_pct'] !== null ? (float) $r['agreement_after_year_pct'] : null];
}

if ($action === 'lines') {
    $rep = commissions_rep_param($pdo, (string) ($_GET['rep_id'] ?? ''));
    $bucket = (string) ($_GET['bucket'] ?? '');
    $month = (string) ($_GET['month'] ?? '');
    $params = [];
    $where = [];

    // Payee views list every line the payee earns on (a split line appears under
    // each payee with that payee's own %); 'house' = lines nobody is paid on.
    if ($rep['kind'] === 'rep') {
        $where[] = 'lc.rep_id = :rep';
        $params[':rep'] = $rep['id'];
        $join = 'JOIN line_commissions lc ON lc.line_id = l.id JOIN reps r ON r.id = lc.rep_id';
        $repCols = 'lc.rep_id AS rep_id, r.name AS rep_name, lc.pct AS pct, lc.commission AS commission';
        $order = 'r.sort_order, ';
    } elseif ($rep['kind'] === 'house') {
        $where[] = 'lc.line_id IS NULL';
        $join = 'LEFT JOIN line_commissions lc ON lc.line_id = l.id';
        $repCols = "0 AS rep_id, 'House' AS rep_name, 0 AS pct, 0 AS commission";
        $order = '';
    } else {
        $join = 'JOIN line_commissions lc ON lc.line_id = l.id JOIN reps r ON r.id = lc.rep_id';
        $repCols = 'lc.rep_id AS rep_id, r.name AS rep_name, lc.pct AS pct, lc.commission AS commission';
        $order = 'r.sort_order, ';
    }

    if ($month !== '') {
        if (preg_match('/^\d{4}-\d{2}$/', $month) !== 1) {
            commissions_respond(400, ['ok' => false, 'error' => 'month must be YYYY-MM.']);
        }
        $where[] = 'i.is_closed = 1 AND i.month = :month';
        $params[':month'] = $month;
        $title = 'Closed invoices — ' . commissions_period_label($month);
        $periodLabel = commissions_period_label($month);
    } elseif ($bucket === 'pending') {
        $where[] = 'i.is_closed = 0';
        $title = 'Pending commissions (invoices not yet Closed)';
        $periodLabel = 'Pending';
    } elseif ($bucket === 'all') {
        // Everything on the dashboard for this rep in ONE report: pending + current + last month.
        $where[] = "(i.is_closed = 0 OR (i.is_closed = 1 AND i.month IN (:mcur, :mlast)))";
        $params[':mcur'] = commissions_month_key(0);
        $params[':mlast'] = commissions_month_key(1);
        $title = 'Pending + ' . commissions_period_label(commissions_month_key(0)) . ' + ' . commissions_period_label(commissions_month_key(1));
        $periodLabel = 'Pending, Current and Last Month';
    } elseif ($bucket === 'current' || $bucket === 'last') {
        $m = commissions_month_key($bucket === 'current' ? 0 : 1);
        $where[] = 'i.is_closed = 1 AND i.month = :month';
        $params[':month'] = $m;
        $title = ($bucket === 'current' ? 'Current month' : 'Last month') . ' — ' . commissions_period_label($m);
        $periodLabel = commissions_period_label($m);
    } else {
        commissions_respond(400, ['ok' => false, 'error' => 'bucket or month required.']);
    }

    $sql = 'SELECT i.id AS invoice_id, i.invoice_number, i.invoice_date, i.company_name, i.territory, i.status_name, i.is_closed,
                   i.detail_state, i.detail_note, i.agreement_start, ' . $repCols . ',
                   l.id AS line_id, l.kind, l.item, l.ticket_id, l.ticket_summary, l.hours, l.qty, l.price, l.cost, l.cost_note,
                   l.gp, l.over_year, l.is_loss
            FROM invoice_lines l
            JOIN invoices i ON i.id = l.invoice_id
            ' . $join . '
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY ' . $order . 'i.invoice_date, i.invoice_number, l.id';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totals = ['commission' => 0.0, 'revenue' => 0.0, 'cost' => 0.0, 'gp' => 0.0, 'loss_lines' => 0, 'loss_amount' => 0.0, 'invoices' => 0, 'needs_review' => 0];
    $seen = [];
    $seenLines = [];
    foreach ($rows as &$row) {
        foreach (['hours', 'qty', 'price', 'cost', 'gp', 'pct', 'commission'] as $f) {
            if ($row[$f] !== null) {
                $row[$f] = (float) $row[$f];
            }
        }
        $row['is_loss'] = (int) $row['is_loss'];
        $row['over_year'] = (int) $row['over_year'];
        $totals['commission'] += $row['commission'];
        // A split line appears once per payee; count its money once.
        if (!isset($seenLines[$row['line_id']])) {
            $seenLines[$row['line_id']] = true;
            $totals['revenue'] += $row['price'];
            $totals['cost'] += $row['cost'];
            $totals['gp'] += $row['gp'];
            if ($row['is_loss']) {
                $totals['loss_lines']++;
                $totals['loss_amount'] += $row['gp'];
            }
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

    $late = 0;
    if ($month !== '') {
        $lock = $pdo->prepare('SELECT locked_at FROM months WHERE month = :m');
        $lock->execute([':m' => $month]);
        $lockedAt = $lock->fetchColumn();
        if ($lockedAt !== false) {
            $q = $pdo->prepare('SELECT COUNT(DISTINCT i.id) FROM invoices i' . ($rep['kind'] === 'rep' ? ' JOIN line_commissions lc ON lc.invoice_id = i.id' : '') . ' WHERE i.is_closed = 1 AND i.month = :m AND i.synced_at > :t' . ($rep['kind'] === 'rep' ? ' AND lc.rep_id = :rep' : ''));
            $qp = [':m' => $month, ':t' => $lockedAt];
            if ($rep['kind'] === 'rep') {
                $qp[':rep'] = $rep['id'];
            }
            $q->execute($qp);
            $late = (int) $q->fetchColumn();
        }
    }

    commissions_respond(200, [
        'ok' => true,
        'rep' => $rep,
        'title' => $title,
        'period_label' => $periodLabel,
        'rows' => $rows,
        'totals' => $totals,
        'late_after_lock' => $late,
        'labor_cost_per_hour' => (float) commissions_setting($pdo, 'labor_cost_per_hour', '90'),
        'generated_at' => gmdate('c'),
    ]);
}

if ($action === 'months') {
    $stmt = $pdo->query(
        "SELECT i.month, COALESCE(lc.rep_id, 0) AS rep_id, COUNT(DISTINCT i.id) AS invoices, SUM(COALESCE(lc.commission, 0)) AS commission, SUM(l.price) AS revenue,
                SUM(CASE WHEN l.is_loss = 1 THEN l.gp ELSE 0 END) AS loss_amount
         FROM invoices i JOIN invoice_lines l ON l.invoice_id = i.id LEFT JOIN line_commissions lc ON lc.line_id = l.id
         WHERE i.is_closed = 1 GROUP BY i.month, COALESCE(lc.rep_id, 0) ORDER BY i.month DESC"
    );
    $months = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $m = (string) $r['month'];
        $months[$m]['month'] = $m;
        $months[$m]['label'] = commissions_period_label($m);
        $months[$m]['reps']['r' . (int) $r['rep_id']] = [
            'invoices' => (int) $r['invoices'],
            'commission' => round((float) $r['commission'], 2),
            'revenue' => round((float) $r['revenue'], 2),
            'loss_amount' => round((float) $r['loss_amount'], 2),
        ];
    }
    $locked = $pdo->query('SELECT month, locked_at FROM months')->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach ($months as $m => &$row) {
        $row['locked'] = isset($locked[$m]);
        $row['locked_at'] = $locked[$m] ?? null;
    }
    unset($row);
    $reps = $pdo->query('SELECT id, name FROM reps WHERE active = 1 ORDER BY sort_order, id')->fetchAll(PDO::FETCH_ASSOC);
    commissions_respond(200, ['ok' => true, 'months' => array_values($months), 'reps' => $reps]);
}

if ($action === 'trends') {
    $rep = commissions_rep_param($pdo, (string) ($_GET['rep_id'] ?? ''));
    if ($rep['kind'] !== 'rep') {
        commissions_respond(400, ['ok' => false, 'error' => 'rep_id required.']);
    }
    $tz = new DateTimeZone('America/New_York');
    $now = new DateTimeImmutable('now', $tz);
    $curMonth = $now->format('Y-m');
    $year = (int) $now->format('Y');
    $monthNum = (int) $now->format('n');

    $stmt = $pdo->prepare(
        "SELECT i.month, i.company_name, SUM(l.price) AS revenue, SUM(l.gp) AS gp, SUM(lc.commission) AS commission, COUNT(DISTINCT i.id) AS invoices,
                SUM(CASE WHEN l.is_loss = 1 THEN l.gp ELSE 0 END) AS loss_amount, SUM(l.is_loss) AS loss_lines
         FROM invoices i JOIN invoice_lines l ON l.invoice_id = i.id JOIN line_commissions lc ON lc.line_id = l.id
         WHERE i.is_closed = 1 AND lc.rep_id = :rep AND i.month >= :from
         GROUP BY i.month, i.company_name"
    );
    $from = ($year - 1) . '-01';
    $stmt->execute([':rep' => $rep['id'], ':from' => $from]);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $byMonth = [];
    $byCust = [];
    foreach ($data as $r) {
        $m = (string) $r['month'];
        $byMonth[$m] ??= ['revenue' => 0.0, 'gp' => 0.0, 'commission' => 0.0, 'invoices' => 0, 'loss_amount' => 0.0, 'loss_lines' => 0];
        $byMonth[$m]['revenue'] += (float) $r['revenue'];
        $byMonth[$m]['gp'] += (float) $r['gp'];
        $byMonth[$m]['commission'] += (float) $r['commission'];
        $byMonth[$m]['invoices'] += (int) $r['invoices'];
        $byMonth[$m]['loss_amount'] += (float) $r['loss_amount'];
        $byMonth[$m]['loss_lines'] += (int) $r['loss_lines'];

        $c = (string) $r['company_name'];
        $byCust[$c]['months'][$m] = ['revenue' => (float) $r['revenue'], 'commission' => (float) $r['commission']];
    }

    // 13-month series, oldest -> newest, with month-over-month deltas.
    $series = [];
    $prev = null;
    for ($k = 12; $k >= 0; $k--) {
        $m = commissions_month_key($k, $now);
        $v = $byMonth[$m] ?? ['revenue' => 0.0, 'gp' => 0.0, 'commission' => 0.0, 'invoices' => 0, 'loss_amount' => 0.0, 'loss_lines' => 0];
        $row = [
            'month' => $m,
            'label' => commissions_period_label($m),
            'partial' => $m === $curMonth,
            'has_data' => isset($byMonth[$m]),
            'revenue' => round($v['revenue'], 2),
            'gp' => round($v['gp'], 2),
            'commission' => round($v['commission'], 2),
            'invoices' => $v['invoices'],
            'loss_amount' => round($v['loss_amount'], 2),
            'loss_lines' => $v['loss_lines'],
            'commission_change' => $prev !== null ? round($v['commission'] - $prev['commission'], 2) : null,
            'revenue_change' => $prev !== null ? round($v['revenue'] - $prev['revenue'], 2) : null,
        ];
        $series[] = $row;
        $prev = $v;
    }

    $sumRange = static function (array $byMonth, string $from, string $to): array {
        $s = ['revenue' => 0.0, 'gp' => 0.0, 'commission' => 0.0, 'invoices' => 0];
        foreach ($byMonth as $m => $v) {
            if ($m >= $from && $m <= $to) {
                $s['revenue'] += $v['revenue'];
                $s['gp'] += $v['gp'];
                $s['commission'] += $v['commission'];
                $s['invoices'] += $v['invoices'];
            }
        }
        return array_map(static fn ($x) => is_float($x) ? round($x, 2) : $x, $s);
    };
    $pad = static fn (int $y, int $m): string => sprintf('%04d-%02d', $y, $m);
    $ytd = $sumRange($byMonth, $pad($year, 1), $pad($year, $monthNum));
    $priorYtd = $sumRange($byMonth, $pad($year - 1, 1), $pad($year - 1, $monthNum));
    $priorYear = $sumRange($byMonth, $pad($year - 1, 1), $pad($year - 1, 12));

    $customers = [];
    foreach ($byCust as $name => $c) {
        $y = ['revenue' => 0.0, 'commission' => 0.0];
        $p = ['revenue' => 0.0, 'commission' => 0.0];
        $spark = [];
        foreach ($c['months'] as $m => $v) {
            if ($m >= $pad($year, 1) && $m <= $pad($year, $monthNum)) {
                $y['revenue'] += $v['revenue'];
                $y['commission'] += $v['commission'];
            } elseif ($m >= $pad($year - 1, 1) && $m <= $pad($year - 1, $monthNum)) {
                $p['revenue'] += $v['revenue'];
                $p['commission'] += $v['commission'];
            }
        }
        for ($k = 11; $k >= 0; $k--) {
            $m = commissions_month_key($k, $now);
            $spark[] = round($c['months'][$m]['revenue'] ?? 0.0, 2);
        }
        $customers[] = [
            'customer' => $name,
            'ytd_revenue' => round($y['revenue'], 2),
            'ytd_commission' => round($y['commission'], 2),
            'prior_ytd_revenue' => round($p['revenue'], 2),
            'prior_ytd_commission' => round($p['commission'], 2),
            'commission_change' => round($y['commission'] - $p['commission'], 2),
            'revenue_change' => round($y['revenue'] - $p['revenue'], 2),
            'last12_revenue' => $spark,
        ];
    }
    usort($customers, static fn ($a, $b) => $b['ytd_revenue'] <=> $a['ytd_revenue']);

    // Money-losing lines in the last 12 months (also shown on printed reports).
    $loss = $pdo->prepare(
        "SELECT i.invoice_number, i.invoice_date, i.company_name, i.territory, l.item, l.ticket_summary, l.hours, l.cost, l.price, l.gp, lc.pct, lc.commission
         FROM invoice_lines l JOIN invoices i ON i.id = l.invoice_id JOIN line_commissions lc ON lc.line_id = l.id
         WHERE i.is_closed = 1 AND lc.rep_id = :rep AND l.is_loss = 1 AND i.month >= :from
         ORDER BY l.gp ASC LIMIT 300"
    );
    $loss->execute([':rep' => $rep['id'], ':from' => commissions_month_key(11, $now)]);

    // Which months have data at all, so the report can say "backfill more".
    $monthsWithData = array_keys($byMonth);
    sort($monthsWithData);

    commissions_respond(200, [
        'ok' => true,
        'rep' => $rep,
        'series' => $series,
        'ytd' => $ytd,
        'prior_ytd' => $priorYtd,
        'prior_year' => $priorYear,
        'year' => $year,
        'through_month' => $monthNum,
        'customers' => array_slice($customers, 0, 300),
        'loss_lines' => $loss->fetchAll(PDO::FETCH_ASSOC),
        'earliest_month_with_data' => $monthsWithData[0] ?? null,
        'generated_at' => gmdate('c'),
    ]);
}

commissions_respond(400, ['ok' => false, 'error' => 'Unknown action.']);
