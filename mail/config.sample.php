<?php
/**
 * mail/config.sample.php
 *
 * Copy this file to mail/config.php on the SERVER (not in git — config.php
 * is listed in .gitignore) and fill in real values there. Never commit real
 * credentials to this repository.
 *
 * For Gmail / Google Workspace:
 *   - host: smtp.gmail.com, port: 587, encryption: 'tls'
 *   - username: the full mailbox address
 *   - password: a 16-character App Password (Google Account -> Security ->
 *     2-Step Verification -> App passwords). A normal account password will
 *     NOT work if 2-Step Verification is on, which it should be.
 *
 * For Microsoft 365 / Outlook:
 *   - host: smtp.office365.com, port: 587, encryption: 'tls'
 *   - username: the full mailbox address
 *   - password: the mailbox password, or an app password if MFA is enforced
 *     and basic auth SMTP AUTH is allowed on the tenant.
 */

return [
    // SMTP server hostname, e.g. 'smtp.gmail.com' or 'smtp.office365.com'
    'host' => 'smtp.example.com',

    // Usually 587 (STARTTLS) — this sender only supports STARTTLS, not
    // implicit TLS on 465.
    'port' => 587,

    // 'tls' (STARTTLS) is what Gmail/Google Workspace and M365 both expect
    // on port 587. Leave as 'tls' unless your provider says otherwise.
    'encryption' => 'tls',

    // Full mailbox address used to authenticate (SMTP AUTH LOGIN).
    'username' => 'quotes@example.com',

    // App password / mailbox password. KEEP THIS OUT OF GIT.
    'password' => 'REPLACE_ME',

    // What recipients see as the sender. Should normally match (or be an
    // alias of) the authenticated mailbox above, or the message may be
    // flagged as spam / spoofed by the receiving server.
    'from_email' => 'quotes@example.com',
    'from_name' => 'CodeBlue Technology',

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
