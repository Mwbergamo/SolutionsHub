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
