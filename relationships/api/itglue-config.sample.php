<?php
/**
 * relationships/api/itglue-config.sample.php
 *
 * Copy this file to relationships/api/itglue-config.php on the SERVER
 * (not in git -- itglue-config.php is listed in .gitignore) and fill in the
 * real key there. Never commit the key, and never paste it into chat,
 * tickets or screenshots.
 *
 * Create the key in IT Glue as an Administrator: Account > Settings >
 * API Keys. It is shown only once. IT Glue revokes keys that go unused for
 * 90 days, so if this integration goes quiet, check the key first.
 *
 * Access is READ-ONLY by design in this app (it only issues GET requests).
 */

return [
    // US: https://api.itglue.com   EU: https://api.eu.itglue.com   AU: https://api.au.itglue.com
    'base_url' => 'https://api.itglue.com',

    // Sent as the "x-api-key" header.
    'api_key' => 'REPLACE_ME',
];
