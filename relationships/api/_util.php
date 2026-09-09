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

function relationships_start_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        // Shared hosting (this app runs on Bluehost) can point PHP's
        // default session.save_path somewhere this account isn't actually
        // able to write to, or prunes it aggressively -- when that happens
        // session_start() doesn't error, it just silently fails to persist
        // anything, so login/register look like they succeed but the very
        // next request comes back signed-out. Use our own directory, which
        // we already know is writable (db.php creates the SQLite file next
        // to it), instead of trusting the server default. It's covered by
        // data/.htaccess's "Deny from all", same as the database file.
        $sessionDir = __DIR__ . '/../data/sessions';
        if (!is_dir($sessionDir)) {
            mkdir($sessionDir, 0770, true);
        }
        session_save_path($sessionDir);

        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/relationships/',
            'httponly' => true,
            'samesite' => 'Lax',
            // True only on an actual HTTPS request, so this still works
            // during local testing over plain HTTP.
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        ]);
        session_name('relationships_session');
        session_start();
    }
}

/**
 * Returns the logged-in user's row (id, name, email) or null. Never throws.
 */
function relationships_current_user(PDO $pdo): ?array
{
    relationships_start_session();
    $userId = $_SESSION['user_id'] ?? null;
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
