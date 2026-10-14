<?php
/**
 * sell_probe.php — TEMPORARY, READ-ONLY diagnostic.
 *
 * Confirms the real ConnectWise CPQ (Sell/Quosal) REST API auth scheme and
 * a few basic endpoints against CodeBlue's live instance, before any
 * quote-creation code gets written. No public ConnectWise documentation
 * exists for this API (confirmed via extensive research) -- this scheme
 * is reverse-engineered from a working open-source client
 * (github.com/WYRE-AI/node-connectwise-cpq) and corroborated by a working
 * BrightGauge connector already running on this same CodeBlue Sell
 * instance. This probe is the step that actually confirms it against the
 * real account, same "probe before building" process used for every other
 * ConnectWise integration in this project.
 *
 * This script ONLY issues GET requests. It never POSTs, PUTs, PATCHes, or
 * DELETEs anything -- nothing in Sell is created, changed, or removed by
 * running this. Delete it once the output below is captured.
 *
 * Usage: deploy alongside connectwise-sell-config.php (repo root), then
 * load this file in a browser on the live server.
 */

header('Content-Type: text/plain');
set_time_limit(60);

$configPath = __DIR__ . '/connectwise-sell-config.php';
if (!file_exists($configPath)) {
    die("ERROR: connectwise-sell-config.php not found at $configPath\n");
}
$cfg = require $configPath;

$accessKey = $cfg['access_key'] ?? '';
$publicKey = $cfg['public_key'] ?? '';
$privateKey = $cfg['private_key'] ?? '';
$baseUrl = rtrim($cfg['base_url'] ?? 'https://sellapi.quosalsell.com', '/');

if (!$accessKey || !$publicKey || !$privateKey) {
    die("ERROR: access_key / public_key / private_key missing from config.\n");
}

// CPQ Basic auth: base64("{accessKey}+{publicKey}:{privateKey}").
$authToken = base64_encode($accessKey . '+' . $publicKey . ':' . $privateKey);

function cpqGet($baseUrl, $path, $authToken) {
    $url = $baseUrl . $path;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: application/json',
        'Content-Type: application/json; version=1.0',
        'Authorization: Basic ' . $authToken,
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    $body = curl_exec($ch);
    $err = curl_error($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    echo "GET $url\n";
    echo "HTTP $status\n";
    if ($err) {
        echo "CURL ERROR: $err\n";
        return;
    }
    $pretty = $body;
    $decoded = json_decode($body, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        $pretty = json_encode($decoded, JSON_PRETTY_PRINT);
    }
    $preview = strlen($pretty) > 2000 ? substr($pretty, 0, 2000) . "\n...[truncated, " . strlen($pretty) . " bytes total]" : $pretty;
    echo "Body:\n$preview\n";
}

echo "=== ConnectWise CPQ (Sell) probe -- " . date('Y-m-d H:i:s') . " ===\n";
echo "base_url=$baseUrl  access_key=$accessKey  public_key_len=" . strlen($publicKey) . "  private_key_len=" . strlen($privateKey) . "\n\n";

echo "########## 1. /settings/user (list CPQ users -- for mapping Solutions Hub reps to Sell users) ##########\n";
cpqGet($baseUrl, '/settings/user', $authToken);

echo "\n########## 2. /api/templates (quote templates -- the only create path is copying one of these) ##########\n";
cpqGet($baseUrl, '/api/templates', $authToken);

echo "\n########## 3. /api/quotes?pageSize=3 (a small page of real quotes, to see the live field shape) ##########\n";
cpqGet($baseUrl, '/api/quotes?pageSize=3', $authToken);

echo "\n=== probe complete ===\n";
echo "DELETE THIS FILE once you've captured the output above -- it's a diagnostic only, not part of the app.\n";
