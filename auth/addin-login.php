<?php
declare(strict_types=1);

/**
 * auth/addin-login.php
 *
 * Entry point for the Outlook add-in's "sign in" -- added 2026-09-28 (see
 * claude/relationships-outlook-addin-plan.md). The task pane opens this
 * URL inside Office's Dialog API (Office.context.ui.displayDialogAsync),
 * NOT a normal browser tab -- otherwise this is the exact same Entra
 * Authorization Code flow /login.php already runs (same MicrosoftAuth
 * class, same app registration, same tenant restriction), just with a
 * DIFFERENT redirect_uri so Microsoft sends the browser back to
 * addin-callback.php (which hands the task pane a bearer token) instead of
 * callback.php (which sets this site's session cookie -- useless to a
 * sandboxed dialog webview that doesn't share cookies with the task pane).
 *
 * ONE-TIME SERVER SETUP THIS DEPENDS ON, per the plan's auth section:
 * addin_redirect_uri below must ALSO be added as a second Redirect URI on
 * the SAME Entra app registration /login.php uses (Entra ID app
 * registrations support multiple redirect URIs under one app -- this does
 * NOT need a second app registration). Michael holds the Microsoft 365
 * admin access this requires (per his own confirmation, 2026-09-28) --
 * Entra admin center -> App registrations -> [the SolutionsHub Sign-In
 * app] -> Authentication -> Add URI ->
 * https://portal.codebluetechnology.com/auth/addin-callback.php
 *
 * GET /auth/addin-login.php
 *   (no query params -- the dialog is opened fresh each time the task pane
 *   needs a new sign-in; there's no return_to concept here, since
 *   addin-callback.php's whole job is to close the dialog, not navigate
 *   anywhere the person would see.)
 */

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/microsoft-auth.php';

$configPath = __DIR__ . '/../auth-config.php';
if (!is_file($configPath)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Microsoft sign-in is not configured yet.';
    exit;
}
/** @var array{tenant_id:string,client_id:string,client_secret:string,redirect_uri:string,addin_redirect_uri?:string} $config */
$config = require $configPath;

if (empty($config['addin_redirect_uri'])) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "The Outlook add-in's sign-in is not configured yet -- auth-config.php is missing addin_redirect_uri. See auth/addin-login.php's file header for the one-time Entra setup this needs.";
    exit;
}

auth_start_session();

// A separate session key from auth_state (used by the normal /login.php
// flow) -- a person could plausibly have both a normal browser sign-in
// tab and an add-in dialog open at once (different windows entirely), and
// they must not be able to clobber each other's pending CSRF state.
$state = bin2hex(random_bytes(16));
$_SESSION['addin_auth_state'] = $state;

$auth = new MicrosoftAuth(
    $config['tenant_id'],
    $config['client_id'],
    $config['client_secret'],
    (string) $config['addin_redirect_uri']
);

header('Location: ' . $auth->authorizeUrl($state));
exit;
