<?php
declare(strict_types=1);

/**
 * auth/session.php
 *
 * The ONE shared PHP session used by every SolutionsHub app -- root,
 * relationships/, and register/ -- so signing in once (see /login.php and
 * auth/callback.php) is recognized everywhere. Added 2026-09-15, replacing
 * three previously-separate per-app logins with a single Microsoft 365
 * sign-in; see auth-config.sample.php for the Entra ID setup this depends
 * on.
 *
 * The key difference from the old relationships/register per-app session
 * setup this replaces: cookie path "/" (the whole site) instead of
 * "/relationships/" or "/register/", and one shared session name/save
 * path, so the same cookie is sent on every request to the domain
 * regardless of which app's folder it's under.
 */

function auth_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    // Same reasoning as the per-app _util.php files this replaces:
    // Bluehost's default session.save_path can silently fail to persist,
    // so use our own directory -- covered by data/.htaccess's
    // "Deny from all", same as relationships/data and register/data.
    $sessionDir = __DIR__ . '/../data/sessions';
    if (!is_dir($sessionDir)) {
        mkdir($sessionDir, 0770, true);
    }
    session_save_path($sessionDir);

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        // True only on an actual HTTPS request, so this still works during
        // local testing over plain HTTP.
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_name('shub_session');
    session_start();
}

/**
 * Returns the signed-in user's shared profile, or null if nobody's signed
 * in. This is the SAME shape every app's own current-user helper reads
 * from -- see relationships/api/_util.php's relationships_current_user()
 * and register/api/_util.php's register_current_user(), which each pull
 * their own app-specific numeric id (crc_user_id / register_user_id) back
 * out of this same session.
 *
 * @return array{name: string, email: string, oid: string, crc_user_id: ?int, register_user_id: ?int}|null
 */
function auth_current_user(): ?array
{
    auth_start_session();
    $email = $_SESSION['auth_email'] ?? null;
    if (!is_string($email) || $email === '') {
        return null;
    }
    return [
        'name' => (string) ($_SESSION['auth_name'] ?? ''),
        'email' => $email,
        'oid' => (string) ($_SESSION['auth_oid'] ?? ''),
        'crc_user_id' => isset($_SESSION['crc_user_id']) ? (int) $_SESSION['crc_user_id'] : null,
        'register_user_id' => isset($_SESSION['register_user_id']) ? (int) $_SESSION['register_user_id'] : null,
    ];
}

/** Clears the shared session -- used by /logout.php. */
function auth_logout(): void
{
    auth_start_session();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', $params['secure'], $params['httponly']);
    }
    session_destroy();
}

/**
 * Only allows a relative, same-site path through -- used to validate
 * /login.php's and /logout.php's ?return_to= so a crafted link can't send
 * a signed-in session off to an attacker-controlled site (open-redirect).
 */
function auth_safe_return_to(?string $path, string $default = '/index.html'): string
{
    if ($path === null || $path === '') {
        return $default;
    }
    // Must start with a single "/" (a relative site path) -- reject
    // "//evil.com" (protocol-relative) and anything carrying a scheme.
    if (!str_starts_with($path, '/') || str_starts_with($path, '//')) {
        return $default;
    }
    if (str_contains($path, "\r") || str_contains($path, "\n")) {
        return $default;
    }
    return $path;
}
