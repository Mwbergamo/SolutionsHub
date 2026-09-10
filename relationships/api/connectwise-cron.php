<?php
/**
 * relationships/api/connectwise-cron.php
 *
 * CLI entry point for an automated (e.g. nightly) ConnectWise sync, meant
 * to be run as a cPanel cron job:
 *
 *   php /home/USERNAME/public_html/relationships/api/connectwise-cron.php
 *
 * (Bluehost cPanel: Advanced -> Cron Jobs. Once a day is plenty -- this
 * dashboard doesn't need real-time freshness.) Not web-accessible -- see
 * the PHP_SAPI guard below -- so hitting its URL in a browser does
 * nothing. The dashboard's "Sync Now" button (sync.php) runs the exact
 * same underlying sync on demand instead, for testing or an immediate
 * refresh without waiting on cron.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script is CLI-only.');
}

require_once __DIR__ . '/connectwise-sync-core.php';
require_once __DIR__ . '/connectwise-billing-sync-core.php';

set_time_limit(0);

$pdo = relationships_db();

echo "[" . date('c') . "] Starting ConnectWise agreement sync...\n";

try {
    $start = relationships_cw_sync_start($pdo);
} catch (RelationshipsConnectWiseError $e) {
    fwrite(STDERR, "Failed to start sync: " . $e->getMessage() . "\n");
    exit(1);
}
echo "Queued {$start['total']} agreements.\n";

$totalErrors = [];
do {
    $result = relationships_cw_sync_step($pdo, 100);
    echo "  processed {$result['processed_this_batch']} (remaining {$result['remaining']}, errors so far {$result['totals']['error']})\n";
    foreach ($result['errors'] as $err) {
        $totalErrors[] = $err;
    }
} while (!$result['done']);

echo "[" . date('c') . "] Done. " . $result['totals']['done'] . " agreements synced, " . $result['totals']['error'] . " failed.\n";

if ($totalErrors !== []) {
    echo "Errors:\n";
    foreach ($totalErrors as $err) {
        echo "  - agreement {$err['agreement_id']} ({$err['company_name']}): {$err['error']}\n";
    }
}

$agreementSyncErrorCount = $result['totals']['error'];

// Monthly Billing (customer_monthly_billing) -- added 2026-09-10, runs
// after the agreement sync above so it only sees customers the agreement
// sync just created/updated. Same "one exit code for the whole run" idea
// -- a billing failure is reported and rolled into the final exit code,
// but never aborts or is aborted by the agreement sync above; the two are
// independent queues.
echo "\n[" . date('c') . "] Starting ConnectWise Monthly Billing sync...\n";

try {
    $billingStart = relationships_cw_billing_sync_start($pdo);
} catch (RelationshipsConnectWiseError $e) {
    fwrite(STDERR, "Failed to start billing sync: " . $e->getMessage() . "\n");
    exit(1);
}
echo "Queued {$billingStart['total']} customers.\n";

$totalBillingErrors = [];
do {
    $billingResult = relationships_cw_billing_sync_step($pdo, 50);
    echo "  processed {$billingResult['processed_this_batch']} (remaining {$billingResult['remaining']}, errors so far {$billingResult['totals']['error']})\n";
    foreach ($billingResult['errors'] as $err) {
        $totalBillingErrors[] = $err;
    }
} while (!$billingResult['done']);

echo "[" . date('c') . "] Done. " . $billingResult['totals']['done'] . " customers' billing synced, " . $billingResult['totals']['error'] . " failed.\n";

if ($totalBillingErrors !== []) {
    echo "Billing errors:\n";
    foreach ($totalBillingErrors as $err) {
        echo "  - customer {$err['customer_id']} ({$err['company_name']}): {$err['error']}\n";
    }
}

exit(($agreementSyncErrorCount > 0 || $billingResult['totals']['error'] > 0) ? 1 : 0);
