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
 * Flow (see public.php's ?action=card-checkout-init and ?action=submit):
 *   1. altpay_get_access_token()       -- OAuth client_credentials grant
 *   2. altpay_create_customer()        -- POST /customers (one per rate
 *                                          sheet signup; external_id ties
 *                                          it back to our own row)
 *   3a. Card: CORRECTED 2026-09-17 (follow-up #3, after a live 500 from
 *       POST /customers/{id}/payment-methods/card). Alternative Payments'
 *       own docs are explicit that there is NO supported way to build a
 *       "card_provider_token" ourselves and POST it directly -- the only
 *       supported path is their own Web SDK's `addPaymentMethod`
 *       component (loaded client-side in signup.js/signup.html), which
 *       mounts Evervault's card form AND calls the payment-methods/card
 *       endpoint internally. This file's role for card is now just
 *       handing the browser a checkout-auth token scoped to the
 *       already-created customer (altpay_checkout_auth_token()) --
 *       public.php never calls a card-vaulting endpoint itself anymore.
 *       (The earlier ratesheet_altpay_card_form_credentials() /
 *       ratesheet_altpay_create_card_payment_method() functions that
 *       hand-rolled this are gone -- they were the bug.)
 *   3b. Bank (ACH): unchanged. Alternative Payments' payment-methods/bank
 *       endpoint takes the routing/account number directly -- there's no
 *       client-side tokenization step documented for bank accounts the
 *       way there is for cards. So the raw routing/account number DOES
 *       pass through our own public.php for a bank signup (over HTTPS,
 *       same as the rest of the form), but is never written to our
 *       database or logged -- it's held in a local PHP variable only
 *       long enough to relay it to Alternative Payments and then
 *       discarded. Only Alternative Payments' own payment_method id and
 *       a redacted display string ("Bank ending 6789") are saved.
 *
 * *** STILL PARTIALLY UNVERIFIED ***
 * Alternative Payments' docs confirm POST /v1/checkout-auth/init accepts
 * customer_id (their examples always paired it with an invoice_id, for
 * their invoice-checkout flow -- we don't have an invoice at signup time,
 * so this omits invoice_id and relies on their addPaymentMethod component's
 * documented "vault-only mode, no invoice ID required"). Not yet exercised
 * against a live request. If checkout-auth/init 400s without invoice_id,
 * that's the next thing to check against the sandbox.
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
 * POST /checkout-auth/init -- a short-lived token that authorizes the
 * browser (via Alternative Payments' own Web SDK) to act as the given
 * customer for exactly long enough to add a payment method. This is what
 * replaces the old (broken) hand-rolled card_provider_token approach --
 * see this file's header.
 *
 * PATH: Alternative Payments' own docs show this with a /v1 prefix
 * ("/v1/checkout-auth/init"), but a live request to that exact path 404'd
 * (2026-09-17) -- the same "docs' /v1 prefix doesn't match the live API"
 * pattern already hit and confirmed for card-form/credentials, /customers,
 * and /payment-methods/*. So this tries the documented /v1 path first and,
 * on a 404 specifically, falls back to the same path without /v1 -- same
 * empirical approach, without needing another guess-deploy-test round trip
 * if this guess is also wrong. Once confirmed, simplify to whichever one
 * actually works.
 */
function ratesheet_altpay_checkout_auth_token(string $customerId): array
{
    $token = ratesheet_altpay_access_token();
    $body = ['customer_id' => $customerId];

    try {
        $result = ratesheet_altpay_request('/v1/checkout-auth/init', $token, 'POST', $body);
    } catch (RatesheetAltpayError $e) {
        if (!str_contains($e->getMessage(), 'HTTP 404')) {
            throw $e;
        }
        $result = ratesheet_altpay_request('/checkout-auth/init', $token, 'POST', $body);
    }

    if (empty($result['token'])) {
        throw new RatesheetAltpayError('Alternative Payments checkout-auth response did not include a token.');
    }
    return [
        'token' => (string) $result['token'],
        'expires_at' => $result['expires_at'] ?? null,
    ];
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
