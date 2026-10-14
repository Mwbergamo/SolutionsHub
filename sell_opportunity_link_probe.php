<?php
/**
 * sell_opportunity_link_probe.php — TEMPORARY, READ-ONLY diagnostic.
 *
 * Michael confirmed CodeBlue already has SOME existing mechanism where
 * ConnectWise Sell links its own quotes to Manage Opportunities on its
 * own (real Opportunities sampled earlier showed source="Connectwise
 * Sell" / updatedBy="Sell"). Before finalizing the Solutions Hub -> Sell
 * integration's "always create a brand-new Opportunity ourselves" design
 * (see solutions-hub-cpq-quote-integration.md in the project docs), this
 * probe checks how that existing link actually works:
 *
 *   1. Does Michael's own real "Test Sell Quote" already carry a
 *      crmOpportunityId? (If a quote gets one automatically/by default,
 *      our own write path may not need to create the Opportunity itself
 *      at all -- it may just need to read back whatever Sell already set.)
 *   2. Across a page of recent real quotes, how many have a non-empty
 *      crmOpportunityId, and do template/non-template quotes differ?
 *   3. If a crmOpportunityId is found, look that Opportunity up in Manage
 *      directly to see its real source/updatedBy/dateBecameLead -- i.e.
 *      was it created automatically by Sell, or does it only get set when
 *      a human manually links the two?
 *
 * Reuses the exact same auth code as sell_probe.php/sell_probe2.php (Sell)
 * and relationships/api/connectwise.php (Manage) -- no new config needed.
 *
 * This script ONLY issues GET requests. It never POSTs, PUTs, PATCHes, or
 * DELETEs anything -- nothing in Sell or Manage is created, changed, or
 * removed by running this. Delete it once the output below is captured.
 */

header('Content-Type: text/plain');
set_time_limit(60);

require_once __DIR__ . '/relationships/api/connectwise.php'; // Manage client

$configPath = __DIR__ . '/connectwise-sell-config.php';
if (!file_exists($configPath)) {
    die("ERROR: connectwise-sell-config.php not found at $configPath\n");
}
$cfg = require $configPath;

$accessKey = $cfg['access_key'] ?? '';
$publicKey = $cfg['public_key'] ?? '';
$privateKey = $cfg['private_key'] ?? '';
$baseUrl = rtrim($cfg['base_url'] ?? 'https://sellapi.quosalsell.com', '/');
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
    if ($err) {
        return ['error' => $err, 'status' => $status];
    }
    $decoded = json_decode($body, true);
    return ['status' => $status, 'body' => $decoded, 'raw' => $body];
}

echo "=== Sell <-> Manage Opportunity link probe — " . date('Y-m-d H:i:s') . " ===\n\n";

// Michael's real "Test Sell Quote", from the quote.dashboard URL he pasted
// earlier: idquotemain=q639275379732147962lFdRAxQ.
$testQuoteId = 'q639275379732147962lFdRAxQ';

echo "########## 1. Michael's test quote — does it already have a crmOpportunityId? ##########\n";
$r = cpqGet($baseUrl, "/api/quotes/$testQuoteId", $authToken);
$testQuote = null;
if (($r['status'] ?? 0) === 200 && is_array($r['body'])) {
    $testQuote = $r['body'];
    echo "quoteNumber: " . ($testQuote['quoteNumber'] ?? '?') . "\n";
    echo "isTemplate: " . (($testQuote['isTemplate'] ?? false) ? 'true' : 'false') . "\n";
    echo "crmOpportunityId: '" . ($testQuote['crmOpportunityId'] ?? '') . "'\n";
    echo "accountName: " . ($testQuote['accountName'] ?? '?') . "\n";
    echo "contactName: " . ($testQuote['contactName'] ?? '?') . "\n";
    echo "dateCreated: " . ($testQuote['dateCreated'] ?? ($testQuote['dateEntered'] ?? '?')) . "\n";
} else {
    echo "FAILED: HTTP " . ($r['status'] ?? '?') . "\n" . ($r['raw'] ?? $r['error'] ?? '') . "\n";
}

echo "\n########## 2. Recent real quotes — how many already carry a crmOpportunityId? ##########\n";
$r = cpqGet($baseUrl, '/api/quotes?pageSize=20&orderBy=dateCreated%20desc', $authToken);
$firstNonEmptyOppId = null;
if (($r['status'] ?? 0) === 200 && is_array($r['body'])) {
    foreach ($r['body'] as $q) {
        $oppId = $q['crmOpportunityId'] ?? '';
        $isTemplate = ($q['isTemplate'] ?? false) ? 'true' : 'false';
        echo "- quoteNumber=" . ($q['quoteNumber'] ?? '?') . "  isTemplate=$isTemplate  crmOpportunityId='$oppId'\n";
        if ($oppId !== '' && $firstNonEmptyOppId === null) {
            $firstNonEmptyOppId = $oppId;
        }
    }
    echo "\nTotal returned: " . count($r['body']) . "\n";
} else {
    echo "FAILED: HTTP " . ($r['status'] ?? '?') . "\n" . ($r['raw'] ?? $r['error'] ?? '') . "\n";
}

echo "\n########## 3. Look up a real crmOpportunityId in Manage, to see how it got there ##########\n";
$oppIdToCheck = $testQuote['crmOpportunityId'] ?? $firstNonEmptyOppId ?? null;
if (!empty($oppIdToCheck)) {
    echo "Checking Manage Opportunity id=$oppIdToCheck ...\n";
    try {
        $opp = relationships_cw_request('/sales/opportunities/' . (int) $oppIdToCheck);
        echo "name: \"" . ($opp['name'] ?? '?') . "\"\n";
        echo "source: " . ($opp['source'] ?? '(none)') . "\n";
        echo "status: " . ($opp['status']['name'] ?? '?') . "\n";
        echo "dateBecameLead: " . ($opp['dateBecameLead'] ?? '?') . "\n";
        echo "_info: " . json_encode($opp['_info'] ?? [], JSON_PRETTY_PRINT) . "\n";
    } catch (Throwable $e) {
        echo "FAILED: " . $e->getMessage() . "\n";
    }
} else {
    echo "SKIPPED — no crmOpportunityId found on the test quote or the recent-quotes page.\n";
    echo "(This itself is informative: it would mean crmOpportunityId is NOT auto-populated by\n";
    echo "default, and whatever Sell->Manage sync produced the earlier 'source: Connectwise Sell'\n";
    echo "Opportunity happens through some other path -- e.g. only when a quote reaches a certain\n";
    echo "status, or only for quotes created through the Sell UI's own 'New Opportunity' flow.)\n";
}

echo "\n=== probe complete ===\n";
echo "DELETE THIS FILE once you've captured the output above.\n";
