<?php
declare(strict_types=1);

/**
 * auth/callback.php
 *
 * Microsoft redirects the browser back here after sign-in, with either
 * ?code=...&state=... (success) or ?error=...&error_description=... (the
 * person cancelled, or something went wrong on Microsoft's side).
 *
 * On success: exchanges the code for an access token, calls Graph /me to
 * get the signed-in person's real name/email/oid, confirms it's really a
 * @codebluetechnology.com account (defense-in-depth -- the app
 * registration's single-tenant restriction should already have prevented
 * anyone else from getting this far), finds-or-creates the matching row
 * in each app's own users table via auth/local-user.php, and stores
 * everything in the one shared session -- see auth/session.php.
 */

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/microsoft-auth.php';
require_once __DIR__ . '/local-user.php';
require_once __DIR__ . '/../relationships/api/db.php';
require_once __DIR__ . '/../register/api/db.php';

function auth_callback_fail(string $message): never
{
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Sign-in failed: {$message}\n\nGo back and try again, or contact IT if this keeps happening.";
    exit;
}

auth_start_session();

if (isset($_GET['error'])) {
    $desc = (string) ($_GET['error_description'] ?? $_GET['error']);
    auth_callback_fail($desc);
}

$code = (string) ($_GET['code'] ?? '');
$state = (string) ($_GET['state'] ?? '');
$expectedState = $_SESSION['auth_state'] ?? null;
unset($_SESSION['auth_state']);

if ($code === '' || $expectedState === null || !hash_equals((string) $expectedState, $state)) {
    auth_callback_fail('the sign-in response could not be verified. Please try signing in again.');
}

$configPath = __DIR__ . '/../auth-config.php';
if (!is_file($configPath)) {
    auth_callback_fail('Microsoft sign-in is not configured on this server yet.');
}
/** @var array{tenant_id:string,client_id:string,client_secret:string,redirect_uri:string,allowed_email_domain?:string} $config */
$config = require $configPath;

$auth = new MicrosoftAuth($config['tenant_id'], $config['client_id'], $config['client_secret'], $config['redirect_uri']);

try {
    $accessToken = $auth->exchangeCodeForAccessToken($code);
    $profile = $auth->fetchProfile($accessToken);
} catch (MicrosoftAuthException $e) {
    auth_callback_fail($e->getMessage());
}

$allowedDomain = strtolower((string) ($config['allowed_email_domain'] ?? 'codebluetechnology.com'));
if (!str_ends_with($profile['email'], '@' . $allowedDomain)) {
    auth_callback_fail('this Microsoft account (' . $profile['email'] . ') is not a @' . $allowedDomain . ' account.');
}

$crcUserId = auth_upsert_local_user(relationships_db(), 'crc_users', $profile['email'], $profile['name']);
$registerUserId = auth_upsert_local_user(register_db(), 'register_users', $profile['email'], $profile['name']);

// Regenerate the session id before writing the new identity into it
// (prevents session fixation -- a pre-auth session id, e.g. from a shared
// kiosk or a crafted link, can never be "upgraded" into a signed-in one).
session_regenerate_id(true);
$_SESSION['auth_name'] = $profile['name'];
$_SESSION['auth_email'] = $profile['email'];
$_SESSION['auth_oid'] = $profile['oid'];
$_SESSION['crc_user_id'] = $crcUserId;
$_SESSION['register_user_id'] = $registerUserId;

$returnTo = auth_safe_return_to($_SESSION['auth_return_to'] ?? null);
unset($_SESSION['auth_return_to']);

header('Location: ' . $returnTo);
exit;
