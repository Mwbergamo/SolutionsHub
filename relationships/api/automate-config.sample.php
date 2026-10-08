<?php
/**
 * relationships/api/automate-config.sample.php
 *
 * Copy this file to relationships/api/automate-config.php on the SERVER
 * (not in git -- automate-config.php is listed in .gitignore) and fill in
 * real values there. Never commit real credentials, and never paste them
 * into chat, tickets or screenshots.
 *
 * These come from CodeBlue's ConnectWise Automate server
 * (automate.codebluetechnology.com):
 *   - client_id: the GUID registered for this integration at
 *     developer.connectwise.com (Automate has its own, separate from the
 *     Manage clientId in connectwise-config.php).
 *   - username / password: a dedicated Automate user for the API (not a
 *     person's login). It only needs read access to Clients and Computers.
 *     If that user has two-factor turned on, the API cannot sign in as it.
 */

return [
    // Server base URL, no trailing slash and no "/cwa/api/v1" suffix.
    'base_url' => 'https://automate.codebluetechnology.com',

    // Integration clientId (GUID) -- sent as the "ClientId" header.
    'client_id' => 'REPLACE_ME',

    // Automate API user (token is requested with these, then cached ~1h).
    'username' => 'REPLACE_ME',
    'password' => 'REPLACE_ME',
];
