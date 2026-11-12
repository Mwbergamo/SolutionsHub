<?php
/**
 * myday/api/auth.php
 *
 * "Who's signed in" for My Day. Unlike relationships/api/auth.php and
 * client-finance/api/auth.php, this never resolves its own local
 * app-specific user row -- My Day has no local database of its own (see
 * digest.php's doc comment), so there's nothing app-specific to look up.
 * This is a thin wrapper around the one shared Microsoft 365 session
 * every SolutionsHub app already uses (auth/session.php, two levels up).
 *
 * GET  /myday/api/auth.php?action=me
 * POST /myday/api/auth.php?action=logout
 *
 * Every response is JSON: { ok: true, user: {...}|null } or
 * { ok: false, error }.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../auth/session.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

auth_start_session();

$action = $_GET['action'] ?? '';

if ($action === 'me') {
    echo json_encode(['ok' => true, 'user' => auth_current_user()]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
    exit;
}

if ($action === 'logout') {
    auth_logout();
    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Unknown action. Sign-in is handled at /login.php.']);
