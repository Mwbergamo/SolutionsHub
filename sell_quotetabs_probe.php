<?php
/**
 * sell_quotetabs_probe.php — TEMPORARY, READ-ONLY diagnostic.
 *
 * Follow-up to the second live write-test: the quote shell + rename/rep/customer PATCH all
 * worked, but POST /api/quoteItems 400'd: "Item can only be set to a tab on quote with quoteId
 * q639275416896529819wrawXEj" -- meaning the idQuoteTabs value the write path picked (from
 * GET /api/quoteTabs?idQuote={id}, a guessed query-param filter) actually belongs to a DIFFERENT
 * quote, not the new one. The ?idQuote= filter was never confirmed; this checks two real
 * candidates against the actual new quote (q639275416896529819wrawXEj, already created by that
 * failed attempt, still sitting in Sell as a test quote):
 *   1. GET /api/quoteTabs?idQuote={id}          -- what the write path tried (likely unfiltered)
 *   2. GET /api/quotes/{id}/quoteTabs            -- REST-nested, matching the shape already
 *      confirmed for .../customers and .../quoteTerms elsewhere in this API
 *
 * Read-only -- only issues GETs. Delete once captured.
 */

header('Content-Type: text/plain');
set_time_limit(30);

$cfg = require __DIR__ . '/connectwise-sell-config.php';
$authToken = base64_encode($cfg['access_key'] . '+' . $cfg['public_key'] . ':' . $cfg['private_key']);
$baseUrl = rtrim($cfg['base_url'], '/');
$quoteId = 'q639275416896529819wrawXEj';

function sellGet($baseUrl, $path, $authToken) {
    $ch = curl_init($baseUrl . $path);
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
    echo "GET $path\nHTTP $status\n";
    $decoded = json_decode($body, true);
    if (is_array($decoded)) {
        echo json_encode($decoded, JSON_PRETTY_PRINT) . "\n\n";
    } else {
        echo "Raw: $body\n\n";
    }
}

echo "=== Sell quoteTabs probe — " . date('Y-m-d H:i:s') . " ===\n\n";

echo "########## 1. GET /api/quoteTabs?idQuote={id} (what the write path tried) ##########\n";
sellGet($baseUrl, '/api/quoteTabs?idQuote=' . $quoteId, $authToken);

echo "########## 2. GET /api/quotes/{id}/quoteTabs (REST-nested guess) ##########\n";
sellGet($baseUrl, '/api/quotes/' . $quoteId . '/quoteTabs', $authToken);

echo "########## 3. For contrast: GET /api/quoteTabs with NO filter at all, pageSize=3 ##########\n";
sellGet($baseUrl, '/api/quoteTabs?pageSize=3', $authToken);

echo "DELETE THIS FILE once captured.\n";
