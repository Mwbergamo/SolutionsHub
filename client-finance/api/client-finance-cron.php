<?php
/**
 * client-finance/api/client-finance-cron.php
 *
 * CLI entry point for a nightly bulk ConnectWise sync across CodeBlue's
 * whole customer book -- added 2026-10-21, specifically so the
 * portfolio-wide gauges on the Client Finance front page (gauges.php,
 * app.js's gaugesHtml()) can mean "the whole book", not just whichever
 * customers a rep happened to have opened before. See
 * claude/client-finance-gauges.md for the full decision trail -- this is
 * a deliberate reversal of db.php's original "on demand, per customer,
 * NOT a big nightly sync" design (Michael's own call, 2026-10-21, once he
 * decided a true portfolio-wide number was worth the added ConnectWise
 * API load). Same trade-off relationships/api/connectwise-cron.php
 * already makes nightly for ~1,800+ customers' billing/tickets/contacts,
 * so this follows that exact precedent rather than inventing a new one.
 *
 * Reuses every section sync function in sync-core.php completely
 * unchanged -- this script's only job is enumerating which companies to
 * sync and driving the existing queue-based start()/step() loop
 * (client_finance_sync_start()/client_finance_sync_step()) across all of
 * them, instead of just the one company an on-demand "Sync now" click
 * queues. The dashboard's own on-demand sync (sync.php) is untouched and
 * still works exactly as before for a rep who wants one customer
 * refreshed right now -- the two write to the same tables and queue, so
 * whichever ran most recently for a given company wins.
 *
 *   php /home/USERNAME/public_html/client-finance/api/client-finance-cron.php
 *
 * (Bluehost cPanel: Advanced -> Cron Jobs, once a night -- same place
 * relationships/api/connectwise-cron.php is already scheduled. Not
 * web-accessible -- see the PHP_SAPI guard below.)
 *
 * Every eligible company gets a full wipe + re-sync of all 5 sections,
 * same as an on-demand "Sync now" -- there's no incremental/partial sync
 * in sync-core.php to call instead, and a nightly full refresh keeps this
 * simple and self-healing (a bad row from a prior run never lingers).
 * KNOWN COST, flagged plainly rather than discovered later: this is
 * roughly 5+ ConnectWise round trips per company (more for a company
 * with many agreements, since each agreement's additions is its own
 * call), so a ~1,000+ company book means several thousand API calls in
 * one run. set_time_limit(0) mirrors connectwise-cron.php's own handling
 * of this; if ConnectWise rate-limiting or total run time ever becomes a
 * real problem in practice, the fix is to only queue companies whose
 * last sync is older than some threshold (a "stale-after N hours" check
 * against company_sync_state) rather than always re-syncing everyone --
 * deliberately not built that way yet since Michael asked for the
 * straightforward bulk sync first and this can be tightened later if the
 * straightforward version turns out too slow/expensive.
 *
 * `sync_state` records bulk_sync_started_at/finished_at (gmdate('c'), UTC
 * ISO-8601) so gauges.php can show an "as of" freshness badge without
 * guessing from individual companies' synced_at timestamps, which would
 * be misleading while a run is still in progress.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script is CLI-only.');
}

require_once __DIR__ . '/sync-core.php';
require_once __DIR__ . '/../../relationships/api/db.php';

set_time_limit(0);

$pdo = client_finance_db();
$rpdo = relationships_db();

$pdo->prepare("INSERT OR REPLACE INTO sync_state (key, value) VALUES ('bulk_sync_started_at', :v)")
    ->execute([':v' => gmdate('c')]);

echo '[' . date('c') . "] Starting Client Finance bulk sync...\n";

// Same eligibility filter as customers.php's search picker, minus
// prospect-only companies -- a prospect has no real ConnectWise
// invoices/agreements to sync, so including it would just be wasted
// round trips and a misleading denominator on the gauges' coverage count.
$customers = $rpdo->query(
    "SELECT connectwise_id, name FROM customers
     WHERE connectwise_id IS NOT NULL AND TRIM(connectwise_id) <> '' AND connectwise_id GLOB '[0-9]*'
       AND COALESCE(is_duplicate_hidden, 0) = 0 AND COALESCE(is_prospect_only, 0) = 0
     ORDER BY name"
)->fetchAll(PDO::FETCH_ASSOC);

echo 'Queuing ' . count($customers) . " customers (5 sections each)...\n";

$queuedCount = 0;
$startErrors = [];
foreach ($customers as $c) {
    $cwCompanyId = (int) $c['connectwise_id'];
    if ($cwCompanyId <= 0) {
        continue;
    }
    try {
        client_finance_sync_start($pdo, $cwCompanyId, (string) $c['name']);
        $queuedCount++;
    } catch (Throwable $e) {
        $startErrors[] = ['company_name' => (string) $c['name'], 'error' => $e->getMessage()];
    }
}
echo "Queued $queuedCount companies. " . count($startErrors) . " failed to queue.\n";
foreach ($startErrors as $err) {
    echo "  queue error - {$err['company_name']}: {$err['error']}\n";
}

$processed = 0;
$sectionErrors = [];
do {
    $result = client_finance_sync_step($pdo);
    if (($result['section'] ?? null) !== null) {
        $processed++;
        if (isset($result['error'])) {
            $sectionErrors[] = $result;
        }
        if ($processed % 200 === 0) {
            echo "  processed $processed sections so far (remaining {$result['remaining']})\n";
        }
    }
} while (!$result['done']);

echo '[' . date('c') . "] Done. $processed sections processed, " . count($sectionErrors) . " section errors.\n";
if ($sectionErrors !== []) {
    echo "Section errors:\n";
    foreach ($sectionErrors as $err) {
        echo "  - company {$err['cw_company_id']}, section {$err['section']}: {$err['error']}\n";
    }
}

$pdo->prepare("INSERT OR REPLACE INTO sync_state (key, value) VALUES ('bulk_sync_finished_at', :v)")
    ->execute([':v' => gmdate('c')]);

exit(($startErrors !== [] || $sectionErrors !== []) ? 1 : 0);
