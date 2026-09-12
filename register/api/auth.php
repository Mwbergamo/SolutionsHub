<?php
/**
 * register/api/auth.php
 *
 * Accounts for the Register retail checkout app. Same small, self-contained
 * auth system as relationships/api/auth.php (name/email/password, hashed
 * with password_hash(), PHP native sessions) -- separate accounts table
 * (register_users) from the Relationships CRC dashboard, since retail
 * staff and Relationship Coordinators aren't necessarily the same people,
 * but every sale needs to record who rang it up, same reasoning as
 * checklist steps needing to know which CRC completed them.
 *
 * POST /register/api/auth.php?action=register  { name, email, password }
 * POST /register/api/auth.php?action=login      { email, password }
 * POST /register/api/auth.php?action=logout     {}
 * GET  /register/api/auth.php?action=me
 *
 * Every response is JSON: { ok: true, user: {...} } or { ok: false, error }.
 */

declare(strict_types=1);

require_once __DIR__ . '/_util.php';

$pdo = register_db();
register_start_session();

$action = $_GET['action'] ?? '';

if ($action === 'me') {
    $user = register_current_user($pdo);
    register_respond(200, ['ok' => true, 'user' => $user]);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    register_respond(405, ['ok' => false, 'error' => 'Method not allowed.']);
}

if ($action === 'logout') {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', $params['secure'], $params['httponly']);
    }
    session_destroy();
    register_respond(200, ['ok' => true]);
}

if ($action === 'register') {
    $data = register_read_json_body();
    $name = trim((string) ($data['name'] ?? ''));
    $email = strtolower(trim((string) ($data['email'] ?? '')));
    $password = (string) ($data['password'] ?? '');

    if ($name === '' || mb_strlen($name) > 120) {
        register_respond(400, ['ok' => false, 'error' => 'Enter your name.']);
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        register_respond(400, ['ok' => false, 'error' => 'Enter a valid email address.']);
    }
    // This app records real sales -- restrict self-service registration to
    // CodeBlue staff rather than leaving it open to anyone who finds the
    // login page. Same restriction as relationships/api/auth.php.
    if (!str_ends_with($email, '@codebluetechnology.com')) {
        register_respond(400, ['ok' => false, 'error' => 'Registration is limited to @codebluetechnology.com email addresses.']);
    }
    if (mb_strlen($password) < 8) {
        register_respond(400, ['ok' => false, 'error' => 'Password must be at least 8 characters.']);
    }

    $existing = $pdo->prepare('SELECT id FROM register_users WHERE email = :email');
    $existing->execute([':email' => $email]);
    if ($existing->fetch() !== false) {
        register_respond(409, ['ok' => false, 'error' => 'An account with that email already exists — try signing in instead.']);
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $insert = $pdo->prepare('INSERT INTO register_users (name, email, password_hash) VALUES (:name, :email, :hash)');
    $insert->execute([':name' => $name, ':email' => $email, ':hash' => $hash]);
    $userId = (int) $pdo->lastInsertId();

    $_SESSION['user_id'] = $userId;
    register_respond(200, ['ok' => true, 'user' => ['id' => $userId, 'name' => $name, 'email' => $email]]);
}

if ($action === 'login') {
    $data = register_read_json_body();
    $email = strtolower(trim((string) ($data['email'] ?? '')));
    $password = (string) ($data['password'] ?? '');

    $stmt = $pdo->prepare('SELECT id, name, email, password_hash FROM register_users WHERE email = :email');
    $stmt->execute([':email' => $email]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    // Same generic error whether the email doesn't exist or the password is
    // wrong, so this endpoint never confirms which accounts exist.
    if ($row === false || !password_verify($password, $row['password_hash'])) {
        register_respond(401, ['ok' => false, 'error' => 'Incorrect email or password.']);
    }

    $_SESSION['user_id'] = (int) $row['id'];
    register_respond(200, ['ok' => true, 'user' => ['id' => (int) $row['id'], 'name' => $row['name'], 'email' => $row['email']]]);
}

register_respond(400, ['ok' => false, 'error' => 'Unknown action.']);
