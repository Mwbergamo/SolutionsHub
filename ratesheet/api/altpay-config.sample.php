<?php
/**
 * ratesheet/api/altpay-config.sample.php
 *
 * Copy this file to altpay-config.php (same folder) and fill in real
 * values. altpay-config.php is gitignored (see ratesheet/.gitignore) --
 * it never travels through git push/pull, same as connectwise-config.php.
 * Deploying it means creating it directly on the production server (via
 * cPanel File Manager or SSH), exactly like connectwise-config.php was.
 *
 * Where these come from: Alternative Payments Partner Dashboard ->
 * Team Preferences -> API Keys -> Generate API Key. That gives you a
 * Client ID + Client Secret pair (OAuth 2.0 client_credentials grant --
 * see altpay.php's altpay_get_access_token()). Generate a SEPARATE key
 * pair per environment if Alternative Payments' dashboard lets you pick
 * an environment when generating -- if not, ask their support which
 * environment a given key pair targets.
 *
 * base_url: only one base URL is confirmed from Alternative Payments'
 * public docs (https://public-api.alternativepayments.io) -- their docs
 * did not surface a distinct staging/sandbox hostname. If Alternative
 * Payments gave you a separate staging URL, put it here instead; if
 * staging vs. production is actually selected by which key pair you use
 * (same host either way), leave this as the production host and just
 * swap client_id/client_secret when moving from staging to production.
 */

return [
    // 'staging' or 'production' -- purely a label used in error messages
    // and the project doc; doesn't change behavior on its own. What
    // actually determines the environment is base_url + which key pair
    // you paste in below.
    'environment' => 'staging',

    'base_url' => 'https://public-api.alternativepayments.io',

    'client_id' => '',
    'client_secret' => '',
];
