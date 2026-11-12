<?php
declare(strict_types=1);

/**
 * marketing/api/auth.php
 *
 * GET  ?action=me      -> { ok: true, user: { name, email } }  (401 otherwise)
 * POST ?action=logout  -> signs the shared session out
 *
 * Same thin-wrapper-around-the-shared-session shape as
 * client-finance/api/auth.php -- unlike that app, there's no access
 * restriction here: every signed-in rep can use Marketing (Michael,
 * 2026-11-12: "All signed-in reps").
 */

require_once __DIR__ . '/_util.php';

$action = $_GET['action'] ?? 'me';

if ($action === 'logout') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        marketing_respond(405, ['ok' => false, 'error' => 'Method not allowed.']);
    }
    require_once __DIR__ . '/../../auth/session.php';
    auth_logout();
    marketing_respond(200, ['ok' => true]);
}

$user = marketing_require_login();
marketing_respond(200, ['ok' => true, 'user' => $user]);
