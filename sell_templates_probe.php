<?php
/**
 * sell_templates_probe.php — TEMPORARY, READ-ONLY diagnostic.
 *
 * Lists every Sell/CPQ template (name + id + quoteNumber) so the real "Proposal" template id can
 * be filled into RELATIONSHIPS_SELL_QUOTE_TEMPLATES in relationships/api/sell.php (currently null
 * there — deliberately left unconfigured rather than guessed, per this project's discipline
 * around ConnectWise field names/ids). Read-only — only issues a GET. Delete once captured.
 */

header('Content-Type: text/plain');
set_time_limit(30);

$configPath = __DIR__ . '/connectwise-sell-config.php';
$cfg = require $configPath;
$authToken = base64_encode($cfg['access_key'] . '+' . $cfg['public_key'] . ':' . $cfg['private_key']);
$baseUrl = rtrim($cfg['base_url'], '/');

$ch = curl_init($baseUrl . '/api/templates');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Accept: application/json',
        'Content-Type: application/json; version=1.0',
        'Authorization: Basic ' . $authToken,
    ],
    CURLOPT_TIMEOUT => 15,
]);
$body = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "=== Sell templates probe — " . date('Y-m-d H:i:s') . " ===\nHTTP $status\n\n";
$templates = json_decode($body, true);
if (is_array($templates)) {
    foreach ($templates as $t) {
        echo '- "' . ($t['name'] ?? '?') . '"  quoteNumber=' . ($t['quoteNumber'] ?? '?') . '  id=' . ($t['id'] ?? '?') . "\n";
    }
    echo "\nTotal: " . count($templates) . "\n";
} else {
    echo "Raw body:\n$body\n";
}
echo "\nDELETE THIS FILE once you've found the real \"Proposal\" template above.\n";
