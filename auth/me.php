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
 * Response: { ok: true, user: {...} | null }
 */

require_once __DIR__ . '/session.php';

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => true, 'user' => auth_current_user()]);
