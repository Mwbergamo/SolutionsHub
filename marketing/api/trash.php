<?php
declare(strict_types=1);

/**
 * marketing/api/trash.php
 *
 * Recoverable Trash for the Marketing document hub (Michael, 2026-11-12:
 * "Recoverable Trash... protects against an accidental click given how
 * routinely you'll be adding/removing things"). Removing a folder or
 * document just sets deleted_at (see folders.php's `trash` action and
 * documents.php's); this file lists what's in Trash and lets anyone
 * restore or permanently purge an item.
 *
 * GET  ?action=list
 *   -> { ok, folders: [...], documents: [...] } -- every trashed folder/
 *      document, newest-removed first. A trashed document whose folder
 *      was ALSO trashed in the same sweep (folders.php's cascading
 *      `trash`) is still listed here individually -- restoring it alone
 *      (without its folder) would just leave it invisible again until
 *      the folder's restored too, which restore_folder below accounts
 *      for by cascading the same way trash did.
 *
 * POST ?action=restore_folder     { folder_id }
 *   -> { ok: true } -- un-trashes this folder AND every folder/document
 *      nested under it.
 *
 * POST ?action=restore_document   { document_id }
 *   -> { ok: true }
 *
 * POST ?action=purge_folder       { folder_id }
 *   -> { ok: true } -- PERMANENT: deletes this folder, every folder/
 *      document nested under it, and their files on disk. Folder must
 *      already be trashed.
 *
 * POST ?action=purge_document     { document_id }
 *   -> { ok: true } -- PERMANENT: deletes the row and its file on disk.
 *      Document must already be trashed.
 */

require_once __DIR__ . '/_util.php';
require_once __DIR__ . '/documents-core.php';

$pdo = marketing_db();
$user = marketing_require_login();

$action = $_GET['action'] ?? '';

if ($action === 'list') {
    $folders = $pdo->query('SELECT * FROM folders WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC')->fetchAll(PDO::FETCH_ASSOC);
    $documents = $pdo->query('SELECT * FROM documents WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC')->fetchAll(PDO::FETCH_ASSOC);

    $folderRows = array_map(static function (array $r): array {
        return marketing_folder_row($r) + ['deleted_at' => $r['deleted_at'], 'deleted_by_name' => $r['deleted_by_name']];
    }, $folders);
    $documentRows = array_map(static function (array $r): array {
        return marketing_document_row($r) + ['deleted_at' => $r['deleted_at'], 'deleted_by_name' => $r['deleted_by_name']];
    }, $documents);

    marketing_respond(200, ['ok' => true, 'folders' => $folderRows, 'documents' => $documentRows]);
}

if ($action === 'restore_folder') {
    $body = marketing_read_json_body();
    $folderId = (int) ($body['folder_id'] ?? 0);
    if ($folderId <= 0) {
        marketing_respond(400, ['ok' => false, 'error' => 'Missing folder_id.']);
    }
    $ids = array_merge([$folderId], marketing_folder_descendant_ids($pdo, $folderId));
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $pdo->beginTransaction();
    $pdo->prepare("UPDATE folders SET deleted_at = NULL, deleted_by_name = NULL, deleted_by_email = NULL WHERE id IN ($placeholders)")->execute($ids);
    $pdo->prepare("UPDATE documents SET deleted_at = NULL, deleted_by_name = NULL, deleted_by_email = NULL WHERE folder_id IN ($placeholders)")->execute($ids);
    $pdo->commit();
    marketing_respond(200, ['ok' => true]);
}

if ($action === 'restore_document') {
    $body = marketing_read_json_body();
    $documentId = (int) ($body['document_id'] ?? 0);
    if ($documentId <= 0) {
        marketing_respond(400, ['ok' => false, 'error' => 'Missing document_id.']);
    }
    $pdo->prepare('UPDATE documents SET deleted_at = NULL, deleted_by_name = NULL, deleted_by_email = NULL WHERE id = :id')->execute([':id' => $documentId]);
    marketing_respond(200, ['ok' => true]);
}

if ($action === 'purge_folder') {
    $body = marketing_read_json_body();
    $folderId = (int) ($body['folder_id'] ?? 0);
    if ($folderId <= 0) {
        marketing_respond(400, ['ok' => false, 'error' => 'Missing folder_id.']);
    }
    $stmt = $pdo->prepare('SELECT * FROM folders WHERE id = :id AND deleted_at IS NOT NULL');
    $stmt->execute([':id' => $folderId]);
    if ($stmt->fetch(PDO::FETCH_ASSOC) === false) {
        marketing_respond(404, ['ok' => false, 'error' => 'That folder isn\'t in Trash.']);
    }

    $ids = array_merge([$folderId], marketing_folder_descendant_ids($pdo, $folderId));
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $docStmt = $pdo->prepare("SELECT id FROM documents WHERE folder_id IN ($placeholders)");
    $docStmt->execute($ids);
    foreach ($docStmt->fetchAll(PDO::FETCH_COLUMN) as $docId) {
        marketing_purge_document_file($pdo, (int) $docId);
    }

    $pdo->prepare("DELETE FROM folders WHERE id IN ($placeholders)")->execute($ids);
    marketing_respond(200, ['ok' => true]);
}

if ($action === 'purge_document') {
    $body = marketing_read_json_body();
    $documentId = (int) ($body['document_id'] ?? 0);
    if ($documentId <= 0) {
        marketing_respond(400, ['ok' => false, 'error' => 'Missing document_id.']);
    }
    $stmt = $pdo->prepare('SELECT * FROM documents WHERE id = :id AND deleted_at IS NOT NULL');
    $stmt->execute([':id' => $documentId]);
    if ($stmt->fetch(PDO::FETCH_ASSOC) === false) {
        marketing_respond(404, ['ok' => false, 'error' => 'That document isn\'t in Trash.']);
    }
    marketing_purge_document_file($pdo, $documentId);
    $pdo->prepare('DELETE FROM documents WHERE id = :id')->execute([':id' => $documentId]);
    marketing_respond(200, ['ok' => true]);
}

marketing_respond(400, ['ok' => false, 'error' => 'Unknown action.']);

/** Deletes one document's file + its data/documents/<id>/ folder from disk, if present. Row deletion is the caller's job. */
function marketing_purge_document_file(PDO $pdo, int $documentId): void
{
    $stmt = $pdo->prepare('SELECT * FROM documents WHERE id = :id');
    $stmt->execute([':id' => $documentId]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($doc === false) {
        return;
    }
    $path = marketing_document_path($doc);
    if (is_file($path)) {
        @unlink($path);
    }
    $dir = marketing_document_dir($documentId);
    if (is_dir($dir)) {
        @rmdir($dir);
    }
}
