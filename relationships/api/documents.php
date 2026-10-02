<?php
/**
 * relationships/api/documents.php
 *
 * Customer Documents -- added 2026-10-02 per Michael: under each company, a
 * Documents section for Word docs, PDFs, spreadsheets and any other
 * important historical document the CRCs need to see and share. Same shape
 * as risk-scans.php (upload on the customer's dashboard, any signed-in
 * Relationships user can upload/download, local copy on disk + the same
 * ConnectWise Company Document attachment), minus the review/assign
 * workflow -- a document is just filed, not an alert.
 *
 * Files live on disk under data/documents/<customer_id>/ -- data/.htaccess
 * denies ALL direct HTTP access to everything under data/, so the ONLY way
 * to read one back out is the 'download' action below, which streams it
 * through PHP after the normal login + territory checks (same protection
 * risk scans rely on). Unlike risk scans there is deliberately NO 1-year
 * purge: these are historical records the team wants to keep. The disk
 * quota on Bluehost is real, though -- see RELATIONSHIPS_DOCUMENT_MAX_BYTES.
 *
 * GET  /relationships/api/documents.php?action=list&customer_id=1
 *   -> { ok: true, documents: [ { id, original_filename, category,
 *        size_bytes, uploaded_by_name, uploaded_at, cw_upload_status,
 *        cw_upload_error }, ... ] } (newest first)
 *
 * POST /relationships/api/documents.php?action=upload
 *   multipart/form-data: customer_id, file, category (optional)
 *   -> { ok: true, document: {...} } -- document.cw_upload_status says
 *   whether the ConnectWise attachment worked.
 *
 * ConnectWise attachment: EVERY upload is also attached to the customer's
 * ConnectWise Company as a Document (relationships_cw_upload_document(),
 * POST /system/documents) right after the local save -- identical to Risk
 * Scans. The local copy never depends on it: if ConnectWise is down or
 * rejects the file, the document is still saved, the row is marked
 * cw_upload_status='failed' with the reason, the panel shows a Retry
 * button, and 'retry_cw_upload' re-attempts it. Customers with no
 * ConnectWise company (mock data) are 'skipped'.
 *
 * POST /relationships/api/documents.php?action=retry_cw_upload
 *   { id } -> { ok: true, document: {...} }
 *
 * GET  /relationships/api/documents.php?action=download&id=5
 *   Streams the file as an attachment under its original filename.
 */

declare(strict_types=1);

require_once __DIR__ . '/_util.php';
require_once __DIR__ . '/territory-access.php';
require_once __DIR__ . '/connectwise.php';

$pdo = relationships_db();
$user = relationships_require_login($pdo);
$allowedTerritories = relationships_allowed_territories($pdo);

$action = $_GET['action'] ?? '';

// 100 MB per document -- comfortably under the 300M upload_max_filesize in
// this folder's .user.ini, and a sane ceiling for Office files/PDFs/scans
// on a shared-hosting disk quota that has no purge job behind it.
const RELATIONSHIPS_DOCUMENT_MAX_BYTES = 100 * 1024 * 1024;

// Allow-list of extensions -> content type. Anything not listed is refused
// (so no .php/.html/.js/.exe can ever be stored or served). The content type
// is also what's sent to ConnectWise and on download.
function relationships_document_types(): array
{
    return [
        'pdf'  => 'application/pdf',
        'doc'  => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls'  => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'xlsm' => 'application/vnd.ms-excel.sheet.macroEnabled.12',
        'csv'  => 'text/csv',
        'ppt'  => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'txt'  => 'text/plain',
        'rtf'  => 'application/rtf',
        'odt'  => 'application/vnd.oasis.opendocument.text',
        'ods'  => 'application/vnd.oasis.opendocument.spreadsheet',
        'msg'  => 'application/vnd.ms-outlook',
        'eml'  => 'message/rfc822',
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif'  => 'image/gif',
        'vsd'  => 'application/vnd.visio',
        'vsdx' => 'application/vnd.ms-visio.drawing',
        'zip'  => 'application/zip',
    ];
}

function relationships_document_categories(): array
{
    return ['General', 'Contract', 'Proposal / Quote', 'Network Diagram', 'Invoice / Billing', 'Meeting Notes', 'Assessment / Report', 'Other'];
}

function relationships_document_dir(int $customerId): string
{
    return __DIR__ . '/../data/documents/' . $customerId;
}

function relationships_document_row(array $r): array
{
    return [
        'id' => (int) $r['id'],
        'customer_id' => (int) $r['customer_id'],
        'original_filename' => $r['original_filename'],
        'category' => $r['category'],
        'size_bytes' => (int) $r['size_bytes'],
        'uploaded_by_name' => $r['uploaded_by_name'],
        'uploaded_at' => $r['uploaded_at'],
        'cw_upload_status' => $r['cw_upload_status'] ?? null,
        'cw_upload_error' => $r['cw_upload_error'] ?? null,
    ];
}

function relationships_documents_require_customer_in_scope(PDO $pdo, ?array $allowedTerritories, int $customerId): array
{
    $stmt = $pdo->prepare('SELECT id, name, territory_name FROM customers WHERE id = :id');
    $stmt->execute([':id' => $customerId]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($customer === false) {
        relationships_respond(404, ['ok' => false, 'error' => 'Customer not found.']);
    }
    relationships_require_territory_scope($allowedTerritories, $customer['territory_name']);
    return $customer;
}

/**
 * Attaches one saved document to its customer's ConnectWise Company and
 * records the outcome on the row. Never throws -- the local upload has
 * already succeeded and must not be undone by a ConnectWise problem.
 */
function relationships_document_push_to_cw(PDO $pdo, array $doc): void
{
    $custStmt = $pdo->prepare('SELECT name, connectwise_id, is_mock FROM customers WHERE id = :id');
    $custStmt->execute([':id' => $doc['customer_id']]);
    $customer = $custStmt->fetch(PDO::FETCH_ASSOC);

    $set = static function (string $status, ?string $docId, ?string $error) use ($pdo, $doc): void {
        $pdo->prepare(
            "UPDATE customer_documents SET cw_upload_status = :s, cw_document_id = :d, cw_upload_error = :e,
                cw_uploaded_at = CASE WHEN :s2 = 'uploaded' THEN datetime('now') ELSE cw_uploaded_at END
             WHERE id = :id"
        )->execute([':s' => $status, ':s2' => $status, ':d' => $docId, ':e' => $error, ':id' => $doc['id']]);
    };

    if ($customer === false || (int) ($customer['is_mock'] ?? 0) === 1 || trim((string) ($customer['connectwise_id'] ?? '')) === '') {
        $set('skipped', null, 'This customer has no ConnectWise company to attach to.');
        return;
    }

    $path = relationships_document_dir((int) $doc['customer_id']) . '/' . $doc['stored_filename'];
    $ext = strtolower((string) pathinfo((string) $doc['original_filename'], PATHINFO_EXTENSION));
    $mime = relationships_document_types()[$ext] ?? 'application/octet-stream';
    @set_time_limit(280);
    try {
        $cwDoc = relationships_cw_upload_document(
            'Company',
            (string) $customer['connectwise_id'],
            (string) $doc['original_filename'],
            $path,
            (string) $doc['original_filename'],
            'Document (' . $doc['category'] . ') uploaded via Relationships by ' . $doc['uploaded_by_name'],
            $mime
        );
        $set('uploaded', (string) $cwDoc['id'], null);
    } catch (Throwable $e) {
        error_log('[relationships/documents] ConnectWise attach failed for document ' . $doc['id'] . ': ' . $e->getMessage());
        $set('failed', null, mb_substr($e->getMessage(), 0, 500));
    }
}

if ($action === 'list') {
    $customerId = (int) ($_GET['customer_id'] ?? 0);
    if ($customerId <= 0) {
        relationships_respond(400, ['ok' => false, 'error' => 'Missing/invalid customer_id.']);
    }
    relationships_documents_require_customer_in_scope($pdo, $allowedTerritories, $customerId);

    $stmt = $pdo->prepare(
        'SELECT id, customer_id, original_filename, category, size_bytes, uploaded_by_name, uploaded_at, cw_upload_status, cw_upload_error
         FROM customer_documents WHERE customer_id = :cid ORDER BY uploaded_at DESC, id DESC'
    );
    $stmt->execute([':cid' => $customerId]);
    $documents = array_map('relationships_document_row', $stmt->fetchAll(PDO::FETCH_ASSOC));

    relationships_respond(200, ['ok' => true, 'documents' => $documents, 'categories' => relationships_document_categories()]);
}

if ($action === 'download') {
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) {
        relationships_respond(400, ['ok' => false, 'error' => 'Missing/invalid id.']);
    }
    $stmt = $pdo->prepare('SELECT * FROM customer_documents WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($doc === false) {
        relationships_respond(404, ['ok' => false, 'error' => 'Document not found.']);
    }
    relationships_documents_require_customer_in_scope($pdo, $allowedTerritories, (int) $doc['customer_id']);

    $path = relationships_document_dir((int) $doc['customer_id']) . '/' . $doc['stored_filename'];
    if (!is_file($path)) {
        relationships_respond(404, ['ok' => false, 'error' => 'That file is no longer on the server.']);
    }

    // Header-safe filename: no quotes/CR/LF/path separators in the plain
    // form, UTF-8 form alongside it for non-ASCII names.
    $safeName = str_replace(['"', "\r", "\n", '/', '\\'], '', (string) $doc['original_filename']);
    $ext = strtolower((string) pathinfo($safeName, PATHINFO_EXTENSION));
    $mime = relationships_document_types()[$ext] ?? 'application/octet-stream';

    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . $safeName . '"; filename*=UTF-8\'\'' . rawurlencode($safeName));
    header('Content-Length: ' . (string) filesize($path));
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    relationships_respond(405, ['ok' => false, 'error' => 'Method not allowed.']);
}

if ($action === 'upload') {
    $customerId = (int) ($_POST['customer_id'] ?? 0);
    if ($customerId <= 0) {
        relationships_respond(400, ['ok' => false, 'error' => 'Missing/invalid customer_id.']);
    }
    relationships_documents_require_customer_in_scope($pdo, $allowedTerritories, $customerId);

    $category = trim((string) ($_POST['category'] ?? 'General'));
    if (!in_array($category, relationships_document_categories(), true)) {
        $category = 'General';
    }

    if (!isset($_FILES['file'])) {
        relationships_respond(400, ['ok' => false, 'error' => 'No file received.']);
    }
    $file = $_FILES['file'];
    if ((int) $file['error'] === UPLOAD_ERR_INI_SIZE || (int) $file['error'] === UPLOAD_ERR_FORM_SIZE) {
        relationships_respond(400, ['ok' => false, 'error' => 'That file is too large for the server to accept.']);
    }
    if ((int) $file['error'] !== UPLOAD_ERR_OK) {
        relationships_respond(400, ['ok' => false, 'error' => 'Upload failed (error code ' . (int) $file['error'] . '). Please try again.']);
    }
    if ((int) $file['size'] <= 0) {
        relationships_respond(400, ['ok' => false, 'error' => 'That file is empty.']);
    }
    if ((int) $file['size'] > RELATIONSHIPS_DOCUMENT_MAX_BYTES) {
        relationships_respond(400, ['ok' => false, 'error' => 'That file is larger than the 100 MB limit.']);
    }

    // Keep only the base name -- some browsers/clients send a path.
    $originalName = trim(basename(str_replace('\\', '/', (string) $file['name'])));
    $ext = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
    $types = relationships_document_types();
    if ($originalName === '' || !isset($types[$ext])) {
        relationships_respond(400, ['ok' => false, 'error' => 'That file type isn\'t accepted. Allowed: ' . implode(', ', array_keys($types)) . '.']);
    }

    // Cheap sanity check on formats with a fixed signature, rather than
    // trusting the client-supplied extension alone.
    $head = (string) @file_get_contents($file['tmp_name'], false, null, 0, 5);
    $zipBased = ['docx', 'xlsx', 'xlsm', 'pptx', 'zip', 'odt', 'ods'];
    if ($ext === 'pdf' && substr($head, 0, 4) !== '%PDF') {
        relationships_respond(400, ['ok' => false, 'error' => 'That file doesn\'t look like a valid PDF.']);
    }
    if (in_array($ext, $zipBased, true) && substr($head, 0, 2) !== 'PK') {
        relationships_respond(400, ['ok' => false, 'error' => 'That file doesn\'t look like a valid .' . $ext . ' file.']);
    }

    $dir = relationships_document_dir($customerId);
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        relationships_respond(500, ['ok' => false, 'error' => 'Could not create storage folder for this customer.']);
    }
    $storedName = date('Ymd-His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    $dest = $dir . '/' . $storedName;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        relationships_respond(500, ['ok' => false, 'error' => 'Could not save the uploaded file.']);
    }

    $pdo->prepare(
        'INSERT INTO customer_documents (customer_id, original_filename, stored_filename, category, size_bytes, uploaded_by_user_id, uploaded_by_name)
         VALUES (:cid, :orig, :stored, :cat, :size, :uid, :uname)'
    )->execute([
        ':cid' => $customerId, ':orig' => $originalName, ':stored' => $storedName, ':cat' => $category,
        ':size' => (int) $file['size'], ':uid' => $user['id'], ':uname' => $user['name'],
    ]);
    $docId = (int) $pdo->lastInsertId();

    $docStmt = $pdo->prepare('SELECT * FROM customer_documents WHERE id = :id');
    $docStmt->execute([':id' => $docId]);
    relationships_document_push_to_cw($pdo, $docStmt->fetch(PDO::FETCH_ASSOC));

    $docStmt->execute([':id' => $docId]);
    relationships_respond(200, ['ok' => true, 'document' => relationships_document_row($docStmt->fetch(PDO::FETCH_ASSOC))]);
}

if ($action === 'retry_cw_upload') {
    $data = relationships_read_json_body();
    $id = (int) ($data['id'] ?? 0);
    if ($id <= 0) {
        relationships_respond(400, ['ok' => false, 'error' => 'Missing/invalid id.']);
    }
    $stmt = $pdo->prepare('SELECT * FROM customer_documents WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($doc === false) {
        relationships_respond(404, ['ok' => false, 'error' => 'Document not found.']);
    }
    relationships_documents_require_customer_in_scope($pdo, $allowedTerritories, (int) $doc['customer_id']);
    if (($doc['cw_upload_status'] ?? null) === 'uploaded') {
        relationships_respond(200, ['ok' => true, 'document' => relationships_document_row($doc)]);
    }

    relationships_document_push_to_cw($pdo, $doc);

    $stmt->execute([':id' => $id]);
    relationships_respond(200, ['ok' => true, 'document' => relationships_document_row($stmt->fetch(PDO::FETCH_ASSOC))]);
}

relationships_respond(400, ['ok' => false, 'error' => 'Unknown action.']);
