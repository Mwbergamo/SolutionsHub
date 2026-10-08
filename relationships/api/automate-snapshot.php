<?php
/**
 * relationships/api/automate-snapshot.php
 *
 * Nightly job: records one capacity snapshot (RAM, CPU, storage, online/offline) for every device of every
 * Automate client that is linked to a ConnectWise company, so the Customer Network page can flag
 * "over 90% for most of the last 90 days" and "offline for 30+ days" for every customer, not just ones someone opened.
 *
 * Command line only. cPanel > Cron Jobs, once a day (e.g. 2:15 AM):
 *   /usr/local/bin/php /home/<account>/public_html/relationships/api/automate-snapshot.php
 * Add --all to include clients with no ConnectWise link (ExternalId 0).
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('RELATIONSHIPS_AUTOMATE_LIB', true);
require_once __DIR__ . '/automate.php';

$all = in_array('--all', $argv, true);
$pdo = relationships_db();
$done = 0;
$failed = 0;
foreach (relationships_automate_clients() as $c) {
    $c = (array) $c;
    $id = (int) relationships_automate_pick($c, ['Id', 'id'], 0);
    $ext = trim((string) relationships_automate_pick($c, ['ExternalId', 'externalId'], ''));
    if ($id <= 0 || (!$all && ($ext === '' || $ext === '0'))) {
        continue;
    }
    try {
        relationships_automate_network_payload($pdo, $id, true);
        $done++;
    } catch (Throwable $e) {
        $failed++;
        fwrite(STDERR, 'client ' . $id . ': ' . $e->getMessage() . "\n");
    }
}
// Keep a year of history.
relationships_automate_snapshot_table($pdo);
$pdo->exec("DELETE FROM automate_snapshots WHERE day < date('now','-365 day')");
echo date('c') . " snapshots recorded for $done client(s), $failed failed\n";
exit($failed > 0 && $done === 0 ? 1 : 0);
