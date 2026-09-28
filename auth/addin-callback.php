<?php
declare(strict_types=1);

/**
 * auth/addin-callback.php
 *
 * Microsoft redirects the DIALOG (opened by auth/addin-login.php via
 * Office.context.ui.displayDialogAsync) back here after sign-in -- added
 * 2026-09-28. Mirrors callback.php's token exchange and profile fetch
 * exactly, but ends differently: instead of setting this site's session
 * cookie (useless here -- the dialog is a separate sandboxed webview that
 * doesn't share cookies with the task pane that opened it), this mints a
 * new opaque bearer token, stores its HASH in addin_tokens, and hands the
 * RAW token back to the task pane via Office.context.ui.messageParent() --
 * the standard pattern for backend auth in an Office Add-in outside
 * Office's own nested SSO. The task pane is responsible for storing that
 * token (e.g. in the add-in's own localStorage/roaming settings) and
 * sending it as `Authorization: Bearer <token>` on every API call from
 * then on -- see relationships/api/_util.php's relationships_bearer_user().
 *
 * The raw token exists in exactly two places, ever: this response (shown
 * once) and the task pane's own storage. This file/table only ever holds
 * its hash from the moment it's generated.
 */

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/microsoft-auth.php';
require_once __DIR__ . '/local-user.php';
require_once __DIR__ . '/../relationships/api/db.php';

/**
 * Renders a minimal HTML page that hands $payload to the task pane via
 * Office.context.ui.messageParent() and then closes itself. Office.js is
 * loaded from Microsoft's own CDN (the same one every Office Add-in must
 * use -- it cannot be self-hosted/bundled, per Microsoft's own add-in
 * platform requirements). If this page is somehow opened OUTSIDE an Office
 * dialog (Office.js present but no parent to message, or Office.js
 * unreachable), it falls back to showing the outcome as plain text instead
 * of silently doing nothing -- a person troubleshooting sign-in needs to
 * see SOMETHING happened.
 */
function addin_callback_render(array $payload): never
{
    header('Content-Type: text/html; charset=utf-8');
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
    $fallbackText = htmlspecialchars(
        ($payload['ok'] ?? false)
            ? 'Signed in. You can close this window.'
            : 'Sign-in failed: ' . (string) ($payload['error'] ?? 'unknown error'),
        ENT_QUOTES
    );
    echo <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Relationships sign-in</title>
<script src="https://appsforoffice.microsoft.com/lib/1/hosted/office.js"></script>
</head>
<body style="font-family: system-ui, sans-serif; padding: 24px; color: #222;">
<p id="fallback">{$fallbackText}</p>
<script>
  var payload = {$json};
  try {
    Office.onReady(function () {
      Office.context.ui.messageParent(JSON.stringify(payload));
    });
  } catch (e) {
    // Office.js unavailable or this wasn't opened as an Office dialog --
    // the plain-text fallback above already covers this case.
  }
</script>
</body>
</html>
HTML;
    exit;
}

function addin_callback_fail(string $message): never
{
    addin_callback_render(['ok' => false, 'error' => $message]);
}

auth_start_session();

if (isset($_GET['error'])) {
    $desc = (string) ($_GET['error_description'] ?? $_GET['error']);
    addin_callback_fail($desc);
}

$code = (string) ($_GET['code'] ?? '');
$state = (string) ($_GET['state'] ?? '');
$expectedState = $_SESSION['addin_auth_state'] ?? null;
unset($_SESSION['addin_auth_state']);

if ($code === '' || $expectedState === null || !hash_equals((string) $expectedState, $state)) {
    addin_callback_fail('the sign-in response could not be verified. Please try signing in again.');
}

$configPath = __DIR__ . '/../auth-config.php';
if (!is_file($configPath)) {
    addin_callback_fail('Microsoft sign-in is not configured on this server yet.');
}
/** @var array{tenant_id:string,client_id:string,client_secret:string,addin_redirect_uri?:string,allowed_email_domain?:string} $config */
$config = require $configPath;

if (empty($config['addin_redirect_uri'])) {
    addin_callback_fail("server is missing addin_redirect_uri in auth-config.php.");
}

$auth = new MicrosoftAuth($config['tenant_id'], $config['client_id'], $config['client_secret'], (string) $config['addin_redirect_uri']);

try {
    $accessToken = $auth->exchangeCodeForAccessToken($code);
    $profile = $auth->fetchProfile($accessToken);
} catch (MicrosoftAuthException $e) {
    addin_callback_fail($e->getMessage());
}

$allowedDomain = strtolower((string) ($config['allowed_email_domain'] ?? 'codebluetechnology.com'));
if (!str_ends_with($profile['email'], '@' . $allowedDomain)) {
    addin_callback_fail('this Microsoft account (' . $profile['email'] . ') is not a @' . $allowedDomain . ' account.');
}

$pdo = relationships_db();
$crcUserId = auth_upsert_local_user($pdo, 'crc_users', $profile['email'], $profile['name']);

// Mint the bearer token -- 32 random bytes, hex-encoded (64 chars), well
// outside the range any real-world value could collide with. Only the
// SHA-256 hash is ever stored (see this file's header).
$rawToken = bin2hex(random_bytes(32));
$tokenHash = hash('sha256', $rawToken);
$pdo->prepare('INSERT INTO addin_tokens (crc_user_id, email, token_hash) VALUES (:uid, :email, :hash)')
    ->execute([':uid' => $crcUserId, ':email' => $profile['email'], ':hash' => $tokenHash]);

addin_callback_render([
    'ok' => true,
    'token' => $rawToken,
    'name' => $profile['name'],
    'email' => $profile['email'],
]);
