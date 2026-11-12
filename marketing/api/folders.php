<?php
declare(strict_types=1);

/**
 * marketing/api/folders.php
 *
 * Folder tree for the Marketing document hub. Every signed-in rep can
 * create/rename/remove folders (Michael, 2026-11-12: "All signed-in
 * reps" -- "we will routinely need folders, sub-folders... created and
 * removed for marketing"). "Removed" means moved to Trash (deleted_at
 * set), not gone -- see marketing/api/trash.php for restore/purge, per
 * Michael's "Recoverable Trash" answer.
 *
 * GET  ?action=list
 *   -> { ok, pillars: [{id,name}], folders: [ {id,pillar,parent_id,name,
 *        created_at,created_by_name}, ... ] }  -- every non-trashed
 *      folder, flat; the frontend builds the tree from parent_id.
 *
 * POST ?action=create   { parent_id, name }
 *   -> { ok, folder: {...} } -- parent_id must be an existing,
 *      non-trashed folder (a pillar's own root folder, seeded at
 *      install, or any folder under it). There's no bare "create a new
 *      pillar" action -- the 6 pillars (5 + General) are fixed.
 *
 * POST ?action=rename   { folder_id, name }
 *   -> { ok, folder: {...} } -- a pillar ROOT folder's name can't be
 *      changed (it's the pillar label itself).
 *
 * POST ?action=trash    { folder_id }
 *   -> { ok: true } -- soft-deletes this folder AND every folder/
 *      document nested under it (see marketing_folder_descendant_ids()).
 *      A pillar root folder can't be trashed.
 */

require_once __DIR__ . '/_util.php';

$pdo = marketing_db();
$user = marketing_require_login();

$action = $_GET['action'] ?? '';

if ($action === 'list') {
    $rows = $pdo->query('SELECT * FROM folders WHERE deleted_at IS NULL ORDER BY parent_id IS NOT NULL, name COLLATE NOCASE ASC')->fetchAll(PDO::FETCH_ASSOC);
    marketing_respond(200, [
        'ok' => true,
        'pillars' => marketing_pillars(),
        'folders' => array_map('marketing_folder_row', $rows),
    ]);
}

if ($action === 'create') {
    $body = marketing_read_json_body();
    $parentId = (int) ($body['parent_id'] ?? 0);
    $name = trim((string) ($body['name'] ?? ''));
    if ($parentId <= 0 || $name === '') {
        marketing_respond(400, ['ok' => false, 'error' => 'A parent folder and a name are required.']);
    }
    if (mb_strlen($name) > 120) {
        marketing_respond(400, ['ok' => false, 'error' => 'Folder name is too long (120 characters max).']);
    }
    $parent = marketing_require_folder($pdo, $parentId);

    $stmt = $pdo->prepare('INSERT INTO folders (pillar, parent_id, name, created_at, created_by_name, created_by_email) VALUES (:p, :pid, :n, :t, :cn, :ce)');
    $stmt->execute([
        ':p' => $parent['pillar'],
        ':pid' => $parentId,
        ':n' => $name,
        ':t' => gmdate('Y-m-d\TH:i:s\Z'),
        ':cn' => $user['name'],
        ':ce' => $user['email'],
    ]);
    $newId = (int) $pdo->lastInsertId();
    $row = $pdo->query('SELECT * FROM folders WHERE id = ' . $newId)->fetch(PDO::FETCH_ASSOC);
    marketing_respond(200, ['ok' => true, 'folder' => marketing_folder_row($row)]);
}

if ($action === 'rename') {
    $body = marketing_read_json_body();
    $folderId = (int) ($body['folder_id'] ?? 0);
    $name = trim((string) ($body['name'] ?? ''));
    if ($folderId <= 0 || $name === '') {
        marketing_respond(400, ['ok' => false, 'error' => 'A folder and a new name are required.']);
    }
    if (mb_strlen($name) > 120) {
        marketing_respond(400, ['ok' => false, 'error' => 'Folder name is too long (120 characters max).']);
    }
    $folder = marketing_require_folder($pdo, $folderId);
    if ($folder['parent_id'] === null) {
        marketing_respond(400, ['ok' => false, 'error' => 'A pillar\'s top-level folder can\'t be renamed.']);
    }
    $pdo->prepare('UPDATE folders SET name = :n WHERE id = :id')->execute([':n' => $name, ':id' => $folderId]);
    $row = $pdo->query('SELECT * FROM folders WHERE id = ' . $folderId)->fetch(PDO::FETCH_ASSOC);
    marketing_respond(200, ['ok' => true, 'folder' => marketing_folder_row($row)]);
}

if ($action === 'trash') {
    $body = marketing_read_json_body();
    $folderId = (int) ($body['folder_id'] ?? 0);
    if ($folderId <= 0) {
        marketing_respond(400, ['ok' => false, 'error' => 'Missing folder_id.']);
    }
    $folder = marketing_require_folder($pdo, $folderId);
    if ($folder['parent_id'] === null) {
        marketing_respond(400, ['ok' => false, 'error' => 'A pillar\'s top-level folder can\'t be removed.']);
    }

    $ids = array_merge([$folderId], marketing_folder_descendant_ids($pdo, $folderId));
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $now = gmdate('Y-m-d\TH:i:s\Z');

    $pdo->beginTransaction();
    $pdo->prepare("UPDATE folders SET deleted_at = ?, deleted_by_name = ?, deleted_by_email = ? WHERE id IN ($placeholders) AND deleted_at IS NULL")
        ->execute(array_merge([$now, $user['name'], $user['email']], $ids));
    $pdo->prepare("UPDATE documents SET deleted_at = ?, deleted_by_name = ?, deleted_by_email = ? WHERE folder_id IN ($placeholders) AND deleted_at IS NULL")
        ->execute(array_merge([$now, $user['name'], $user['email']], $ids));
    $pdo->commit();

    marketing_respond(200, ['ok' => true]);
}

marketing_respond(400, ['ok' => false, 'error' => 'Unknown action.']);
