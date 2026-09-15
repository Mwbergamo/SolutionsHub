<?php
/**
 * register/api/auth.php
 *
 * Accounts for the Register retail checkout app. As of 2026-09-15 this no
 * longer runs its own name/email/password login -- everyone signs in once
 * via Microsoft 365 at the SolutionsHub root (/login.php), which is
 * recognized here automatically through the shared session (see
 * auth/session.php and register_current_user() in _util.php, which
 * replaced the old email+password register_users check). This endpoint
 * now only reports who's signed in and signs them out; the register/login
 * actions it used to handle are gone.
 *
 * GET  /register/api/auth.php?action=me
 * POST /register/api/auth.php?action=logout
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
    require_once __DIR__ . '/../../auth/session.php';
    auth_logout();
    register_respond(200, ['ok' => true]);
}

register_respond(400, ['ok' => false, 'error' => 'Unknown action. Sign-in is now handled at /login.php.']);
