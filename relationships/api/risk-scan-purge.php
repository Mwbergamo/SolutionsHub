<?php
/**
 * relationships/api/risk-scan-purge.php
 *
 * CLI entry point for the risk-scan 1-year retention purge, meant to run
 * as a nightly cPanel cron job (same pattern as connectwise-cron.php):
 *
 *   php /home/USERNAME/public_html/relationships/api/risk-scan-purge.php
 *
 * Deletes every risk_scans row (and its file under data/risk-scans/) once
 * uploaded_at is more than a year old -- regardless of whether it was
 * ever reviewed. Michael, 2026-09-23: "I'd like the risk scans to only
 * retain 1 year of files before purging them to keep the server from
 * filling up" -- Bluehost shared hosting has a real, fixed disk quota, so
 * this is a genuine constraint, not a nice-to-have.
 *
 * Deletes the row FIRST, then the file -- if the file delete fails (e.g.
 * already gone), the customer's dashboard still stops listing it rather
 * than the purge silently retrying it forever. Not web-accessible -- see
 * the PHP_SAPI guard below.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script is CLI-only.');
}

require_once __DIR__ . '/_util.php';

$pdo = relationships_db();

$cutoff = (new DateTimeImmutable('-1 year'))->format('Y-m-d H:i:s');

$stmt = $pdo->prepare('SELECT id, customer_id, stored_filename, original_filename, uploaded_at FROM risk_scans WHERE uploaded_at < :cutoff');
$stmt->execute([':cutoff' => $cutoff]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "[" . date('c') . "] Risk-scan purge: " . count($rows) . " row(s) older than {$cutoff}.\n";

$deletedRows = 0;
$deletedFiles = 0;
$missingFiles = 0;

foreach ($rows as $row) {
    $path = __DIR__ . '/../data/risk-scans/' . (int) $row['customer_id'] . '/' . $row['stored_filename'];

    $pdo->prepare('DELETE FROM risk_scans WHERE id = :id')->execute([':id' => $row['id']]);
    $deletedRows++;

    if (is_file($path)) {
        if (unlink($path)) {
            $deletedFiles++;
        } else {
            fwrite(STDERR, "  could not delete file for scan #{$row['id']} ({$row['original_filename']}): {$path}\n");
        }
    } else {
        $missingFiles++;
    }

    echo "  purged scan #{$row['id']} -- {$row['original_filename']} (uploaded {$row['uploaded_at']})\n";
}

// Clean up now-empty per-customer folders so they don't accumulate
// forever -- purely cosmetic (an empty dir costs nothing), but keeps
// data/risk-scans/ tidy for anyone poking around on the server.
$baseDir = __DIR__ . '/../data/risk-scans';
if (is_dir($baseDir)) {
    foreach (scandir($baseDir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $entryPath = $baseDir . '/' . $entry;
        if (is_dir($entryPath) && count(scandir($entryPath) ?: []) <= 2) {
            @rmdir($entryPath);
        }
    }
}

echo "Done. Rows deleted: {$deletedRows}, files deleted: {$deletedFiles}, files already missing: {$missingFiles}.\n";
