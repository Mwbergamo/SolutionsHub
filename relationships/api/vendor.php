<?php
/**
 * relationships/api/vendor.php
 *
 * "Current vendor if not CodeBlue" -- added 2026-09-15 per Michael's
 * Customer Meeting Capture request. One editable free-text note PER PILLAR
 * (confirmed via AskUserQuestion 2026-09-15 -- not per individual service)
 * naming who a customer uses instead of CodeBlue for that pillar, shown
 * above that pillar's tile on the dashboard. Single current value + who/
 * when it was last updated -- no history log (Michael only asked to "see
 * the last time it was updated," unlike OutGrow Last Touch's full history).
 * Purely local -- no ConnectWise sync, not requested for this field.
 *
 * GET  /relationships/api/vendor.php?action=list&customer_id=1
 *   -> { ok: true, notes: { "<pillar_id>": { vendor_name, updated_at, updated_by_name } | null, ... } }
 *   One entry per pillar in the catalog (relationships_catalog()), always
 *   present even when nothing has been recorded yet (null in that case) --
 *   so the UI never has to guess which pillars exist.
 *
 * POST /relationships/api/vendor.php?action=set
 *   { customer_id, pillar_id, vendor_name }
 *   -> { ok: true, note: { vendor_name, updated_at, updated_by_name } }
 *   vendor_name may be blank (clears the field back to "not recorded") --
 *   still counts as an edit and still stamps updated_at/by, same as any
 *   other save.
 */

declare(strict_types=1);

require_once __DIR__ . '/_util.php';

$pdo = relationships_db();
$user = relationships_require_login($pdo);

$action = $_GET['action'] ?? '';

function relationships_vendor_notes(PDO $pdo, int $customerId): array
{
    $stmt = $pdo->prepare(
        'SELECT pillar_id, vendor_name, updated_at, updated_by_name FROM pillar_vendor_notes WHERE customer_id = :id'
    );
    $stmt->execute([':id' => $customerId]);
    $byPillar = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $byPillar[$r['pillar_id']] = [
            'vendor_name' => $r['vendor_name'],
            'updated_at' => $r['updated_at'],
            'updated_by_name' => $r['updated_by_name'],
        ];
    }

    $notes = [];
    foreach (array_keys(relationships_catalog()) as $pillarId) {
        $notes[$pillarId] = $byPillar[$pillarId] ?? null;
    }
    return $notes;
}

if ($action === 'list') {
    $customerId = (int) ($_GET['customer_id'] ?? 0);
    if ($customerId <= 0) {
        relationships_respond(400, ['ok' => false, 'error' => 'Missing customer_id.']);
    }
    relationships_respond(200, ['ok' => true, 'notes' => relationships_vendor_notes($pdo, $customerId)]);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    relationships_respond(405, ['ok' => false, 'error' => 'Method not allowed.']);
}

if ($action === 'set') {
    $data = relationships_read_json_body();
    $customerId = (int) ($data['customer_id'] ?? 0);
    $pillarId = (string) ($data['pillar_id'] ?? '');
    $vendorName = trim((string) ($data['vendor_name'] ?? ''));

    $catalog = relationships_catalog();
    if ($customerId <= 0 || !isset($catalog[$pillarId])) {
        relationships_respond(400, ['ok' => false, 'error' => 'Missing/invalid customer_id or pillar_id.']);
    }
    if (mb_strlen($vendorName) > 200) {
        relationships_respond(400, ['ok' => false, 'error' => 'Vendor name is too long (200 characters max).']);
    }

    $custStmt = $pdo->prepare('SELECT id FROM customers WHERE id = :id');
    $custStmt->execute([':id' => $customerId]);
    if ($custStmt->fetch(PDO::FETCH_ASSOC) === false) {
        relationships_respond(404, ['ok' => false, 'error' => 'Customer not found.']);
    }

    // Delete-then-insert upsert -- same pattern checklist_progress and
    // outgrow use elsewhere in this app; UNIQUE(customer_id, pillar_id)
    // guarantees at most one row either way.
    $pdo->prepare('DELETE FROM pillar_vendor_notes WHERE customer_id = :cid AND pillar_id = :pid')
        ->execute([':cid' => $customerId, ':pid' => $pillarId]);
    $pdo->prepare(
        "INSERT INTO pillar_vendor_notes (customer_id, pillar_id, vendor_name, updated_at, updated_by_user_id, updated_by_name)
         VALUES (:cid, :pid, :vname, datetime('now'), :uid, :uname)"
    )->execute([
        ':cid' => $customerId, ':pid' => $pillarId, ':vname' => $vendorName,
        ':uid' => $user['id'], ':uname' => $user['name'],
    ]);

    $notes = relationships_vendor_notes($pdo, $customerId);
    relationships_respond(200, ['ok' => true, 'note' => $notes[$pillarId]]);
}

relationships_respond(400, ['ok' => false, 'error' => 'Unknown action.']);
