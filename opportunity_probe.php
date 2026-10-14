<?php
/**
 * opportunity_probe.php — TEMPORARY, READ-ONLY diagnostic.
 *
 * Confirms the real shape of ConnectWise Manage's Opportunity object
 * (/sales/opportunities) before any Opportunity-CREATE code gets written
 * for the Solutions Hub -> ConnectWise Sell quote integration (see
 * solutions-hub-cpq-quote-integration.md in the project docs). This
 * codebase has never touched /sales/opportunities in either direction --
 * every existing ConnectWise Manage integration here (relationships/,
 * register/) only reads/writes Companies/Contacts/Activities/Tickets.
 *
 * Reuses relationships/api/connectwise.php's already-proven Manage REST
 * client and connectwise-config.php credentials -- no new config needed.
 *
 * This script ONLY issues GET requests. It never POSTs, PUTs, PATCHes, or
 * DELETEs anything -- nothing in ConnectWise is created, changed, or
 * removed by running this. Delete it once the output below is captured.
 *
 * Usage: load this file in a browser on the live server (same folder as
 * relationships/, i.e. the SolutionsHub repo root) while signed in isn't
 * required -- it talks to ConnectWise directly with the API Member's own
 * credentials, same as sell_probe.php/sell_probe2.php did for Sell.
 */

header('Content-Type: text/plain');
set_time_limit(60);

require_once __DIR__ . '/relationships/api/connectwise.php';

function printJson($label, $value): void
{
    echo "$label:\n" . json_encode($value, JSON_PRETTY_PRINT) . "\n\n";
}

echo "=== ConnectWise Manage Opportunity probe — " . date('Y-m-d H:i:s') . " ===\n\n";

echo "########## 1. Recent Opportunities (list, a few fields each) ##########\n";
try {
    $list = relationships_cw_request('/sales/opportunities', [
        'pageSize' => 5,
        'orderBy' => 'id desc',
    ]);
    foreach ($list as $o) {
        $id = $o['id'] ?? '?';
        $name = $o['name'] ?? '(no name)';
        $company = $o['company']['name'] ?? '(no company)';
        $status = $o['status']['name'] ?? '?';
        $stage = $o['stage']['name'] ?? '?';
        $type = $o['type']['name'] ?? '?';
        $primaryRep = $o['primarySalesRep']['name'] ?? '?';
        $expectedClose = $o['expectedCloseDate'] ?? '?';
        echo "- id=$id  \"$name\"  company=\"$company\"  status=$status  stage=$stage  type=$type  primaryRep=$primaryRep  expectedClose=$expectedClose\n";
    }
    echo "\nTotal returned: " . count($list) . "\n";
} catch (Throwable $e) {
    echo "FAILED: " . $e->getMessage() . "\n";
    $list = [];
}

echo "\n########## 2. One full Opportunity record, untruncated ##########\n";
if (!empty($list[0]['id'])) {
    try {
        $full = relationships_cw_request('/sales/opportunities/' . (int) $list[0]['id']);
        printJson('Full record', $full);
    } catch (Throwable $e) {
        echo "FAILED: " . $e->getMessage() . "\n";
    }
} else {
    echo "SKIPPED — no Opportunity id from step 1 to fetch.\n";
}

echo "\n########## 3. Opportunity reference lists (statuses / types / stages) ##########\n";
foreach (['/sales/opportunities/statuses', '/sales/opportunities/types', '/sales/opportunities/stages'] as $path) {
    echo "--- $path ---\n";
    try {
        $ref = relationships_cw_request($path, ['pageSize' => 50]);
        foreach ($ref as $r) {
            $id = $r['id'] ?? '?';
            $name = $r['name'] ?? ($r['description'] ?? '?');
            $extra = [];
            foreach (['defaultFlag', 'closedFlag', 'wonFlag', 'inactiveFlag'] as $flagKey) {
                if (array_key_exists($flagKey, $r)) {
                    $extra[] = "$flagKey=" . ($r[$flagKey] ? 'true' : 'false');
                }
            }
            echo "  - id=$id  \"$name\"" . ($extra ? '  ' . implode(' ', $extra) : '') . "\n";
        }
    } catch (Throwable $e) {
        echo "  FAILED: " . $e->getMessage() . "\n";
    }
    echo "\n";
}

echo "\n########## 4. A real Opportunity tied to a Company with real Contacts (for the 'who's the contact' question) ##########\n";
if (!empty($list[0]['company']['id'])) {
    try {
        $companyId = (int) $list[0]['company']['id'];
        $withCompany = relationships_cw_request('/sales/opportunities', [
            'conditions' => "company/id=$companyId",
            'pageSize' => 3,
        ]);
        printJson("Opportunities for company id=$companyId", $withCompany);
    } catch (Throwable $e) {
        echo "FAILED: " . $e->getMessage() . "\n";
    }
} else {
    echo "SKIPPED — no company id available from step 1.\n";
}

echo "\n=== probe complete ===\n";
echo "DELETE THIS FILE once you've captured the output above.\n";
