<?php
/**
 * register/api/_util.php
 *
 * Small shared helpers for every register/api/*.php endpoint: JSON
 * responses, the login session check, and a lightweight origin check --
 * same shape as relationships/api/_util.php (this app is same-origin/
 * same-site, so this is defense-in-depth, not real CORS handling).
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

function register_respond(int $httpCode, array $payload): never
{
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($httpCode);
    echo json_encode($payload);
    exit;
}

function register_read_json_body(int $maxBytes = 262144): array
{
    $raw = file_get_contents('php://input', false, null, 0, $maxBytes);
    $data = json_decode((string) $raw, true);
    return is_array($data) ? $data : [];
}

function register_start_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        // Same reasoning as relationships/api/_util.php: Bluehost's default
        // session.save_path can silently fail to persist, so use our own
        // directory next to the SQLite database, which we already know is
        // writable.
        $sessionDir = __DIR__ . '/../data/sessions';
        if (!is_dir($sessionDir)) {
            mkdir($sessionDir, 0770, true);
        }
        session_save_path($sessionDir);

        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/register/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

function register_current_user(PDO $pdo): ?array
{
    register_start_session();
    $userId = $_SESSION['user_id'] ?? null;
    if (!is_int($userId)) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT id, name, email FROM register_users WHERE id = :id');
    $stmt->execute([':id' => $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}

/**
 * Call at the top of any endpoint that requires a logged-in retail staff
 * account. Responds with 401 and exits if there's no active session.
 */
function register_require_login(PDO $pdo): array
{
    $user = register_current_user($pdo);
    if ($user === null) {
        register_respond(401, ['ok' => false, 'error' => 'Not signed in.']);
    }
    return $user;
}
