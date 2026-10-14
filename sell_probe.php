<?php
/**
 * sell_probe.php — TEMPORARY, READ-ONLY diagnostic.
 *
 * Purpose: empirically discover how CodeBlue's ConnectWise Sell/CPQ
 * (Quosal) instance actually wants to be authenticated and what its real
 * API base URL is. No public documentation exists for this (confirmed via
 * extensive web research) -- same "probe the real instance first" process
 * used for every other ConnectWise integration in this project.
 *
 * This script ONLY issues GET requests. It never POSTs, PUTs, PATCHes, or
 * DELETEs anything. It is meant to be deleted once the real scheme is
 * confirmed (same convention as every other *-probe.php in this project).
 *
 * Usage: place this file in the SolutionsHub repo root (same directory as
 * connectwise-sell-config.php), then load it in a browser while logged
 * into the server, or run it via `php sell_probe.php` on the server.
 */

header('Content-Type: text/plain');
set_time_limit(170);
if (function_exists('ob_implicit_flush')) { ob_implicit_flush(true); }
while (ob_get_level() > 0) { ob_end_flush(); }

$configPath = __DIR__ . '/connectwise-sell-config.php';
if (!file_exists($configPath)) {
    die("ERROR: connectwise-sell-config.php not found at $configPath\n");
}
$cfg = require $configPath;

$publicKey = $cfg['public_key'] ?? '';
$privateKey = $cfg['private_key'] ?? '';
$companyId = $cfg['company_id'] ?? '';
$clientId = $cfg['client_id'] ?? '';

if (!$publicKey || !$privateKey) {
    die("ERROR: public_key / private_key missing from config.\n");
}

// The one real fact we have: the web UI lives at
//   https://codebluetechnology.quosalsell.com/QuosalWeb/quote.search?accesskey=codebluetechnology_azure&skiplayout=yes
// Candidate API roots to try, built off that same host/app path. Quosal's
// web app (QuosalWeb) is an older ASP.NET WebForms app, which sometimes
// exposes a parallel API under /api/, /QuosalWeb/api/, or a .asmx SOAP
// service -- trying several shapes rather than guessing one.
$host = 'https://codebluetechnology.quosalsell.com';
$accessKey = 'codebluetechnology_azure';

$candidateUrls = [
    'web-root'          => "$host/",
    'quosalweb-root'    => "$host/QuosalWeb/",
    'api-root'          => "$host/api/",
    'api-v1'            => "$host/api/v1/",
    'quosalweb-api'     => "$host/QuosalWeb/api/",
    'quosalweb-api-quotes' => "$host/QuosalWeb/api/quotes",
    'api-quotes'        => "$host/api/quotes",
    'quote-list-with-key' => "$host/QuosalWeb/quote.search?accesskey=$accessKey&format=json",
];

// Candidate auth header shapes to try against each URL.
function buildAuthVariants($publicKey, $privateKey, $companyId, $clientId) {
    $variants = [];

    // 1. HTTP Basic Auth, publicKey:privateKey (mirrors ConnectWise
    //    Manage's REST API convention: base64(companyId+publicKey:privateKey)
    //    -- trying both the Manage-style combined form and a plain pair).
    $variants['basic_public_private'] = [
        'Authorization: Basic ' . base64_encode($publicKey . ':' . $privateKey),
    ];
    if ($companyId) {
        $variants['basic_company_public_private'] = [
            'Authorization: Basic ' . base64_encode($companyId . '+' . $publicKey . ':' . $privateKey),
        ];
    }

    // 2. Custom headers some vendors use instead of Authorization.
    $variants['custom_x_headers'] = [
        'X-Public-Key: ' . $publicKey,
        'X-Private-Key: ' . $privateKey,
    ];
    $variants['custom_api_key_headers'] = [
        'PublicKey: ' . $publicKey,
        'PrivateKey: ' . $privateKey,
    ];

    // 3. clientId header (ConnectWise Manage requires this alongside Basic
    //    Auth) -- try it added to the plain Basic variant.
    if ($clientId) {
        $variants['basic_public_private_with_clientid'] = [
            'Authorization: Basic ' . base64_encode($publicKey . ':' . $privateKey),
            'clientId: ' . $clientId,
        ];
    }

    // 4. No auth at all (control case -- tells us what an unauthenticated
    //    request looks like, for comparison).
    $variants['no_auth'] = [];

    return $variants;
}

$authVariants = buildAuthVariants($publicKey, $privateKey, $companyId, $clientId);

function probeOne($url, $headers) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($headers, ['Accept: application/json']));
    curl_setopt($ch, CURLOPT_TIMEOUT, 4);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false); // see redirects explicitly
    $response = curl_exec($ch);
    $err = curl_error($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    if ($response === false) {
        return "CURL ERROR: $err\n";
    }

    $respHeaders = substr($response, 0, $headerSize);
    $respBody = substr($response, $headerSize);

    // Truncate huge bodies (e.g. the full web UI HTML page) so the probe
    // output stays readable.
    $bodyPreview = strlen($respBody) > 600 ? substr($respBody, 0, 600) . "\n...[truncated, " . strlen($respBody) . " bytes total]" : $respBody;

    // Just the first line of headers (status line) + content-type, not the
    // whole header block.
    $headerLines = explode("\r\n", trim($respHeaders));
    $statusLine = $headerLines[0] ?? '';
    $contentType = '';
    foreach ($headerLines as $hl) {
        if (stripos($hl, 'content-type:') === 0) { $contentType = trim($hl); break; }
    }

    return "HTTP $status | $statusLine | $contentType\nBody preview:\n$bodyPreview\n";
}

echo "=== ConnectWise Sell/CPQ probe -- " . date('Y-m-d H:i:s') . " ===\n";
echo "Config loaded: company_id=" . ($companyId ?: '(empty)') . " client_id=" . ($clientId ? substr($clientId, 0, 8) . '...' : '(empty)') . " public_key_len=" . strlen($publicKey) . " private_key_len=" . strlen($privateKey) . "\n\n";

foreach ($candidateUrls as $urlLabel => $url) {
    echo "########################################\n";
    echo "URL [$urlLabel]: $url\n";
    echo "########################################\n";
    foreach ($authVariants as $variantLabel => $headers) {
        echo "--- auth variant: $variantLabel ---\n";
        echo probeOne($url, $headers);
        echo "\n";
        flush();
    }
    echo "\n";
}

echo "=== probe complete ===\n";
echo "DELETE THIS FILE once you've captured the output above -- it's a diagnostic only, not part of the app.\n";
