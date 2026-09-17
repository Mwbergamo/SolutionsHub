<?php
/**
 * ratesheet/api/altpay.php
 *
 * Small REST client for Alternative Payments (alternativepayments.io),
 * added 2026-09-17 (follow-up #2, per Michael) to replace the
 * "payment_method choice only" placeholder from the original signup
 * build with real payment-method collection: at signup, the customer's
 * card or bank account is VAULTED (stored on file with Alternative
 * Payments for staff to bill manually later) -- nothing is charged at
 * signup time. Same self-contained-sub-app copy pattern as
 * connectwise.php (see that file's header).
 *
 * Flow (see public.php's ?action=submit for where this is called):
 *   1. altpay_get_access_token()       -- OAuth client_credentials grant
 *   2. altpay_create_customer()        -- POST /customers (one per rate
 *                                          sheet signup; external_id ties
 *                                          it back to our own row)
 *   3a. Card: the browser never sends us a raw card number. It mounts
 *       Evervault's Inputs card form (see signup.js), which encrypts the
 *       card fields client-side. Those encrypted values are relayed
 *       through to Alternative Payments via altpay_create_card_payment_method().
 *   3b. Bank (ACH): Alternative Payments' payment-methods/bank endpoint
 *       takes the routing/account number directly -- there's no
 *       client-side tokenization step documented for bank accounts the
 *       way there is for cards. So the raw routing/account number DOES
 *       pass through our own public.php for a bank signup (over HTTPS,
 *       same as the rest of the form), but is never written to our
 *       database or logged -- it's held in a local PHP variable only
 *       long enough to relay it to Alternative Payments and then
 *       discarded. Only Alternative Payments' own payment_method id and
 *       a redacted display string ("Bank ending 6789") are saved.
 *
 * *** IMPORTANT -- UNVERIFIED AGAINST A REAL SANDBOX ***
 * Alternative Payments' own docs (as fetched 2026-09-17) do not show a
 * complete worked example of the exact shape of "card_provider_token"
 * for POST /customers/{id}/payment-methods/card, nor whether
 * GET /card-form/credentials needs a scoped token beyond the standard
 * client_credentials grant. What's implemented below is my best
 * reading of their docs (Evervault Inputs -> encrypted card.values.card.*
 * -> relayed as the card_provider_token payload) but has NOT been
 * exercised against a live request yet. Before this goes live: place
 * real staging credentials in altpay-config.php and test each function
 * here against the sandbox (a quick curl/php script is enough) --
 * don't trust this file blindly just because it lints clean.
 */

declare(strict_types=1);

class RatesheetAltpayError extends RuntimeException
{
}

function ratesheet_altpay_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $path = __DIR__ . '/altpay-config.php';
    if (!is_file($path)) {
        throw new RatesheetAltpayError(
            'altpay-config.php is missing. Copy altpay-config.sample.php to ' .
            'altpay-config.php in this same folder and fill in real values.'
        );
    }

    $config = require $path;
    foreach (['base_url', 'client_id', 'client_secret'] as $key) {
        if (empty($config[$key])) {
            throw new RatesheetAltpayError("altpay-config.php is missing required key \"$key\".");
        }
    }
    return $config;
}

/**
 * One request against the Alternative Payments API. $path is relative
 * to the config's base_url (no leading /v1 assumed -- pass the full
 * path Alternative Payments' docs show, e.g. "/oauth/token" or
 * "/v1/customers", since their examples weren't consistent about a
 * version prefix). Set $accessToken to null only for the token request
 * itself.
 */
function ratesheet_altpay_request(
    string $path,
    ?string $accessToken,
    string $method = 'GET',
    ?array $jsonBody = null,
    array $extraHeaders = [],
    int $timeoutSeconds = 30,
    int $connectTimeoutSeconds = 10
): array {
    $config = ratesheet_altpay_config();
    $url = rtrim((string) $config['base_url'], '/') . $path;

    $headers = array_merge(['Accept: application/json'], $extraHeaders);
    if ($accessToken !== null) {
        $headers[] = 'Authorization: Bearer ' . $accessToken;
    }

    $encodedBody = null;
    if ($jsonBody !== null) {
        $encodedBody = json_encode($jsonBody);
        $headers[] = 'Content-Type: application/json';
    }

    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => $timeoutSeconds,
        CURLOPT_CONNECTTIMEOUT => $connectTimeoutSeconds,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ];
    if ($method !== 'GET') {
        $opts[CURLOPT_CUSTOMREQUEST] = $method;
    }
    if ($encodedBody !== null) {
        $opts[CURLOPT_POSTFIELDS] = $encodedBody;
    }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $errNo = curl_errno($ch);
    $errStr = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errNo !== 0) {
        throw new RatesheetAltpayError("Alternative Payments request failed (cURL error $errNo): $errStr — $url");
    }
    if ($status < 200 || $status >= 300) {
        $snippet = is_string($body) ? substr($body, 0, 3000) : '';
        throw new RatesheetAltpayError("Alternative Payments request returned HTTP $status for $url — $snippet");
    }
    if ($body === '' || $body === false) {
        return [];
    }
    $decoded = json_decode((string) $body, true);
    if (!is_array($decoded)) {
        throw new RatesheetAltpayError("Alternative Payments response was not valid JSON for $url");
    }
    return $decoded;
}

/**
 * OAuth 2.0 client_credentials grant. Not cached beyond a single PHP
 * request (each request is short-lived -- a rate sheet submit is a rare,
 * low-volume event, so re-fetching a token every time is simpler and
 * safer than a persistent token cache file that could go stale/racy).
 * Basic-auth per Alternative Payments' quick-start guide: base64(client_id:client_secret).
 */
function ratesheet_altpay_access_token(): string
{
    static $token = null;
    if ($token !== null) {
        return $token;
    }

    $config = ratesheet_altpay_config();
    $basic = base64_encode($config['client_id'] . ':' . $config['client_secret']);

    $result = ratesheet_altpay_request(
        '/oauth/token',
        null,
        'POST',
        ['grant_type' => 'client_credentials'],
        ['Authorization: Basic ' . $basic]
    );

    if (empty($result['access_token'])) {
        throw new RatesheetAltpayError('Alternative Payments token response did not include an access_token.');
    }
    $token = (string) $result['access_token'];
    return $token;
}

/**
 * GET /card-form/credentials -- Evervault app_id/team_id used to mount
 * the card Inputs form client-side (see signup.js). These are not
 * secret (same trust level as a "publishable key"): they only let a
 * browser render a tokenization iframe, not act as our API credentials.
 * Safe to hand back to the public signup page via public.php.
 */
function ratesheet_altpay_card_form_credentials(): array
{
    $token = ratesheet_altpay_access_token();
    $result = ratesheet_altpay_request('/card-form/credentials', $token, 'GET');
    if (empty($result['app_id']) || empty($result['team_id'])) {
        throw new RatesheetAltpayError('Alternative Payments card-form credentials response was missing app_id/team_id.');
    }
    return ['app_id' => (string) $result['app_id'], 'team_id' => (string) $result['team_id']];
}

/**
 * POST /customers. $customer may include: name, email, external_id,
 * street_address, city, state, postal_code, country. external_id is set
 * to our own rate_sheet_requests.id so the two systems can be
 * cross-referenced later. Returns the Alternative Payments customer id.
 */
function ratesheet_altpay_create_customer(array $customer): string
{
    $token = ratesheet_altpay_access_token();
    $result = ratesheet_altpay_request('/customers', $token, 'POST', $customer);
    if (empty($result['id'])) {
        throw new RatesheetAltpayError('Alternative Payments customer creation did not return an id.');
    }
    return (string) $result['id'];
}

/**
 * POST /customers/{id}/payment-methods/card. $cardProviderToken is
 * whatever signup.js's relay of Evervault's encrypted card.values.card
 * fields produces (see this file's header -- UNVERIFIED exact shape).
 * Returns ['id' => ..., 'summary' => 'Visa ending 4242'] -- summary is
 * built from whatever brand/last-4 fields the response includes, falling
 * back to a generic label if Alternative Payments doesn't echo those back.
 */
function ratesheet_altpay_create_card_payment_method(string $customerId, string $cardProviderToken, array $address = []): array
{
    $token = ratesheet_altpay_access_token();
    $body = array_merge([
        'card_provider_token' => $cardProviderToken,
        'provider' => 'evervault',
    ], $address !== [] ? ['address' => $address] : []);

    $result = ratesheet_altpay_request(
        '/customers/' . rawurlencode($customerId) . '/payment-methods/card',
        $token,
        'POST',
        $body
    );
    if (empty($result['id'])) {
        throw new RatesheetAltpayError('Alternative Payments card payment-method creation did not return an id.');
    }

    $brand = isset($result['brand']) ? ucfirst((string) $result['brand']) : 'Card';
    $last4 = isset($result['last4']) ? (string) $result['last4'] : (isset($result['last_4']) ? (string) $result['last_4'] : null);
    $summary = $last4 !== null ? "$brand ending $last4" : 'Card on file';

    return ['id' => (string) $result['id'], 'summary' => $summary];
}

/**
 * POST /customers/{id}/payment-methods/bank. $accountData must contain
 * account_data_type => 'us' plus routing_number + account_number (this
 * app only offers US bank accounts -- CodeBlue is Virginia-based). The
 * raw numbers are the caller's (public.php's) responsibility to never
 * persist -- this function only relays them onward and returns the
 * resulting Alternative Payments id + a redacted summary.
 */
function ratesheet_altpay_create_bank_payment_method(string $customerId, array $accountData): array
{
    $token = ratesheet_altpay_access_token();
    $body = array_merge(['account_data_type' => 'us', 'type' => 'depository'], $accountData);

    $result = ratesheet_altpay_request(
        '/customers/' . rawurlencode($customerId) . '/payment-methods/bank',
        $token,
        'POST',
        $body
    );
    if (empty($result['id'])) {
        throw new RatesheetAltpayError('Alternative Payments bank payment-method creation did not return an id.');
    }

    $accountNumber = (string) ($accountData['account_number'] ?? '');
    $last4 = $accountNumber !== '' ? substr($accountNumber, -4) : null;
    $summary = $last4 !== null ? "Bank account ending $last4" : 'Bank account on file';

    return ['id' => (string) $result['id'], 'summary' => $summary];
}
