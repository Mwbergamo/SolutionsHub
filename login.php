<?php
declare(strict_types=1);

/**
 * /login.php
 *
 * Entry point for "Sign in with Microsoft" -- redirects the browser to
 * Entra ID's own sign-in page (where CodeBlue's real MFA/Conditional
 * Access policies apply), carrying a CSRF `state` value and an optional
 * ?return_to= so the user lands back where they started (root
 * SolutionsHub, or a relationships/register deep link) once signed in.
 *
 * GET /login.php?return_to=/relationships/index.html
 */

require_once __DIR__ . '/auth/session.php';
require_once __DIR__ . '/auth/microsoft-auth.php';

$configPath = __DIR__ . '/auth-config.php';
if (!is_file($configPath)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Microsoft sign-in is not configured yet -- copy auth-config.sample.php to auth-config.php and fill in the real Entra ID values.";
    exit;
}
/** @var array{tenant_id:string,client_id:string,client_secret:string,redirect_uri:string} $config */
$config = require $configPath;

auth_start_session();

$state = bin2hex(random_bytes(16));
$_SESSION['auth_state'] = $state;
$_SESSION['auth_return_to'] = auth_safe_return_to($_GET['return_to'] ?? null);

$auth = new MicrosoftAuth(
    $config['tenant_id'],
    $config['client_id'],
    $config['client_secret'],
    $config['redirect_uri']
);

header('Location: ' . $auth->authorizeUrl($state));
exit;
