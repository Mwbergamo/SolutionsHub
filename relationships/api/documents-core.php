<?php
declare(strict_types=1);

/**
 * relationships/api/documents-core.php
 *
 * Added 2026-11-12, extracted out of documents.php so the Outlook add-in
 * endpoint (relationships/api/outlook-addin.php) can file a Document --
 * a rep-picked attachment, or the auto-generated email-snapshot for
 * Create-a-Meeting/Create-a-To-Do -- WITHOUT going through an HTTP round
 * trip to documents.php?action=upload and without re-running that file's
 * own top-level login/territory/action-dispatch code (which would try to
 * require_login() a second time, re-read $_GET['action'], etc.). Same
 * pattern as client-finance/api/gauges-core.php: this file defines
 * FUNCTIONS ONLY, no top-level execution, and both documents.php and
 * outlook-addin.php require it.
 *
 * Splits the old single upload() action into three reusable steps, since
 * the add-in's two sources of a "file" are different from a normal HTTP
 * upload:
 *   1. relationships_document_validate_file()   -- extension/size/magic-
 *      byte checks against a path on disk (works whether that path is
 *      $_FILES[...]['tmp_name'] or a snapshot file outlook-addin.php just
 *      wrote itself).
 *   2. relationships_document_place_file()       -- moves it into
 *      data/documents/<customer_id>/, via move_uploaded_file() for a real
 *      HTTP upload or rename() for a server-generated file.
 *   3. relationships_document_insert_and_push()   -- INSERT into
 *      customer_documents + the existing ConnectWise attach step, same as
 *      before.
 *
 * Every function here calls relationships_respond() (via _util.php) and
 * exits on a validation/IO failure, exactly as documents.php's old inline
 * code did -- callers don't need their own error handling for these
 * steps.
 */

require_once __DIR__ . '/_util.php';
require_once __DIR__ . '/territory-access.php';
require_once __DIR__ . '/connectwise.php';

// 100 MB per document -- comfortably under the 300M upload_max_filesize in
// this folder's .user.ini, and a sane ceiling for Office files/PDFs/scans
// on a shared-hosting disk quota that has no purge job behind it.
if (!defined('RELATIONSHIPS_DOCUMENT_MAX_BYTES')) {
    define('RELATIONSHIPS_DOCUMENT_MAX_BYTES', 100 * 1024 * 1024);
}

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

/**
 * Step 1 of filing a document: validates $originalName's extension
 * against the allow-list, $sizeBytes against the cap, and -- for formats
 * with a fixed signature -- sniffs the first bytes of $sourcePath rather
 * than trusting the extension alone. $sourcePath just needs to be a
 * readable file on disk; it does NOT need to be a real HTTP upload (the
 * add-in's generated email-snapshot files go through this exact same
 * check). Responds 400 and exits on any failure. Returns the lowercased
 * extension on success.
 */
function relationships_document_validate_file(string $originalName, int $sizeBytes, string $sourcePath): string
{
    if ($sizeBytes <= 0) {
        relationships_respond(400, ['ok' => false, 'error' => 'That file is empty.']);
    }
    if ($sizeBytes > RELATIONSHIPS_DOCUMENT_MAX_BYTES) {
        relationships_respond(400, ['ok' => false, 'error' => 'That file is larger than the 100 MB limit.']);
    }

    $ext = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
    $types = relationships_document_types();
    if ($originalName === '' || !isset($types[$ext])) {
        relationships_respond(400, ['ok' => false, 'error' => 'That file type isn\'t accepted. Allowed: ' . implode(', ', array_keys($types)) . '.']);
    }

    $head = (string) @file_get_contents($sourcePath, false, null, 0, 5);
    $zipBased = ['docx', 'xlsx', 'xlsm', 'pptx', 'zip', 'odt', 'ods'];
    if ($ext === 'pdf' && substr($head, 0, 4) !== '%PDF') {
        relationships_respond(400, ['ok' => false, 'error' => 'That file doesn\'t look like a valid PDF.']);
    }
    if (in_array($ext, $zipBased, true) && substr($head, 0, 2) !== 'PK') {
        relationships_respond(400, ['ok' => false, 'error' => 'That file doesn\'t look like a valid .' . $ext . ' file.']);
    }

    return $ext;
}

/**
 * Step 2: moves $sourcePath into data/documents/<customer_id>/ under a
 * fresh generated name, creating that directory if needed. Pass
 * $isUploadedFile = true for a real $_FILES[...]['tmp_name'] (uses
 * move_uploaded_file(), which also checks the source really did come
 * through PHP's upload machinery); pass false for a server-generated
 * file such as the add-in's email snapshot (uses rename()). Responds 500
 * and exits on failure. Returns the stored filename (not the full path --
 * same value documents.php's INSERT and relationships_document_dir()
 * callers have always combined back into a path).
 */
function relationships_document_place_file(int $customerId, string $ext, string $sourcePath, bool $isUploadedFile): string
{
    $dir = relationships_document_dir($customerId);
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        relationships_respond(500, ['ok' => false, 'error' => 'Could not create storage folder for this customer.']);
    }
    $storedName = date('Ymd-His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    $dest = $dir . '/' . $storedName;

    $moved = $isUploadedFile ? move_uploaded_file($sourcePath, $dest) : rename($sourcePath, $dest);
    if (!$moved) {
        relationships_respond(500, ['ok' => false, 'error' => 'Could not save the uploaded file.']);
    }

    return $storedName;
}

/**
 * Step 3: INSERTs the customer_documents row for an already-placed file
 * (relationships_document_place_file() must have run first) and attempts
 * the ConnectWise attach, exactly as documents.php's upload action always
 * has. Returns the finished row in the same shape relationships_document_row()
 * produces.
 */
function relationships_document_insert_and_push(
    PDO $pdo,
    int $customerId,
    string $originalName,
    string $storedName,
    int $sizeBytes,
    string $category,
    int $uploadedByUserId,
    string $uploadedByName
): array {
    $pdo->prepare(
        'INSERT INTO customer_documents (customer_id, original_filename, stored_filename, category, size_bytes, uploaded_by_user_id, uploaded_by_name)
         VALUES (:cid, :orig, :stored, :cat, :size, :uid, :uname)'
    )->execute([
        ':cid' => $customerId, ':orig' => $originalName, ':stored' => $storedName, ':cat' => $category,
        ':size' => $sizeBytes, ':uid' => $uploadedByUserId, ':uname' => $uploadedByName,
    ]);
    $docId = (int) $pdo->lastInsertId();

    $docStmt = $pdo->prepare('SELECT * FROM customer_documents WHERE id = :id');
    $docStmt->execute([':id' => $docId]);
    relationships_document_push_to_cw($pdo, $docStmt->fetch(PDO::FETCH_ASSOC));

    $docStmt->execute([':id' => $docId]);
    return relationships_document_row($docStmt->fetch(PDO::FETCH_ASSOC));
}
