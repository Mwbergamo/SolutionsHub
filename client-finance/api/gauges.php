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
 * GET ?action=mrr
 *   -> { ok: true, mrr: { month, total, customers_with_revenue,
 *        coverage: {synced, eligible},
 *        bulk_sync: {started_at, finished_at} } }
 *
 *   "Agreement/recurring revenue" per Michael's approved gauge list --
 *   defined here as the portfolio's total Agreement-category invoiced
 *   dollars for the most recently fully-completed calendar month. This
 *   is real invoiced revenue, NOT a computed (addition unit price x
 *   billing frequency) estimate -- no sync in this codebase has ever
 *   captured a ConnectWise agreement addition's billing frequency, so
 *   there is no confirmed field to compute a theoretical MRR from. See
 *   claude/client-finance-gauges.md's "MRR definition" section for the
 *   one known caveat this choice carries: a customer billed quarterly or
 *   annually will show as a one-month spike in whatever month that
 *   invoice lands, not smoothed across the period it actually covers --
 *   this mirrors the raw ConnectWise invoice data as-is, nothing is
 *   being smoothed or assumed on top of it. Flag to Michael if a
 *   smoothed definition is what he actually wants instead.
 */

declare(strict_types=1);

require_once __DIR__ . '/_util.php';
require_once __DIR__ . '/../../relationships/api/db.php';

client_finance_require_access();
$pdo = client_finance_db();
$action = $_GET['action'] ?? '';

/** Real, ConnectWise-linked, non-prospect customers -- same filter client-finance-cron.php queues. */
function client_finance_eligible_customer_count(): int
{
    $rpdo = relationships_db();
    $stmt = $rpdo->query(
        "SELECT COUNT(*) FROM customers
         WHERE connectwise_id IS NOT NULL AND TRIM(connectwise_id) <> '' AND connectwise_id GLOB '[0-9]*'
           AND COALESCE(is_duplicate_hidden, 0) = 0 AND COALESCE(is_prospect_only, 0) = 0"
    );
    return (int) $stmt->fetchColumn();
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

if ($action === 'mrr') {
    // Strictly before the current month, so a month that's only
    // partially elapsed never shows as an artificially low "MRR".
    $cutoffMonth = gmdate('Y-m');
    $stmt = $pdo->prepare(
        "SELECT month, SUM(total) AS total, COUNT(DISTINCT cw_company_id) AS customers
         FROM invoices WHERE category = 'agreement' AND month < :cutoff
         GROUP BY month ORDER BY month DESC LIMIT 1"
    );
    $stmt->execute([':cutoff' => $cutoffMonth]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $synced = (int) $pdo->query('SELECT COUNT(*) FROM company_sync_state WHERE agreements_synced_at IS NOT NULL')->fetchColumn();

    client_finance_respond(200, ['ok' => true, 'mrr' => [
        'month' => $row['month'] ?? null,
        'total' => $row ? (float) $row['total'] : 0.0,
        'customers_with_revenue' => $row ? (int) $row['customers'] : 0,
        'coverage' => ['synced' => $synced, 'eligible' => client_finance_eligible_customer_count()],
        'bulk_sync' => client_finance_bulk_sync_state($pdo),
    ]]);
}

client_finance_respond(400, ['ok' => false, 'error' => 'Unknown action.']);
