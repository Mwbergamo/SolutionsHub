<?php
declare(strict_types=1);

/**
 * marketing/api/documents.php
 *
 * The files themselves. See documents-core.php for the validate/place
 * helpers (same allow-list/size-cap/magic-byte approach as Relationships'
 * Customer Documents, no ConnectWise attach step). Files live on disk
 * under data/documents/<document_id>/<original filename> --
 * data/.htaccess denies ALL direct HTTP access, same as every other
 * app's data/ folder; the only way to read one back is the 'download'
 * action below, streamed through PHP after the sign-in check.
 *
 * GET  ?action=list&folder_id=N&sort=name|date|type&dir=asc|desc
 *   -> { ok, folder: {...}, documents: [...] } -- non-trashed documents
 *      directly in this folder (not nested subfolders' files).
 *
 * GET  ?action=search&q=...
 *   -> { ok, documents: [ {..., folder_name, pillar} ] } -- filename
 *      search across every non-trashed document in every pillar/folder.
 *
 * POST ?action=upload   multipart/form-data: folder_id, file
 *   -> { ok, document: {...} }
 *
 * GET  ?action=download&id=N
 *   Streams the file as an attachment under its original filename.
 *
 * POST ?action=trash    { document_id }
 *   -> { ok: true }
 */

require_once __DIR__ . '/_util.php';
require_once __DIR__ . '/documents-core.php';

$pdo = marketing_db();
$user = marketing_require_login();

$action = $_GET['action'] ?? '';

if ($action === 'list') {
    $folderId = (int) ($_GET['folder_id'] ?? 0);
    if ($folderId <= 0) {
        marketing_respond(400, ['ok' => false, 'error' => 'Missing folder_id.']);
    }
    $folder = marketing_require_folder($pdo, $folderId);

    $sortCol = match ($_GET['sort'] ?? 'date') {
        'name' => 'original_filename COLLATE NOCASE',
        'type' => 'ext, original_filename COLLATE NOCASE',
        default => 'uploaded_at',
    };
    $dir = (($_GET['dir'] ?? 'desc') === 'asc') ? 'ASC' : 'DESC';

    $stmt = $pdo->prepare("SELECT * FROM documents WHERE folder_id = :fid AND deleted_at IS NULL ORDER BY $sortCol $dir");
    $stmt->execute([':fid' => $folderId]);
    $documents = array_map('marketing_document_row', $stmt->fetchAll(PDO::FETCH_ASSOC));

    marketing_respond(200, ['ok' => true, 'folder' => marketing_folder_row($folder), 'documents' => $documents]);
}

if ($action === 'search') {
    $q = trim((string) ($_GET['q'] ?? ''));
    if ($q === '') {
        marketing_respond(200, ['ok' => true, 'documents' => []]);
    }
    $like = '%' . $q . '%';
    $stmt = $pdo->prepare(
        'SELECT d.*, f.name AS folder_name, f.pillar AS pillar
         FROM documents d JOIN folders f ON f.id = d.folder_id
         WHERE d.deleted_at IS NULL AND f.deleted_at IS NULL AND d.original_filename LIKE :q
         ORDER BY d.original_filename COLLATE NOCASE ASC LIMIT 200'
    );
    $stmt->execute([':q' => $like]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $documents = array_map(static function (array $r): array {
        return marketing_document_row($r) + ['folder_name' => $r['folder_name'], 'pillar' => $r['pillar']];
    }, $rows);
    marketing_respond(200, ['ok' => true, 'documents' => $documents]);
}

if ($action === 'upload') {
    $folderId = (int) ($_POST['folder_id'] ?? 0);
    if ($folderId <= 0) {
        marketing_respond(400, ['ok' => false, 'error' => 'Missing folder_id.']);
    }
    marketing_require_folder($pdo, $folderId);

    if (!isset($_FILES['file']) || !is_array($_FILES['file']) || (int) ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        marketing_respond(400, ['ok' => false, 'error' => 'No file was received (or the upload failed).']);
    }
    $originalName = basename((string) $_FILES['file']['name']);
    $tmpPath = (string) $_FILES['file']['tmp_name'];
    $sizeBytes = (int) $_FILES['file']['size'];
    $ext = marketing_document_validate_file($originalName, $sizeBytes, $tmpPath);

    $pdo->beginTransaction();
    $stmt = $pdo->prepare('INSERT INTO documents (folder_id, original_filename, ext, size_bytes, uploaded_by_name, uploaded_by_email, uploaded_at) VALUES (:fid, :n, :ext, :sz, :un, :ue, :t)');
    $stmt->execute([
        ':fid' => $folderId,
        ':n' => $originalName,
        ':ext' => $ext,
        ':sz' => $sizeBytes,
        ':un' => $user['name'],
        ':ue' => $user['email'],
        ':t' => gmdate('Y-m-d\TH:i:s\Z'),
    ]);
    $newId = (int) $pdo->lastInsertId();
    $pdo->commit();

    marketing_document_place_file($newId, $originalName, $tmpPath, true);

    $row = $pdo->query('SELECT * FROM documents WHERE id = ' . $newId)->fetch(PDO::FETCH_ASSOC);
    marketing_respond(200, ['ok' => true, 'document' => marketing_document_row($row)]);
}

if ($action === 'download') {
    $id = (int) ($_GET['id'] ?? 0);
    $stmt = $pdo->prepare('SELECT * FROM documents WHERE id = :id AND deleted_at IS NULL');
    $stmt->execute([':id' => $id]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($doc === false) {
        marketing_respond(404, ['ok' => false, 'error' => 'Document not found.']);
    }
    $path = marketing_document_path($doc);
    if (!is_file($path)) {
        marketing_respond(404, ['ok' => false, 'error' => 'File is missing on disk.']);
    }
    $mime = marketing_document_types()[$doc['ext']] ?? 'application/octet-stream';
    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $doc['original_filename']) . '"');
    header('Content-Length: ' . filesize($path));
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}

if ($action === 'trash') {
    $body = marketing_read_json_body();
    $documentId = (int) ($body['document_id'] ?? 0);
    if ($documentId <= 0) {
        marketing_respond(400, ['ok' => false, 'error' => 'Missing document_id.']);
    }
    $now = gmdate('Y-m-d\TH:i:s\Z');
    $stmt = $pdo->prepare('UPDATE documents SET deleted_at = :t, deleted_by_name = :n, deleted_by_email = :e WHERE id = :id AND deleted_at IS NULL');
    $stmt->execute([':t' => $now, ':n' => $user['name'], ':e' => $user['email'], ':id' => $documentId]);
    if ($stmt->rowCount() === 0) {
        marketing_respond(404, ['ok' => false, 'error' => 'Document not found (or already removed).']);
    }
    marketing_respond(200, ['ok' => true]);
}

marketing_respond(400, ['ok' => false, 'error' => 'Unknown action.']);
