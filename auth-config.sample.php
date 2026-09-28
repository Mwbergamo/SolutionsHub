<?php
/**
 * auth-config.sample.php
 *
 * Copy this file to auth-config.php on the SERVER (not in git --
 * auth-config.php is in .gitignore) and fill in real values there. Never
 * commit real credentials to this repository.
 *
 * Powers "Sign in with Microsoft" across every SolutionsHub app (root,
 * relationships/, register/) via one shared session -- see
 * auth/session.php. Added 2026-09-15, replacing three previously-separate
 * per-app email/password logins with a single CodeBlue Microsoft 365
 * sign-in.
 *
 * This is a SEPARATE Entra ID app registration from mail/mail-config.php's
 * -- that one sends mail app-only, as a shared mailbox, with no user
 * present (Mail.Send *application* permission). This one signs a real
 * person in with their own delegated permissions (openid/profile/email/
 * User.Read). Keeping them as two separate app registrations means a
 * compromised credential in one has no bearing on the other.
 *
 *   1. Entra admin center (entra.microsoft.com) -> App registrations ->
 *      New registration.
 *      - Name: e.g. "SolutionsHub Sign-In"
 *      - Supported account types: "Accounts in this organizational
 *        directory only (CodeBlue Technology only - Single tenant)" --
 *        this is what actually restricts sign-in to CodeBlue's own
 *        Microsoft 365 accounts; nobody outside the tenant can even reach
 *        the consent screen.
 *      - Redirect URI: platform "Web",
 *        https://portal.codebluetechnology.com/auth/callback.php
 *
 *      ADDED 2026-09-28 for the Outlook add-in ("Send to Relationships"):
 *      on this SAME app registration's Authentication page, add a SECOND
 *      Redirect URI (also platform "Web", no second app registration
 *      needed): https://portal.codebluetechnology.com/auth/addin-callback.php
 *      -- this is the one piece of Entra setup this integration cannot do
 *      from here; it needs whoever holds Microsoft 365 admin access
 *      (Michael, per his own confirmation 2026-09-28).
 *   2. API permissions -> Add a permission -> Microsoft Graph ->
 *      Delegated permissions -> add openid, profile, email
 *      (User.Read is included by default) -> "Grant admin consent".
 *   3. Certificates & secrets -> New client secret -> copy the VALUE
 *      immediately (shown once).
 *   4. tenant_id / client_id below come from the app registration's
 *      Overview page.
 */

return [
    // Entra ID (Azure AD) tenant ID -- a GUID, from the app registration's
    // Overview page ("Directory (tenant) ID").
    'tenant_id' => 'REPLACE_ME',

    // Application (client) ID of the Entra ID app registration.
    'client_id' => 'REPLACE_ME',

    // Client secret VALUE (not the secret ID) from Certificates & secrets.
    // KEEP THIS OUT OF GIT.
    'client_secret' => 'REPLACE_ME',

    // Must exactly match the Redirect URI configured on the app
    // registration (scheme + host + path -- no trailing slash difference).
    'redirect_uri' => 'https://portal.codebluetechnology.com/auth/callback.php',

    // The Outlook add-in's OWN redirect URI -- added 2026-09-28. Same app
    // registration, same tenant/client id/secret above, just a second
    // Redirect URI entry (see this file's setup notes above) so Microsoft
    // sends the add-in's sign-in dialog to auth/addin-callback.php instead
    // of auth/callback.php. auth/addin-login.php refuses to start a
    // sign-in if this key is missing, rather than silently reusing the
    // wrong redirect_uri and failing with a confusing Microsoft-side
    // mismatch error.
    'addin_redirect_uri' => 'https://portal.codebluetechnology.com/auth/addin-callback.php',

    // Defense-in-depth alongside the tenant restriction above -- only
    // emails ending in this are allowed to complete sign-in, in case the
    // tenant ever grows guest/B2B accounts from other domains.
    'allowed_email_domain' => 'codebluetechnology.com',
];
