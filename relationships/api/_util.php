<?php
/**
 * relationships/api/_util.php
 *
 * Small shared helpers for every relationships/api/*.php endpoint:
 * JSON responses, the login session check, and a lightweight origin check
 * mirroring mail/send-quote.php's pattern (this app is same-origin/
 * same-site, so this is defense-in-depth, not real CORS handling).
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

function relationships_respond(int $httpCode, array $payload): never
{
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($httpCode);
    echo json_encode($payload);
    exit;
}

function relationships_read_json_body(int $maxBytes = 262144): array
{
    $raw = file_get_contents('php://input', false, null, 0, $maxBytes);
    $data = json_decode((string) $raw, true);
    return is_array($data) ? $data : [];
}

/**
 * As of 2026-09-15 this dashboard no longer runs its own login session --
 * everyone signs in once via Microsoft 365 at the SolutionsHub root (see
 * /login.php), and every app (root, relationships/, register/) shares that
 * ONE session. See auth/session.php for the actual session mechanics this
 * just delegates to.
 */
function relationships_start_session(): void
{
    require_once __DIR__ . '/../../auth/session.php';
    auth_start_session();
}

/**
 * Returns the signed-in user's row (id, name, email) or null. Never
 * throws. $_SESSION['crc_user_id'] is set by auth/callback.php on
 * successful Microsoft sign-in (see auth/local-user.php) -- it's this
 * app's own crc_users.id, kept as a real local row (rather than reading
 * name/email straight out of the shared session) so existing foreign keys
 * elsewhere in this schema, like checklist_progress's "completed by",
 * keep resolving to a valid id exactly as before.
 */
function relationships_current_user(PDO $pdo): ?array
{
    relationships_start_session();
    $userId = $_SESSION['crc_user_id'] ?? null;
    if (!is_int($userId)) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT id, name, email FROM crc_users WHERE id = :id');
    $stmt->execute([':id' => $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}

/**
 * Call at the top of any endpoint that requires a logged-in CRC. Responds
 * with 401 and exits if there's no active session.
 */
function relationships_require_login(PDO $pdo): array
{
    $user = relationships_current_user($pdo);
    if ($user === null) {
        relationships_respond(401, ['ok' => false, 'error' => 'Not signed in.']);
    }
    return $user;
}

/**
 * Resolves an `Authorization: Bearer <token>` request header against
 * addin_tokens (see db.php's migration and auth/addin-callback.php) --
 * added 2026-09-28 for the Outlook add-in, whose task pane cannot reliably
 * share this site's PHP session cookie (its own sandboxed webview) and so
 * authenticates with an opaque per-device token instead. Only the token's
 * HASH is ever stored, so this hashes the presented token and looks up the
 * hash -- same posture as checking a password. Returns null (never throws)
 * on a missing header, a malformed header, an unknown token, or a revoked
 * one -- every case degrades the same way, to "not authenticated," so a
 * caller can't accidentally distinguish "bad token" from "no token" in a
 * way that would help an attacker enumerate valid ones.
 *
 * On success, bumps last_used_at (best-effort -- a failure to record that
 * timestamp is not a reason to fail the request itself) and returns the
 * SAME shape relationships_current_user() does, so every existing
 * territory/ownership check downstream keeps working unmodified.
 */
function relationships_bearer_user(PDO $pdo): ?array
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (!is_string($header) || !preg_match('/^Bearer\s+([A-Za-z0-9]+)$/', trim($header), $m)) {
        return null;
    }
    $tokenHash = hash('sha256', $m[1]);

    $stmt = $pdo->prepare(
        'SELECT id, crc_user_id FROM addin_tokens WHERE token_hash = :hash AND revoked_at IS NULL'
    );
    $stmt->execute([':hash' => $tokenHash]);
    $tokenRow = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($tokenRow === false) {
        return null;
    }

    $userStmt = $pdo->prepare('SELECT id, name, email FROM crc_users WHERE id = :id');
    $userStmt->execute([':id' => (int) $tokenRow['crc_user_id']]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);
    if ($user === false) {
        return null;
    }

    try {
        $pdo->prepare("UPDATE addin_tokens SET last_used_at = datetime('now') WHERE id = :id")
            ->execute([':id' => (int) $tokenRow['id']]);
    } catch (Throwable $e) {
        // Non-fatal -- the token is still valid even if this bookkeeping
        // write fails.
    }

    return $user;
}

/**
 * Like relationships_require_login(), but also accepts a bearer token
 * (relationships_bearer_user() above) -- so an endpoint the Outlook add-in
 * calls directly (meetings.php's add_task/set_task_done/list/global, this
 * same file's territory helpers, etc.) works unmodified for BOTH a normal
 * signed-in browser session and the add-in's own per-device token, without
 * duplicating any of the engine those endpoints already run. Bearer is
 * checked first (cheap, and an add-in request never carries this site's
 * session cookie anyway); session is the fallback for every existing
 * caller. Responds 401 and exits if neither resolves to a real user.
 */
function relationships_require_login_or_bearer(PDO $pdo): array
{
    $bearerUser = relationships_bearer_user($pdo);
    if ($bearerUser !== null) {
        return $bearerUser;
    }
    return relationships_require_login($pdo);
}
