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

/**
 * Installs a safety net so a bug or a hard timeout in an endpoint produces
 * a real JSON error response instead of a broken/partial body -- added
 * 2026-09-13 after catalog.php's sync-step started failing with a generic
 * "Sync failed partway through — check your connection and try again."
 * (app.js's fetch().catch() message, which fires specifically when the
 * response body isn't valid JSON: a transport failure, or a fatal
 * error/timeout that cut the response short). Without this, every such
 * failure looks identical from the client and there's no way to tell a
 * genuine PHP bug from a ConnectWise-side slowdown from Bluehost's
 * execution-time limit still being hit despite the bounded-batch design.
 *
 * set_exception_handler() catches any uncaught Throwable (including PHP 7+
 * Errors like TypeError). The shutdown-function branch additionally tries
 * to catch a genuine max_execution_time timeout: PHP's fatal "Maximum
 * execution time exceeded" error is NOT throwable/catchable normally, but
 * register_shutdown_function() + error_get_last() can still detect it and
 * emit JSON before the response closes, PROVIDED Bluehost's own PHP
 * enforces the limit (vs. an outer Apache/proxy timeout killing the
 * process first, which no PHP-level code can intercept).
 *
 * Call this once, near the top of any endpoint, before doing real work.
 */
function register_install_error_handlers(): void
{
    set_exception_handler(function (Throwable $e): void {
        if (!headers_sent()) {
            register_respond(500, ['ok' => false, 'error' => 'Server error: ' . $e->getMessage()]);
        }
    });

    register_shutdown_function(function (): void {
        $error = error_get_last();
        if ($error === null || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            return;
        }
        if (headers_sent()) {
            return;
        }
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Server error: ' . $error['message']]);
    });
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
