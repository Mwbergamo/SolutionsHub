<?php
/**
 * sell_quote_detail_probe.php — TEMPORARY, READ-ONLY diagnostic.
 *
 * Follow-up to the third live write-test: the quote now gets created with line items landing on
 * the right tabs (the quoteTabs fix worked), but two new problems showed up:
 *   1. No company/contact got assigned, even though the write path PATCHes accountName/contactName.
 *      The earlier field-dump probe (sell_quote_fields_probe.php) only grepped field names for
 *      "account|contact|customer" -- that regex would NOT have caught a field named e.g.
 *      "company"/"idCompany", so there may be a real company-link field we never saw. This also
 *      does a GET (never tried before -- only PUT, which 400'd) on the /customers sub-resource,
 *      since its actual shape might show the right way to link a company/contact.
 *   2. The new line items are plain custom/ad-hoc items, not real catalog parts. Michael says the
 *      template's tabs likely already have real catalog products pre-loaded that should have
 *      their quantities updated instead of us inserting new custom lines. This dumps the existing
 *      quoteItems on the target (via the now-confirmed working conditions=quote/id="{id}" filter)
 *      to see their real shape -- catalog/part ids, SKUs, qty fields -- so the write path can be
 *      changed to update existing items instead of creating new ones.
 *
 * Usage: sell_quote_detail_probe.php?id={quoteId}
 *   Defaults to the PeopleFirst Support Plan TEMPLATE itself (q638470587029567633CoVvNoC) if no
 *   id is given, so Michael can also just paste a specific quote's id from the URL bar in Sell
 *   without needing a new probe deployed each time.
 *
 * Read-only -- only issues GETs. Delete once captured.
 */

header('Content-Type: text/plain');
set_time_limit(30);

$cfg = require __DIR__ . '/connectwise-sell-config.php';
$authToken = base64_encode($cfg['access_key'] . '+' . $cfg['public_key'] . ':' . $cfg['private_key']);
$baseUrl = rtrim($cfg['base_url'], '/');

$quoteId = isset($_GET['id']) && $_GET['id'] !== '' ? (string) $_GET['id'] : 'q638470587029567633CoVvNoC';

function sellGet($baseUrl, $path, $authToken) {
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
    return [$status, $decoded, $body];
}

echo "=== Sell quote detail probe — " . date('Y-m-d H:i:s') . " ===\nTarget: $quoteId\n\n";

echo "########## 1. Full quote object -- every field matching company/account/contact/customer ##########\n";
list($status, $q, $raw) = sellGet($baseUrl, '/api/quotes/' . rawurlencode($quoteId), $authToken);
if (is_array($q)) {
    foreach ($q as $k => $v) {
        if (preg_match('/company|account|contact|customer/i', $k)) {
            $val = is_array($v) ? json_encode($v) : (string) $v;
            echo "- $k = " . (strlen($val) > 300 ? substr($val, 0, 300) . '...' : $val) . "\n";
        }
    }
    echo "\n(all top-level field names, for anything the regex above might have missed)\n";
    echo implode(', ', array_keys($q)) . "\n";
} else {
    echo "Raw (first 500 chars): " . substr((string) $raw, 0, 500) . "\n";
}
echo "\n";

echo "########## 2. GET /api/quotes/{id}/customers (never tried -- only PUT was tried before, and 400'd) ##########\n";
list($status, $customers, $raw) = sellGet($baseUrl, '/api/quotes/' . rawurlencode($quoteId) . '/customers', $authToken);
if (is_array($customers)) {
    echo json_encode($customers, JSON_PRETTY_PRINT) . "\n";
} else {
    echo "Raw (first 1000 chars): " . substr((string) $raw, 0, 1000) . "\n";
}
echo "\n";

echo "########## 3. Existing quoteItems on this quote (conditions=quote/id=\"{id}\", now confirmed working) ##########\n";
list($status, $items, $raw) = sellGet(
    $baseUrl,
    '/api/quoteItems?conditions=' . rawurlencode('quote/id="' . $quoteId . '"'),
    $authToken
);
if (is_array($items)) {
    echo "Count: " . count($items) . "\n\n";
    foreach ($items as $i => $item) {
        $desc = $item['description'] ?? $item['name'] ?? '?';
        $tabId = $item['idQuoteTabs'] ?? ($item['quoteTabs']['id'] ?? '?');
        echo "--- item [$i]: \"$desc\" (tab id=$tabId) ---\n";
        echo json_encode($item, JSON_PRETTY_PRINT) . "\n\n";
    }
    if (count($items) === 0) {
        echo "(no items found on this quote/template via this filter)\n";
    }
} else {
    echo "Raw (first 1000 chars): " . substr((string) $raw, 0, 1000) . "\n";
}

echo "\nDELETE THIS FILE once captured.\n";
