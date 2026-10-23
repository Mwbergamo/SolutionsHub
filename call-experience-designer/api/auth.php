<?php
/**
 * call-experience-designer/api/auth.php
 *
 * Accounts for the Call In Experience Designer app. Same as ratesheet/
 * api/auth.php, register/api/auth.php, relationships/api/auth.php: no
 * local login of its own -- everyone signs in once via Microsoft 365 at
 * the SolutionsHub root (/login.php), recognized here through the shared
 * session (see auth/session.php and call_experience_current_user() in
 * _util.php). This endpoint only reports who's signed in and signs them
 * out.
 *
 * Unlike ratesheet, there is no admin/roster concept here -- every
 * signed-in rep can send a Call In Experience link and sees every
 * request on the dashboard, so there is no is_admin flag to report.
 *
 * GET  /call-experience-designer/api/auth.php?action=me
 * POST /call-experience-designer/api/auth.php?action=logout
 */

declare(strict_types=1);

require_once __DIR__ . '/_util.php';

$pdo = call_experience_db();
call_experience_start_session();

$action = $_GET['action'] ?? '';

if ($action === 'me') {
    $user = call_experience_current_user($pdo);
    call_experience_respond(200, [
        'ok' => true,
        'user' => $user,
    ]);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    call_experience_respond(405, ['ok' => false, 'error' => 'Method not allowed.']);
}

if ($action === 'logout') {
    require_once __DIR__ . '/../../auth/session.php';
    auth_logout();
    call_experience_respond(200, ['ok' => true]);
}

call_experience_respond(400, ['ok' => false, 'error' => 'Unknown action. Sign-in is now handled at /login.php.']);
