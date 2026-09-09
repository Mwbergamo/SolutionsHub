<?php
/**
 * relationships/api/auth.php
 *
 * Accounts for the Relationships dashboard. This is a small, self-contained
 * auth system (name/email/password, hashed with password_hash(), PHP native
 * sessions) — there's no existing login system on portal.codebluetechnology.com
 * to hook into today, and the dashboard's checklist needs to know WHICH CRC
 * completed each step, not just when.
 *
 * POST /relationships/api/auth.php?action=register  { name, email, password }
 * POST /relationships/api/auth.php?action=login      { email, password }
 * POST /relationships/api/auth.php?action=logout     {}
 * GET  /relationships/api/auth.php?action=me
 *
 * Every response is JSON: { ok: true, user: {...} } or { ok: false, error }.
 */

declare(strict_types=1);

require_once __DIR__ . '/_util.php';

$pdo = relationships_db();
relationships_start_session();

$action = $_GET['action'] ?? '';

if ($action === 'me') {
    $user = relationships_current_user($pdo);
    relationships_respond(200, ['ok' => true, 'user' => $user]);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    relationships_respond(405, ['ok' => false, 'error' => 'Method not allowed.']);
}

if ($action === 'logout') {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', $params['secure'], $params['httponly']);
    }
    session_destroy();
    relationships_respond(200, ['ok' => true]);
}

if ($action === 'register') {
    $data = relationships_read_json_body();
    $name = trim((string) ($data['name'] ?? ''));
    $email = strtolower(trim((string) ($data['email'] ?? '')));
    $password = (string) ($data['password'] ?? '');

    if ($name === '' || mb_strlen($name) > 120) {
        relationships_respond(400, ['ok' => false, 'error' => 'Enter your name.']);
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        relationships_respond(400, ['ok' => false, 'error' => 'Enter a valid email address.']);
    }
    if (mb_strlen($password) < 8) {
        relationships_respond(400, ['ok' => false, 'error' => 'Password must be at least 8 characters.']);
    }

    $existing = $pdo->prepare('SELECT id FROM crc_users WHERE email = :email');
    $existing->execute([':email' => $email]);
    if ($existing->fetch() !== false) {
        relationships_respond(409, ['ok' => false, 'error' => 'An account with that email already exists — try signing in instead.']);
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $insert = $pdo->prepare('INSERT INTO crc_users (name, email, password_hash) VALUES (:name, :email, :hash)');
    $insert->execute([':name' => $name, ':email' => $email, ':hash' => $hash]);
    $userId = (int) $pdo->lastInsertId();

    $_SESSION['user_id'] = $userId;
    relationships_respond(200, ['ok' => true, 'user' => ['id' => $userId, 'name' => $name, 'email' => $email]]);
}

if ($action === 'login') {
    $data = relationships_read_json_body();
    $email = strtolower(trim((string) ($data['email'] ?? '')));
    $password = (string) ($data['password'] ?? '');

    $stmt = $pdo->prepare('SELECT id, name, email, password_hash FROM crc_users WHERE email = :email');
    $stmt->execute([':email' => $email]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    // Same generic error whether the email doesn't exist or the password is
    // wrong, so this endpoint never confirms which accounts exist.
    if ($row === false || !password_verify($password, $row['password_hash'])) {
        relationships_respond(401, ['ok' => false, 'error' => 'Incorrect email or password.']);
    }

    $_SESSION['user_id'] = (int) $row['id'];
    relationships_respond(200, ['ok' => true, 'user' => ['id' => (int) $row['id'], 'name' => $row['name'], 'email' => $row['email']]]);
}

relationships_respond(400, ['ok' => false, 'error' => 'Unknown action.']);
