<?php
/**
 * sell_probe2.php — TEMPORARY, READ-ONLY diagnostic, follow-up to sell_probe.php.
 *
 * Confirmed working auth scheme (2026-10-14) -- this probe digs into two
 * specific open questions before any write code gets written:
 *   1. A compact list of every template's name + id, so Michael can point
 *      at the right one for PeopleFirst support quotes.
 *   2. The full /settings/user record for Michael's own account (matched by
 *      email), to see what id format a quote's insideRep/preparedBy fields
 *      actually expect (the id shown on /settings/user doesn't match the
 *      id format seen in a real quote's insideRep field -- e.g.
 *      "q636924186913080286lVFzZfv" vs a plain hex GUID).
 *   3. One full real quote's COMPLETE JSON (not truncated), to see every
 *      field actually populated on a real quote -- including whether
 *      crmOpportunityId or similar actually gets set when a rep manually
 *      ties a quote to a Manage opportunity today.
 *
 * Read-only. Nothing created, changed, or deleted. Delete this file once
 * its output is captured.
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
    if ($err) { return ['error' => $err, 'status' => $status]; }
    $decoded = json_decode($body, true);
    return ['status' => $status, 'body' => $decoded, 'raw' => $body];
}

echo "=== ConnectWise CPQ follow-up probe -- " . date('Y-m-d H:i:s') . " ===\n\n";

// 1. Compact template list.
echo "########## 1. Templates (name + id only) ##########\n";
$r = cpqGet($baseUrl, '/api/templates', $authToken);
if (($r['status'] ?? 0) === 200 && is_array($r['body'])) {
    foreach ($r['body'] as $t) {
        $name = $t['name'] ?? '(no name)';
        $id = $t['id'] ?? '(no id)';
        $qn = $t['quoteNumber'] ?? '?';
        echo "- \"$name\"  quoteNumber=$qn  id=$id\n";
    }
    echo "\nTotal templates: " . count($r['body']) . "\n";
} else {
    echo "FAILED: HTTP " . ($r['status'] ?? '?') . "\n" . ($r['raw'] ?? $r['error'] ?? '') . "\n";
}

// 2. Full /settings/user list, but trimmed to just id/userName/name/email
// for every user, plus flag the full raw record for the one matching
// Michael's own email.
echo "\n########## 2. CPQ users (id + name + email), full record for Michael's own ##########\n";
$r = cpqGet($baseUrl, '/settings/user', $authToken);
if (($r['status'] ?? 0) === 200 && is_array($r['body'])) {
    foreach ($r['body'] as $u) {
        $email = $u['emailAddress'] ?? '';
        $line = "- id=" . ($u['id'] ?? '?') . "  userName=" . ($u['userName'] ?? '?') . "  name=" . ($u['presentedName'] ?? '?') . "  email=$email";
        echo "$line\n";
        if (stripos($email, 'mbergamo') !== false || stripos($email, 'michael') !== false) {
            echo "  ^^ FULL RECORD:\n" . json_encode($u, JSON_PRETTY_PRINT) . "\n";
        }
    }
} else {
    echo "FAILED: HTTP " . ($r['status'] ?? '?') . "\n" . ($r['raw'] ?? $r['error'] ?? '') . "\n";
}

// 3. One full, untruncated real quote.
echo "\n########## 3. One full quote, untruncated ##########\n";
$r = cpqGet($baseUrl, '/api/quotes?pageSize=1', $authToken);
if (($r['status'] ?? 0) === 200 && is_array($r['body']) && count($r['body']) > 0) {
    echo json_encode($r['body'][0], JSON_PRETTY_PRINT) . "\n";
} else {
    echo "FAILED: HTTP " . ($r['status'] ?? '?') . "\n" . ($r['raw'] ?? $r['error'] ?? '') . "\n";
}

echo "\n=== probe complete ===\n";
echo "DELETE THIS FILE (and sell_probe.php, if you haven't already) once you've captured the output above.\n";
