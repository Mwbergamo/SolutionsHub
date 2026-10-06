<?php
/**
 * commissions/api/auth.php
 *
 * GET  ?action=me      -> { ok: true, user: { name, email } }  (401 / 403 otherwise)
 * POST ?action=logout  -> signs the shared session out
 */

declare(strict_types=1);

require_once __DIR__ . '/_util.php';

$action = $_GET['action'] ?? 'me';

if ($action === 'logout') {
    commissions_require_post();
    auth_logout();
    commissions_respond(200, ['ok' => true]);
}

$user = commissions_require_access();
commissions_respond(200, ['ok' => true, 'user' => $user + ['is_manager' => commissions_is_manager($user['email'])]]);
