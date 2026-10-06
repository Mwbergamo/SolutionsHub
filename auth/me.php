<?php
declare(strict_types=1);

/**
 * auth/me.php
 *
 * GET-only JSON endpoint reporting who's signed in via the shared
 * Microsoft 365 session (see auth/session.php), or null if nobody is.
 * Used by the root SolutionsHub app's sign-in gate (index.html) before it
 * mounts. relationships/ and register/ each have their own equivalent
 * ?action=me on their own api/auth.php (same shared session underneath,
 * kept as separate endpoints since those apps' app.js already call them
 * by that URL).
 *
 * As of 2026-10-05 the user also carries `can_view_commissions` (see
 * auth/commissions-access.php) so the home page can hide the Commissions
 * card from everyone who isn't on that allow-list.
 *
 * Response: { ok: true, user: {...} | null }
 */

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/commissions-access.php';

header('Content-Type: application/json; charset=utf-8');
$user = auth_current_user();
if ($user !== null) {
    $user['can_view_commissions'] = commissions_email_allowed($user['email'] ?? '');
}
echo json_encode(['ok' => true, 'user' => $user]);
