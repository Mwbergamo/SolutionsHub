<?php
/**
 * ratesheet/api/auth.php
 *
 * Accounts for the Customer Rate Sheet Sign Up app. Same as register/api/
 * auth.php and relationships/api/auth.php: no local login of its own --
 * everyone signs in once via Microsoft 365 at the SolutionsHub root
 * (/login.php), recognized here through the shared session (see
 * auth/session.php and ratesheet_current_user() in _util.php). This
 * endpoint only reports who's signed in and signs them out.
 *
 * GET  /ratesheet/api/auth.php?action=me
 * POST /ratesheet/api/auth.php?action=logout
 */

declare(strict_types=1);

require_once __DIR__ . '/_util.php';

$pdo = ratesheet_db();
ratesheet_start_session();

$action = $_GET['action'] ?? '';

if ($action === 'me') {
    $user = ratesheet_current_user($pdo);
    ratesheet_respond(200, [
        'ok' => true,
        'user' => $user,
        'is_admin' => $user !== null && ratesheet_is_admin($user),
    ]);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    ratesheet_respond(405, ['ok' => false, 'error' => 'Method not allowed.']);
}

if ($action === 'logout') {
    require_once __DIR__ . '/../../auth/session.php';
    auth_logout();
    ratesheet_respond(200, ['ok' => true]);
}

ratesheet_respond(400, ['ok' => false, 'error' => 'Unknown action. Sign-in is now handled at /login.php.']);
