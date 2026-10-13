<?php
/**
 * relationships/api/duplicates-admin.php
 *
 * Duplicate Customers screen backend (2026-10-13). Admin-only, same gate as
 * territory-admin.php. See duplicates-core.php for what "hide" does.
 *
 * GET  ?action=list
 *   -> { ok, groups: [{key,name,suggested_id,customers:[{id,name,connectwise_id,
 *        status,bucket,territory,services,contacts,recent_billing,tickets_ytd,
 *        meetings,last_outgrow_touch}]}], hidden: [{customer_id,name,
 *        connectwise_id,keeper_customer_id,keeper_name,...}] }
 * POST ?action=keep     { keeper_id, hide_ids: [id,...] }
 * POST ?action=dismiss  { key }          -- "these are different companies"
 * POST ?action=restore  { customer_id }  -- un-hide
 */

declare(strict_types=1);

require_once __DIR__ . '/_util.php';
require_once __DIR__ . '/territory-access.php';
require_once __DIR__ . '/duplicates-core.php';

$pdo = relationships_db();
$user = relationships_require_login($pdo);

if (!relationships_current_user_is_territory_admin($pdo)) {
    relationships_respond(403, ['ok' => false, 'error' => 'You do not have access to duplicate customer management.']);
}

$action = $_GET['action'] ?? '';

if ($action === 'list') {
    relationships_respond(200, ['ok' => true] + relationships_dup_list($pdo));
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    relationships_respond(405, ['ok' => false, 'error' => 'Method not allowed.']);
}

$by = (string) ($user['email'] ?? $user['name'] ?? '');
$data = relationships_read_json_body();

if ($action === 'keep') {
    try {
        $res = relationships_dup_keep($pdo, (int) ($data['keeper_id'] ?? 0), (array) ($data['hide_ids'] ?? []), $by);
    } catch (InvalidArgumentException $e) {
        relationships_respond(400, ['ok' => false, 'error' => $e->getMessage()]);
    }
    relationships_respond(200, ['ok' => true] + $res);
}

if ($action === 'dismiss') {
    $key = trim((string) ($data['key'] ?? ''));
    if ($key === '') {
        relationships_respond(400, ['ok' => false, 'error' => 'Missing group.']);
    }
    $pdo->prepare('INSERT OR IGNORE INTO customer_duplicate_dismissals (group_key, dismissed_by) VALUES (:k, :by)')
        ->execute([':k' => $key, ':by' => $by]);
    relationships_respond(200, ['ok' => true]);
}

if ($action === 'restore') {
    $ok = relationships_dup_restore($pdo, (int) ($data['customer_id'] ?? 0));
    relationships_respond($ok ? 200 : 404, $ok ? ['ok' => true] : ['ok' => false, 'error' => 'That customer is not hidden.']);
}

relationships_respond(400, ['ok' => false, 'error' => 'Unknown action.']);
