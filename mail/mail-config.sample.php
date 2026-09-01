<?php
/**
 * mail/mail-config.sample.php
 *
 * Copy this file to mail/mail-config.php on the SERVER (not in git —
 * mail-config.php is listed in .gitignore) and fill in real values there.
 * Never commit real credentials to this repository. (Named "mail-config.php"
 * rather than "config.php" because some hosts block/reserve that filename.)
 *
 * Mail is sent via the Microsoft Graph API (app-only / client credentials
 * OAuth2), not SMTP -- no app password, no legacy basic auth. This requires
 * an Entra ID app registration with the Mail.Send *application* permission,
 * admin-consented, and a client secret:
 *
 *   1. Entra admin center (entra.microsoft.com) -> App registrations ->
 *      New registration.
 *   2. API permissions -> Add a permission -> Microsoft Graph ->
 *      Application permissions -> Mail.Send -> Add, then
 *      "Grant admin consent".
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

    // The mailbox this sends as (its UPN/email address). The app
    // registration's Mail.Send permission must be admin-consented for the
    // tenant (or scoped to just this mailbox via an application access
    // policy, if you want to restrict which mailboxes this app can touch).
    'sender' => 'Solutions@codebluetechnology.com',

    // What recipients see as the sender display name. Best-effort only --
    // Microsoft Graph may override this with the mailbox's actual
    // configured display name instead. For a guaranteed result, set the
    // display name on the mailbox itself in Exchange/Entra.
    'from_name' => 'Solutions Hub',

    // Every outgoing quote email is BCC'd here too, so the account manager
    // has a copy even if the customer's address was mistyped. Leave as ''
    // to disable.
    'bcc' => '',

    // Only requests whose Origin/Referer header matches one of these
    // (scheme + host, no trailing slash) are accepted. Set this to the real
    // site URL(s) once deployed, e.g. ['https://portal.codebluetechnology.com'].
    'allowed_origins' => [
        'https://portal.codebluetechnology.com',
    ],
];
