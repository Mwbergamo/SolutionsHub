<?php
declare(strict_types=1);

/**
 * auth/addin-login.php
 *
 * Entry point for the Outlook add-in's "Sign in" button. Unlike
 * /login.php (which redirects the whole browser tab and, on success,
 * lands back on a normal page), this is opened inside an Office.js
 * dialog (Office.context.ui.displayDialogAsync) from
 * outlook-addin/taskpane.js -- the whole round trip stays inside that
 * dialog, which closes itself once auth/addin-callback.php hands the
 * task pane a bearer token via Office.context.ui.messageParent(). See
 * claude/relationships-outlook-addin-plan.md for why the add-in can't
 * just reuse /login.php and the shared session cookie (its task-pane
 * webview doesn't reliably see that cookie).
 *
 * GET /auth/addin-login.php
 */

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/microsoft-auth.php';

$configPath = __DIR__ . '/../auth-config.php';
if (!is_file($configPath)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Microsoft sign-in is not configured yet -- copy auth-config.sample.php to auth-config.php and fill in the real Entra ID values.";
    exit;
}
/** @var array $config */
$config = require $configPath;

if (empty($config['addin_redirect_uri']) && empty($config['addin_redirect_uris'])) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "The Outlook add-in's sign-in is not configured yet -- add 'addin_redirect_uri' to auth-config.php (and register it as a second Redirect URI on the Entra app registration). See auth-config.sample.php.";
    exit;
}

auth_start_session();

// A separate state key from /login.php's own $_SESSION['auth_state'] --
// the dialog is a separate browser surface from whatever page opened it,
// but Office dialogs have been known to share a webview profile, so
// keeping these independent avoids one flow's state clobbering the
// other's mid-flight if a rep ever has both open at once.
$state = bin2hex(random_bytes(16));
$_SESSION['addin_auth_state'] = $state;

$auth = new MicrosoftAuth(
    $config['tenant_id'],
    $config['client_id'],
    $config['client_secret'],
    auth_addin_redirect_uri($config)
);

header('Location: ' . $auth->authorizeUrl($state));
exit;
