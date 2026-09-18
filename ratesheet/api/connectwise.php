<?php
/**
 * ratesheet/api/connectwise.php
 *
 * Small REST client for CodeBlue's self-hosted ConnectWise Manage instance
 * (connect.codebluetechnology.com), used by requests.php to create the
 * Company + Contact once a customer completes their rate sheet signup.
 *
 * Same shape as register/api/connectwise.php and
 * relationships/api/connectwise.php (both proven working end-to-end
 * against this same instance) -- kept as this app's own copy rather than
 * a shared include, per this codebase's established self-contained-
 * sub-app pattern (each app under portal.codebluetechnology.com has its
 * own api/ folder and doesn't reach across into another app's files).
 * Only the create-related surface is included here (no catalog/list-page
 * helpers) since that's all this app needs.
 *
 * Auth: HTTP Basic where the "username" is "{companyId}+{publicKey}" and
 * the "password" is the API Member's private key, PLUS a required
 * clientId header. All four values plus the instance base URL live in
 * connectwise-config.php (gitignored -- see connectwise-config.sample.php).
 * That file can be a copy of register's or relationships' own config (same
 * ConnectWise instance) -- nothing here assumes which.
 */

declare(strict_types=1);

class RatesheetConnectWiseError extends RuntimeException
{
}

function ratesheet_cw_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $path = __DIR__ . '/connectwise-config.php';
    if (!is_file($path)) {
        throw new RatesheetConnectWiseError(
            'connectwise-config.php is missing. Copy connectwise-config.sample.php to ' .
            'connectwise-config.php in this same folder and fill in real values.'
        );
    }

    $config = require $path;
    foreach (['base_url', 'company_id', 'public_key', 'private_key', 'client_id'] as $key) {
        if (empty($config[$key])) {
            throw new RatesheetConnectWiseError("connectwise-config.php is missing required key \"$key\".");
        }
    }
    return $config;
}

/**
 * One authenticated request against the ConnectWise REST API. $path is
 * relative to the v3.0 apis root, e.g. "/company/companies". Returns the
 * decoded JSON body (array). Throws RatesheetConnectWiseError on any
 * transport failure, non-2xx response, or malformed JSON body.
 */
function ratesheet_cw_request(
    string $path,
    array $query = [],
    string $method = 'GET',
    ?array $jsonBody = null,
    int $timeoutSeconds = 30,
    int $connectTimeoutSeconds = 10
): array {
    $config = ratesheet_cw_config();
    $base = rtrim((string) $config['base_url'], '/') . '/v4_6_release/apis/3.0';
    $url = $base . $path;
    if ($query !== []) {
        $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    $authString = $config['company_id'] . '+' . $config['public_key'] . ':' . $config['private_key'];
    $headers = [
        'Authorization: Basic ' . base64_encode($authString),
        'clientId: ' . $config['client_id'],
        'Accept: application/json',
    ];

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
        throw new RatesheetConnectWiseError("ConnectWise request failed (cURL error $errNo): $errStr — $url");
    }
    if ($status < 200 || $status >= 300) {
        $snippet = is_string($body) ? substr($body, 0, 3000) : '';
        throw new RatesheetConnectWiseError("ConnectWise request returned HTTP $status for $url — $snippet");
    }

    if ($body === '' || $body === false) {
        return [];
    }
    $decoded = json_decode((string) $body, true);
    if (!is_array($decoded)) {
        throw new RatesheetConnectWiseError("ConnectWise response was not valid JSON for $url");
    }
    return $decoded;
}

/**
 * Escapes a string for safe interpolation into a single ConnectWise
 * "field like "%...%"" condition value -- same helper/reasoning as
 * register/api/connectwise.php's register_cw_condition_escape(), added
 * here 2026-09-17 (follow-up) for public.php's territory-by-name lookup.
 */
function ratesheet_cw_condition_escape(string $value): string
{
    $value = str_replace('\\', '\\\\', $value);
    $value = str_replace('"', '\\"', $value);
    return $value;
}

/**
 * Updates one or more fields on an existing Company via PUT (not PATCH),
 * added 2026-09-17 (follow-up #4) for releasing Credit Hold once Step 2
 * payment vaults -- see public.php's ?action=step2-submit.
 *
 * PUT, not PATCH, because this ConnectWise instance's Company PATCH
 * endpoint revalidates the ENTIRE stored record on every PATCH (not just
 * the field touched) and 500s with a generic DateTime error the moment
 * any pre-existing field on the company is invalid by today's stricter
 * validation -- confirmed live against this same instance in the
 * relationships app (relationships/api/connectwise-outgrow.php's header)
 * and register app (register_cw_put_company_with_retry()). Ported
 * verbatim from relationships/api/connectwise.php's
 * relationships_cw_put_company_with_retry() + relationships_cw_offending_
 * field(): GET the full record, merge in just the field(s) actually being
 * changed, PUT the whole thing back, and if ConnectWise's structured error
 * names a specific pre-existing field as invalid, strip THAT field from
 * the payload (leaving its stored value untouched) and retry -- rather
 * than failing the whole update over a field nobody's trying to change.
 *
 * This also means an unconfirmed field name in $fieldsToSet (see
 * ratesheet_cw_release_credit_hold() below) degrades gracefully: if
 * ConnectWise's error names that exact field as invalid/unrecognized,
 * it's the one that gets stripped and retried away, rather than a hard
 * failure.
 */
function ratesheet_cw_put_company_with_retry(int $cwCompanyId, array $fieldsToSet, int $maxAttempts = 5): array
{
    $full = ratesheet_cw_request('/company/companies/' . $cwCompanyId, []);
    $modified = $fieldsToSet + $full;

    for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
        try {
            return ratesheet_cw_request('/company/companies/' . $cwCompanyId, [], 'PUT', $modified);
        } catch (RatesheetConnectWiseError $e) {
            $realKey = ratesheet_cw_offending_field($e->getMessage(), $modified);
            if ($realKey === null) {
                throw $e;
            }
            unset($modified[$realKey]);
        }
    }

    throw new RatesheetConnectWiseError('Could not update company ' . $cwCompanyId . ' after ' . $maxAttempts . ' attempts.');
}

/**
 * Parses the JSON body embedded in a RatesheetConnectWiseError message
 * (see ratesheet_cw_request()'s "HTTP $status ... $snippet" shape) for a
 * ConnectWise structured errors[] entry naming a specific field, and
 * resolves it to the matching key actually present in $payload -- ported
 * verbatim from relationships/api/connectwise.php's
 * relationships_cw_offending_field(). Returns null if the error body
 * isn't ConnectWise's structured shape, or names a field this payload
 * doesn't have.
 */
function ratesheet_cw_offending_field(string $errorMessage, array $payload): ?string
{
    $jsonStart = strpos($errorMessage, '{');
    $decoded = $jsonStart !== false ? json_decode(substr($errorMessage, $jsonStart), true) : null;
    if (!is_array($decoded) || !is_array($decoded['errors'] ?? null)) {
        return null;
    }

    $offendingField = null;
    foreach ($decoded['errors'] as $err) {
        $field = is_array($err) ? ($err['field'] ?? null) : null;
        if (is_string($field) && $field !== '') {
            $offendingField = $field;
            break;
        }
    }
    if ($offendingField === null) {
        return null;
    }

    $candidates = [$offendingField];
    if (substr($offendingField, -3) === 'Ids') {
        $base = substr($offendingField, 0, -3);
        $candidates[] = $base . 's';
        $candidates[] = $base;
    }
    foreach ($candidates as $candidate) {
        if (array_key_exists($candidate, $payload)) {
            return $candidate;
        }
    }
    return null;
}

/**
 * Releases Credit Hold on a Company once Step 2 payment actually vaults
 * (see public.php's ?action=step2-submit) -- added 2026-09-17 (follow-up
 * #4). Deliberately fail-open: by the time this is called, the customer's
 * payment method is already vaulted and their signup already saved, so a
 * ConnectWise quirk here should never re-surface as a failure to the
 * customer -- it's logged instead, so staff can release the hold by hand
 * if this ever misfires.
 *
 * *** FIELD NAME UNCONFIRMED *** -- 'creditHold' is ConnectWise Manage's
 * documented boolean field for a Company's Credit Hold checkbox, but this
 * hasn't been exercised against this instance's live API from here (no
 * local ConnectWise credentials -- see connectwise-config.sample.php).
 * If it's wrong, ratesheet_cw_put_company_with_retry()'s offending-field
 * handling will strip it and the PUT will otherwise succeed -- but the
 * hold itself won't actually release. Watch the error_log below on the
 * first live Step 2 completion to confirm.
 */
function ratesheet_cw_release_credit_hold(int $cwCompanyId): void
{
    try {
        ratesheet_cw_put_company_with_retry($cwCompanyId, ['creditHold' => false]);
    } catch (Throwable $e) {
        error_log('[ratesheet/connectwise] could not release Credit Hold for company ' . $cwCompanyId . ': ' . $e->getMessage());
    }
}
