<?php
/**
 * commissions/api/dashboard.php
 *
 * GET -> the live commissions grid: one column per rep, three numbers each:
 *   pending  invoices created in ConnectWise but NOT yet Closed / Closed - Emailed
 *   current  this month's invoices that are Closed or Closed - Emailed
 *   last     last month's invoices that are Closed or Closed - Emailed
 * House accounts (territories that pay nobody) are not in the grid.
 * Every number is COMMISSION dollars (not gross profit or revenue); revenue,
 * gross profit, loss counts and "needs review" counts ride along for the
 * little sub-labels and for reporting.
 */

declare(strict_types=1);

require_once __DIR__ . '/_util.php';
require_once __DIR__ . '/calc.php';
require_once __DIR__ . '/sync-core.php';

commissions_require_access();
$pdo = commissions_db();
commissions_run_pending_migration($pdo);

$cur = commissions_month_key(0);
$last = commissions_month_key(1);

$empty = static fn (): array => ['commission' => 0.0, 'revenue' => 0.0, 'gp' => 0.0, 'invoices' => 0, 'loss_lines' => 0, 'loss_amount' => 0.0, 'needs_review' => 0];

$reps = $pdo->query('SELECT * FROM reps WHERE active = 1 ORDER BY sort_order, id')->fetchAll(PDO::FETCH_ASSOC);
$grid = [];
foreach ($reps as $r) {
    $grid[(int) $r['id']] = ['pending' => $empty(), 'current' => $empty(), 'last' => $empty()];
}

$bucketSql = "CASE WHEN i.is_closed = 0 THEN 'pending' WHEN i.month = :cur THEN 'current' WHEN i.month = :last THEN 'last' ELSE 'other' END";

// One row per payee per line: a split territory shows the line's revenue/GP
// under each payee, but the commission dollars are each payee's own share.
$stmt = $pdo->prepare(
    "SELECT lc.rep_id AS rep_id, $bucketSql AS bucket,
            COUNT(DISTINCT i.id) AS invoices, SUM(lc.commission) AS commission, SUM(l.price) AS revenue, SUM(l.gp) AS gp,
            SUM(l.is_loss) AS loss_lines, SUM(CASE WHEN l.is_loss = 1 THEN l.gp ELSE 0 END) AS loss_amount,
            COUNT(DISTINCT CASE WHEN i.detail_state != 'ok' THEN i.id END) AS needs_review
     FROM invoices i JOIN invoice_lines l ON l.invoice_id = i.id JOIN line_commissions lc ON lc.line_id = l.id
     GROUP BY lc.rep_id, bucket"
);
$stmt->execute([':cur' => $cur, ':last' => $last]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $b = (string) $row['bucket'];
    $rid = (int) $row['rep_id'];
    if ($b === 'other' || !isset($grid[$rid])) {
        continue;
    }
    $grid[$rid][$b] = [
        'commission' => round((float) $row['commission'], 2),
        'revenue' => round((float) $row['revenue'], 2),
        'gp' => round((float) $row['gp'], 2),
        'invoices' => (int) $row['invoices'],
        'loss_lines' => (int) $row['loss_lines'],
        'loss_amount' => round((float) $row['loss_amount'], 2),
        'needs_review' => (int) $row['needs_review'],
    ];
}

$out = [];
foreach ($reps as $r) {
    $out[] = [
        'id' => (int) $r['id'],
        'name' => $r['name'],
        'base_pct' => (float) $r['base_pct'],
        'agreement_after_year_pct' => $r['agreement_after_year_pct'] !== null ? (float) $r['agreement_after_year_pct'] : null,
        'pct_missing' => (float) $r['base_pct'] <= 0,
    ] + $grid[(int) $r['id']];
}

$scope = "(i.is_closed = 0 OR i.month IN ('" . $cur . "','" . $last . "'))";
$noPayee = 'NOT EXISTS (SELECT 1 FROM line_commissions lc WHERE lc.invoice_id = i.id)';
$totalInvoices = (int) $pdo->query('SELECT COUNT(*) FROM invoices')->fetchColumn();
// House accounts: territories that pay nobody (Trey, Walter, Richmond, Michael, House ...).
$houseInvoices = (int) $pdo->query("SELECT COUNT(*) FROM invoices i WHERE $scope AND $noPayee")->fetchColumn();
$review = (int) $pdo->query("SELECT COUNT(*) FROM invoices i WHERE i.detail_state != 'ok' AND $scope")->fetchColumn();

commissions_respond(200, [
    'ok' => true,
    'reps' => $out,
    'house_invoices' => $houseInvoices,
    'needs_review_invoices' => $review,
    'periods' => [
        'current' => ['month' => $cur, 'label' => commissions_period_label($cur)],
        'last' => ['month' => $last, 'label' => commissions_period_label($last)],
    ],
    'labor_cost_per_hour' => (float) commissions_setting($pdo, 'labor_cost_per_hour', '90'),
    'total_invoices' => $totalInvoices,
    'sync' => commissions_sync_status($pdo),
]);
