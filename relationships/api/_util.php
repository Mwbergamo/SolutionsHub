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

/* ------------------------------------------------------------------ *
 * Outlook add-in bearer tokens (added 2026-11-12)
 *
 * The "Send to Relationships" add-in's task pane runs inside Outlook's
 * own sandboxed webview (Office.js), which does not reliably share this
 * site's session cookie -- so instead of relationships_current_user()'s
 * $_SESSION lookup, a rep signs in once per device via a small OAuth
 * dialog (auth/addin-login.php + auth/addin-callback.php) that mints one
 * of these tokens and hands it to the task pane exactly once. From then
 * on the task pane sends it as `Authorization: Bearer <token>` on every
 * call to relationships/api/outlook-addin.php. Only the SHA-256 hash is
 * ever persisted (see the addin_tokens migration in db.php) -- the raw
 * token exists only in the dialog's one-time handoff message and in the
 * browser/Office storage the task pane keeps it in.
 * ------------------------------------------------------------------ */

function relationships_addin_hash_token(string $rawToken): string
{
    return hash('sha256', $rawToken);
}

/**
 * Mints a new raw token for $crcUserId/$email, stores only its hash, and
 * returns the RAW token -- the one and only time it will ever exist in
 * plaintext. Called by auth/addin-callback.php right after a successful
 * Microsoft sign-in for the add-in's OAuth dialog.
 */
function relationships_addin_mint_token(PDO $pdo, int $crcUserId, string $email): string
{
    $rawToken = bin2hex(random_bytes(32));
    $stmt = $pdo->prepare(
        'INSERT INTO addin_tokens (crc_user_id, email, token_hash) VALUES (:uid, :email, :hash)'
    );
    $stmt->execute([
        ':uid' => $crcUserId,
        ':email' => $email,
        ':hash' => relationships_addin_hash_token($rawToken),
    ]);
    return $rawToken;
}

/**
 * Resolves the bearer token on this request (Authorization: Bearer ...)
 * to the same {id, name, email} shape relationships_current_user()
 * returns, or null if there's no header, no matching/unrevoked token, or
 * the token's crc_users row is gone. Bumps last_used_at on every
 * successful call so a stale, abandoned token is easy to spot later.
 */
function relationships_addin_user_from_bearer(PDO $pdo): ?array
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!is_string($header) || stripos($header, 'Bearer ') !== 0) {
        return null;
    }
    $rawToken = trim(substr($header, 7));
    if ($rawToken === '') {
        return null;
    }
    $hash = relationships_addin_hash_token($rawToken);

    $stmt = $pdo->prepare(
        'SELECT u.id, u.name, u.email, t.id AS token_id
         FROM addin_tokens t
         JOIN crc_users u ON u.id = t.crc_user_id
         WHERE t.token_hash = :hash AND t.revoked_at IS NULL'
    );
    $stmt->execute([':hash' => $hash]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        return null;
    }

    $touch = $pdo->prepare("UPDATE addin_tokens SET last_used_at = datetime('now') WHERE id = :id");
    $touch->execute([':id' => $row['token_id']]);

    return ['id' => (int) $row['id'], 'name' => $row['name'], 'email' => $row['email']];
}

/**
 * Like relationships_require_login(), but also accepts a valid add-in
 * bearer token -- for endpoints the Outlook task pane calls directly
 * (relationships/api/outlook-addin.php). Session is checked first since
 * it's the cheaper/already-established path for anyone hitting this from
 * a normal signed-in browser tab; the bearer check only runs if that
 * comes back empty. Responds with 401 and exits if neither resolves.
 */
function relationships_require_login_or_bearer(PDO $pdo): array
{
    $user = relationships_current_user($pdo);
    if ($user !== null) {
        return $user;
    }
    $user = relationships_addin_user_from_bearer($pdo);
    if ($user === null) {
        relationships_respond(401, ['ok' => false, 'error' => 'Not signed in.']);
    }
    return $user;
}
