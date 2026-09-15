<?php
declare(strict_types=1);

/**
 * /logout.php
 *
 * Clears the ONE shared session used by every SolutionsHub app, so
 * signing out here signs a person out of root/relationships/register all
 * at once. See auth/session.php.
 *
 * GET /logout.php?return_to=/relationships/login.html
 */

require_once __DIR__ . '/auth/session.php';

auth_logout();

$returnTo = auth_safe_return_to($_GET['return_to'] ?? null);
header('Location: ' . $returnTo);
exit;
