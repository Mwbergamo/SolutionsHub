<?php
declare(strict_types=1);

/**
 * marketing/api/documents-core.php
 *
 * File validation/storage helpers for the Marketing document hub --
 * adapted from relationships/api/documents-core.php's same-named
 * functions (same extension allow-list, same 100 MB cap, same
 * magic-byte sniffing for the zip-based Office formats and PDF), minus
 * the ConnectWise-attachment step (marketing files are collateral, not
 * tied to any one customer's ConnectWise Company) and keyed by folder_id
 * instead of customer_id.
 */

// 100 MB per document -- same ceiling relationships/api/documents-core.php
// uses, for the same reason (Office files/PDFs/decks on a shared-hosting
// disk quota with no purge job).
if (!defined('MARKETING_DOCUMENT_MAX_BYTES')) {
    define('MARKETING_DOCUMENT_MAX_BYTES', 100 * 1024 * 1024);
}

// Graph's sendMail request must stay well under its ~4 MB total size
// (see mail/send-quote.php's own comment) -- base64 adds ~33%, so this
// caps the RAW file size offered for emailing. A file over this limit
// can still be stored/downloaded here; it just can't be attached to an
// outgoing email from this app.
if (!defined('MARKETING_EMAIL_ATTACHMENT_MAX_BYTES')) {
    define('MARKETING_EMAIL_ATTACHMENT_MAX_BYTES', 3 * 1024 * 1024);
}

function marketing_document_types(): array
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
        'odp'  => 'application/vnd.oasis.opendocument.presentation',
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif'  => 'image/gif',
        'svg'  => 'image/svg+xml',
        'vsd'  => 'application/vnd.visio',
        'vsdx' => 'application/vnd.ms-visio.drawing',
        'zip'  => 'application/zip',
    ];
}

/**
 * Validates $originalName's extension against the allow-list,
 * $sizeBytes against the cap, and -- for formats with a fixed signature
 * -- sniffs the first bytes of $sourcePath rather than trusting the
 * extension alone. Responds 400 and exits on any failure. Returns the
 * lowercased extension on success. Same approach as
 * relationships_document_validate_file().
 */
function marketing_document_validate_file(string $originalName, int $sizeBytes, string $sourcePath): string
{
    if ($sizeBytes <= 0) {
        marketing_respond(400, ['ok' => false, 'error' => 'That file is empty.']);
    }
    if ($sizeBytes > MARKETING_DOCUMENT_MAX_BYTES) {
        marketing_respond(400, ['ok' => false, 'error' => 'That file is larger than the 100 MB limit.']);
    }

    $ext = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
    $types = marketing_document_types();
    if ($originalName === '' || !isset($types[$ext])) {
        marketing_respond(400, ['ok' => false, 'error' => 'That file type isn\'t accepted. Allowed: ' . implode(', ', array_keys($types)) . '.']);
    }

    $head = (string) @file_get_contents($sourcePath, false, null, 0, 5);
    $zipBased = ['docx', 'xlsx', 'xlsm', 'pptx', 'zip', 'odt', 'ods', 'odp'];
    if ($ext === 'pdf' && substr($head, 0, 4) !== '%PDF') {
        marketing_respond(400, ['ok' => false, 'error' => 'That file doesn\'t look like a valid PDF.']);
    }
    if (in_array($ext, $zipBased, true) && substr($head, 0, 2) !== 'PK') {
        marketing_respond(400, ['ok' => false, 'error' => 'That file doesn\'t look like a valid .' . $ext . ' file.']);
    }

    return $ext;
}

/**
 * Moves $sourcePath into data/documents/<document_id>/ under its
 * original filename (unlike Relationships' per-customer folder, a
 * document here already has a unique numeric id by the time this is
 * called -- see documents.php's upload action -- so there's no
 * filename-collision risk and the original name can be kept verbatim on
 * disk, which makes a manual server-side look during a disk-quota
 * cleanup self-explanatory). Responds 500 and exits on failure.
 */
function marketing_document_place_file(int $documentId, string $originalName, string $sourcePath, bool $isUploadedFile): void
{
    $dir = marketing_document_dir($documentId);
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        marketing_respond(500, ['ok' => false, 'error' => 'Could not create storage folder for this document.']);
    }
    $dest = $dir . '/' . $originalName;
    $moved = $isUploadedFile ? move_uploaded_file($sourcePath, $dest) : rename($sourcePath, $dest);
    if (!$moved) {
        marketing_respond(500, ['ok' => false, 'error' => 'Could not save the uploaded file.']);
    }
}

function marketing_document_dir(int $documentId): string
{
    return __DIR__ . '/../data/documents/' . $documentId;
}

function marketing_document_path(array $doc): string
{
    return marketing_document_dir((int) $doc['id']) . '/' . $doc['original_filename'];
}
