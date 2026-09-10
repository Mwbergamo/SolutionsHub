<?php
/**
 * relationships/api/connectwise-config.sample.php
 *
 * Copy this file to relationships/api/connectwise-config.php on the SERVER
 * (not in git -- connectwise-config.php is listed in .gitignore) and fill
 * in real values there. Never commit real credentials to this repository.
 *
 * These come from CodeBlue's own ConnectWise Manage instance
 * (connect.codebluetechnology.com):
 *   - company_id / public_key / private_key: an API Member's keys, from
 *     System -> Members -> API Members in ConnectWise.
 *   - client_id: a separate GUID registered via ConnectWise's developer
 *     portal (developer.connectwise.com) for this integration -- NOT the
 *     same as the API Member's public/private keys above.
 */

return [
    // Base URL of the ConnectWise instance, no trailing slash and no
    // "/v4_6_release/..." suffix -- connectwise.php appends that itself.
    'base_url' => 'https://connect.codebluetechnology.com',

    // API Member company ID (e.g. "CBTS").
    'company_id' => 'REPLACE_ME',

    // API Member public key.
    'public_key' => 'REPLACE_ME',

    // API Member private key. KEEP THIS OUT OF GIT.
    'private_key' => 'REPLACE_ME',

    // Integration clientId (GUID) from the ConnectWise developer portal.
    'client_id' => 'REPLACE_ME',
];
