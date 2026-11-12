<?php
declare(strict_types=1);

/**
 * auth/addin-callback.php
 *
 * Mirrors auth/callback.php's OAuth exchange, but for the Outlook add-in's
 * sign-in dialog (see auth/addin-login.php) instead of the normal browser
 * flow. Differences from auth/callback.php:
 *   - Validates $_SESSION['addin_auth_state'], not 'auth_state'.
 *   - On success, mints an add-in bearer token
 *     (relationships_addin_mint_token() in relationships/api/_util.php)
 *     instead of writing $_SESSION['crc_user_id'] -- the task pane's own
 *     webview won't reliably see this PHP session later anyway, which is
 *     the whole reason the add-in needs bearer tokens in the first place.
 *   - Never redirects with a Location header. Office dialogs expect the
 *     dialog's own content to call Office.context.ui.messageParent() with
 *     the result and let the task pane close the dialog -- so both
 *     success and failure render a tiny HTML page that does exactly that.
 *
 * Only crc_users (Relationships' own user table) is touched here -- the
 * add-in only ever calls into relationships/api/outlook-addin.php, so
 * there's no need to upsert register/ratesheet/call-experience rows the
 * way auth/callback.php does for the full shared sign-in.
 *
 * GET /auth/addin-callback.php?code=...&state=...  (or ?error=...)
 */

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/microsoft-auth.php';
require_once __DIR__ . '/local-user.php';
require_once __DIR__ . '/../relationships/api/db.php';
require_once __DIR__ . '/../relationships/api/_util.php';

/**
 * Renders the dialog-closing page and exits. $payload is whatever
 * outlook-addin/taskpane.js's displayDialogAsync message handler expects
 * via messageParent -- either {ok:true, token, name, email} or
 * {ok:false, error}. json_encode()'s output is safe to inline directly
 * into a <script> block: it never produces an unescaped "</script" (every
 * "/" is itself ASCII and contains no "<", and PHP's default encoding
 * does not emit raw "<" or ">" for ordinary string content either way),
 * and nothing here is attacker-controlled HTML -- $payload's strings are
 * either our own static text or Microsoft's own profile/error fields,
 * never rendered as markup.
 */
function auth_addin_callback_finish(array $payload): never
{
    header('Content-Type: text/html; charset=utf-8');
    // $json is JSON, which is also valid JS object-literal syntax -- safe
    // to inline directly as `var payload = {$json};` below. It's never
    // attacker-controlled HTML (our own static text or Microsoft's own
    // profile/error strings, never rendered as markup), and JSON's
    // escaping already neutralizes anything that could break out of the
    // <script> block.
    $json = (string) json_encode($payload);
    echo <<<HTML
<!doctype html>
<html>
<head><meta charset="utf-8"><title>Signing in…</title></head>
<body>
<script src="https://appsforoffice.microsoft.com/lib/1/hosted/office.js"></script>
<script>
  var payload = {$json};
  Office.onReady(function () {
    Office.context.ui.messageParent(JSON.stringify(payload));
  });
</script>
<p>You can close this window if it doesn't close on its own.</p>
</body>
</html>
HTML;
    exit;
}

auth_start_session();

if (isset($_GET['error'])) {
    $desc = (string) ($_GET['error_description'] ?? $_GET['error']);
    auth_addin_callback_finish(['ok' => false, 'error' => $desc]);
}

$code = (string) ($_GET['code'] ?? '');
$state = (string) ($_GET['state'] ?? '');
$expectedState = $_SESSION['addin_auth_state'] ?? null;
unset($_SESSION['addin_auth_state']);

if ($code === '' || $expectedState === null || !hash_equals((string) $expectedState, $state)) {
    auth_addin_callback_finish(['ok' => false, 'error' => 'The sign-in response could not be verified. Please try signing in again.']);
}

$configPath = __DIR__ . '/../auth-config.php';
if (!is_file($configPath)) {
    auth_addin_callback_finish(['ok' => false, 'error' => 'Microsoft sign-in is not configured on this server yet.']);
}
/** @var array $config */
$config = require $configPath;

$auth = new MicrosoftAuth($config['tenant_id'], $config['client_id'], $config['client_secret'], auth_addin_redirect_uri($config));

try {
    $accessToken = $auth->exchangeCodeForAccessToken($code);
    $profile = $auth->fetchProfile($accessToken);
} catch (MicrosoftAuthException $e) {
    auth_addin_callback_finish(['ok' => false, 'error' => $e->getMessage()]);
}

$allowedDomain = strtolower((string) ($config['allowed_email_domain'] ?? 'codebluetechnology.com'));
if (!str_ends_with($profile['email'], '@' . $allowedDomain)) {
    auth_addin_callback_finish(['ok' => false, 'error' => 'This Microsoft account (' . $profile['email'] . ') is not a @' . $allowedDomain . ' account.']);
}

$crcUserId = auth_upsert_local_user(relationships_db(), 'crc_users', $profile['email'], $profile['name']);
$rawToken = relationships_addin_mint_token(relationships_db(), $crcUserId, $profile['email']);

auth_addin_callback_finish([
    'ok' => true,
    'token' => $rawToken,
    'name' => $profile['name'],
    'email' => $profile['email'],
]);
