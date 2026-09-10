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

set_time_limit(0);

$pdo = relationships_db();

echo "[" . date('c') . "] Starting ConnectWise sync...\n";

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

exit($result['totals']['error'] > 0 ? 1 : 0);
