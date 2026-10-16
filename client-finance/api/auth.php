<?php
/**
 * client-finance/api/auth.php
 *
 * GET  ?action=me      -> { ok: true, user: { name, email } }  (401 / 403 otherwise)
 * POST ?action=logout  -> signs the shared session out
 */

declare(strict_types=1);

require_once __DIR__ . '/_util.php';

$action = $_GET['action'] ?? 'me';

if ($action === 'logout') {
    client_finance_require_post();
    auth_logout();
    client_finance_respond(200, ['ok' => true]);
}

$user = client_finance_require_access();
client_finance_respond(200, ['ok' => true, 'user' => $user]);
