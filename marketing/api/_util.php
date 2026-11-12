<?php
declare(strict_types=1);

/**
 * marketing/api/_util.php
 *
 * Small shared helpers for every marketing/api/*.php endpoint -- same
 * shape as relationships/api/_util.php, but identity comes straight from
 * the shared Microsoft 365 session (auth/session.php) with no local user
 * table, same approach myday/api/digest.php already established, since
 * nothing in this app's schema needs a numeric user id to foreign-key
 * against -- every row that records "who did this" just stores the
 * name/email string directly.
 */

require_once __DIR__ . '/db.php';

function marketing_respond(int $httpCode, array $payload): never
{
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($httpCode);
    echo json_encode($payload);
    exit;
}

function marketing_read_json_body(int $maxBytes = 8388608): array
{
    $raw = file_get_contents('php://input', false, null, 0, $maxBytes);
    $data = json_decode((string) $raw, true);
    return is_array($data) ? $data : [];
}

/**
 * Returns the signed-in user's { name, email }, or responds 401 and
 * exits if nobody's signed in. Every endpoint calls this first.
 */
function marketing_require_login(): array
{
    require_once __DIR__ . '/../../auth/session.php';
    $user = auth_current_user();
    if ($user === null) {
        marketing_respond(401, ['ok' => false, 'error' => 'Not signed in.']);
    }
    return ['name' => (string) ($user['name'] ?? ''), 'email' => (string) ($user['email'] ?? '')];
}

function marketing_pillar_ids(): array
{
    return array_column(marketing_pillars(), 'id');
}

/** Looks up one non-deleted folder by id, or responds 404 and exits. */
function marketing_require_folder(PDO $pdo, int $folderId): array
{
    $stmt = $pdo->prepare('SELECT * FROM folders WHERE id = :id AND deleted_at IS NULL');
    $stmt->execute([':id' => $folderId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        marketing_respond(404, ['ok' => false, 'error' => 'Folder not found.']);
    }
    return $row;
}

function marketing_folder_row(array $r): array
{
    return [
        'id' => (int) $r['id'],
        'pillar' => $r['pillar'],
        'parent_id' => $r['parent_id'] !== null ? (int) $r['parent_id'] : null,
        'name' => $r['name'],
        'created_at' => $r['created_at'],
        'created_by_name' => $r['created_by_name'],
    ];
}

function marketing_document_row(array $r): array
{
    return [
        'id' => (int) $r['id'],
        'folder_id' => (int) $r['folder_id'],
        'original_filename' => $r['original_filename'],
        'ext' => $r['ext'],
        'size_bytes' => (int) $r['size_bytes'],
        'uploaded_by_name' => $r['uploaded_by_name'],
        'uploaded_by_email' => $r['uploaded_by_email'],
        'uploaded_at' => $r['uploaded_at'],
    ];
}
