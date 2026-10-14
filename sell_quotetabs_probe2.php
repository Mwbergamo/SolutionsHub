<?php
/**
 * sell_quotetabs_probe2.php — TEMPORARY, READ-ONLY diagnostic. Follow-up to
 * sell_quotetabs_probe.php: that probe showed GET /api/quoteTabs?idQuote={id} silently IGNORES
 * the idQuote param (it returned ~51 tabs, none belonging to our target quote -- confirmed by
 * grepping the dump for the target quote's id and finding zero matches), while GET
 * /api/quotes/{id}/quoteTabs 404s, and plain pageSize IS honored (so the endpoint takes real
 * query params, idQuote just isn't a recognized one). Each tab object embeds its owning quote as
 * a nested object -- "quote": {"id": "..."} -- not a flat "idQuote"/"quoteId" field, which is
 * probably why that guess didn't filter anything.
 *
 * This tries several different candidate ways to find OUR quote's own tabs, against the SAME real
 * quote from the last two failed write-tests (q639275416896529819wrawXEj, still sitting in Sell):
 *   1. orderBy=id desc, pageSize=10 -- newest tabs first, no filter guess needed at all
 *   2. conditions=quote/id="{id}"   -- ConnectWise-Manage-style condition syntax, in case Sell's
 *                                      API borrows it
 *   3. quote.id={id}                -- dotted-path flat filter guess
 *
 * Read-only -- only issues GETs. Delete once captured.
 */

header('Content-Type: text/plain');
set_time_limit(30);

$cfg = require __DIR__ . '/connectwise-sell-config.php';
$authToken = base64_encode($cfg['access_key'] . '+' . $cfg['public_key'] . ':' . $cfg['private_key']);
$baseUrl = rtrim($cfg['base_url'], '/');
$quoteId = 'q639275416896529819wrawXEj';

function sellGet($baseUrl, $path, $authToken, $targetQuoteId) {
    $ch = curl_init($baseUrl . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/json; version=1.0',
            'Authorization: Basic ' . $authToken,
        ],
        CURLOPT_TIMEOUT => 20,
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    echo "GET $path\nHTTP $status\n";
    $decoded = json_decode($body, true);
    if (is_array($decoded)) {
        echo "Count returned: " . count($decoded) . "\n";
        foreach ($decoded as $i => $tab) {
            $owningQuoteId = $tab['quote']['id'] ?? '?';
            $match = ($owningQuoteId === $targetQuoteId) ? '  <== MATCHES OUR TARGET QUOTE' : '';
            echo "  [$i] tab id=" . ($tab['id'] ?? '?') . "  name=" . ($tab['name'] ?? '?') . "  owning quote.id=$owningQuoteId$match\n";
        }
    } else {
        echo "Raw (first 500 chars): " . substr((string) $body, 0, 500) . "\n";
    }
    echo "\n";
}

echo "=== Sell quoteTabs probe #2 — " . date('Y-m-d H:i:s') . " ===\nTarget quote: $quoteId\n\n";

echo "########## 1. orderBy=id desc, pageSize=10 (newest tabs first) ##########\n";
sellGet($baseUrl, '/api/quoteTabs?orderBy=id%20desc&pageSize=10', $authToken, $quoteId);

echo "########## 2. conditions=quote/id=\"{id}\" (Manage-style condition) ##########\n";
sellGet($baseUrl, '/api/quoteTabs?conditions=' . rawurlencode('quote/id="' . $quoteId . '"'), $authToken, $quoteId);

echo "########## 3. quote.id={id} (dotted flat filter guess) ##########\n";
sellGet($baseUrl, '/api/quoteTabs?' . http_build_query(['quote.id' => $quoteId]), $authToken, $quoteId);

echo "DELETE THIS FILE once captured.\n";
