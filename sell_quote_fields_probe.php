<?php
/**
 * sell_quote_fields_probe.php — TEMPORARY, READ-ONLY diagnostic.
 *
 * Follow-up to the first live write-test of the Clyde -> Sell quote path: the quote shell itself
 * got created successfully (copyById + the rename/rep PATCH both worked), but the customer step
 * failed: PUT /api/quotes/{id}/customers returned HTTP 400 "The requested resource with API
 * version '2.0' does not support HTTP method 'PUT'." Rather than guess a fix, this fetches the
 * REAL test quote (id q639275414649164801ExKQXCM, already created by that test) and dumps every
 * top-level field whose name contains "account" or "contact" -- QuoteView is known to carry
 * plain-text accountName/contactName fields directly on the quote (confirmed in earlier reads),
 * separate from whatever the /customers sub-resource is for. If those plain fields are enough,
 * the customer step can fold into the SAME PATCH call that already works for rename/rep fields,
 * instead of the broken PUT .../customers call.
 *
 * Read-only -- only issues a GET. Delete once captured.
 */

header('Content-Type: text/plain');
set_time_limit(30);

$cfg = require __DIR__ . '/connectwise-sell-config.php';
$authToken = base64_encode($cfg['access_key'] . '+' . $cfg['public_key'] . ':' . $cfg['private_key']);
$baseUrl = rtrim($cfg['base_url'], '/');
$quoteId = 'q639275414649164801ExKQXCM';

$ch = curl_init($baseUrl . '/api/quotes/' . $quoteId);
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

echo "=== Sell quote fields probe — " . date('Y-m-d H:i:s') . " ===\nHTTP $status\n\n";
$q = json_decode($body, true);
if (!is_array($q)) {
    echo "Raw body:\n$body\n";
    exit;
}

echo "########## account/contact/customer -related fields on this real quote ##########\n";
foreach ($q as $k => $v) {
    if (preg_match('/account|contact|customer/i', $k)) {
        $val = is_array($v) ? json_encode($v) : (string) $v;
        echo "- $k = " . (strlen($val) > 200 ? substr($val, 0, 200) . '...' : $val) . "\n";
    }
}

echo "\n########## every top-level field name (so nothing is missed) ##########\n";
echo implode(', ', array_keys($q)) . "\n";

echo "\nDELETE THIS FILE once captured.\n";
