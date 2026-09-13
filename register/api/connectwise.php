<?php
/**
 * register/api/connectwise.php
 *
 * Small REST client for CodeBlue's own self-hosted ConnectWise Manage
 * instance (connect.codebluetechnology.com) -- used by catalog-sync.php to
 * pull Procurement Catalog items (productClass='Inventory' only) plus
 * their on-hand quantity into catalog_items.
 *
 * Same shape as relationships/api/connectwise.php (that file's client is
 * proven working end-to-end against this same ConnectWise instance as of
 * 2026-09-11/12) -- kept as this app's own copy rather than a shared
 * include, per this codebase's established self-contained-sub-app pattern
 * (each app under portal.codebluetechnology.com has its own api/ folder
 * and doesn't reach across into another app's files).
 *
 * Auth: HTTP Basic where the "username" is "{companyId}+{publicKey}" and
 * the "password" is the API Member's private key, base64-encoded together,
 * PLUS a separate required `clientId` header (a GUID from ConnectWise's
 * developer portal -- distinct from the API Member's public/private keys).
 * All four values plus the instance base URL live in connectwise-config.php
 * (gitignored -- see connectwise-config.sample.php for the template). This
 * app's connectwise-config.php can be a copy of relationships/api's, or its
 * own separate API Member -- either works, nothing here assumes which.
 */

declare(strict_types=1);

class RegisterConnectWiseError extends RuntimeException
{
}

function register_cw_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $path = __DIR__ . '/connectwise-config.php';
    if (!is_file($path)) {
        throw new RegisterConnectWiseError(
            'connectwise-config.php is missing. Copy connectwise-config.sample.php to ' .
            'connectwise-config.php in this same folder and fill in real values.'
        );
    }

    $config = require $path;
    foreach (['base_url', 'company_id', 'public_key', 'private_key', 'client_id'] as $key) {
        if (empty($config[$key])) {
            throw new RegisterConnectWiseError("connectwise-config.php is missing required key \"$key\".");
        }
    }
    return $config;
}

/**
 * One authenticated request against the ConnectWise REST API. $path is
 * relative to the v3.0 apis root, e.g. "/procurement/catalog". $query is a
 * plain key => value array (this function handles urlencoding).
 *
 * Returns the decoded JSON body (array). Throws RegisterConnectWiseError on
 * any transport failure, non-2xx response, or malformed JSON body.
 */
function register_cw_request(string $path, array $query = [], string $method = 'GET', ?array $jsonBody = null): array
{
    $config = register_cw_config();
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
        CURLOPT_TIMEOUT => 60,
        CURLOPT_CONNECTTIMEOUT => 15,
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
        throw new RegisterConnectWiseError("ConnectWise request failed (cURL error $errNo): $errStr — $url");
    }
    if ($status < 200 || $status >= 300) {
        $snippet = is_string($body) ? substr($body, 0, 3000) : '';
        throw new RegisterConnectWiseError("ConnectWise request returned HTTP $status for $url — $snippet");
    }

    if ($body === '' || $body === false) {
        return [];
    }
    $decoded = json_decode((string) $body, true);
    if (!is_array($decoded)) {
        throw new RegisterConnectWiseError("ConnectWise response was not valid JSON for $url");
    }
    return $decoded;
}

/**
 * Fetches exactly ONE page of a ConnectWise list endpoint -- no internal
 * looping. Added 2026-09-13 alongside catalog.php's list_agreement/
 * list_inventory sync stages: looping through every page of a ~14,600-item
 * candidate list (via register_cw_list() below) inside a single request is
 * exactly the kind of unbounded synchronous ConnectWise work that caused
 * the 2026-09-12 sync-timeout bug in the first place, just moved one level
 * up (from per-item calls to per-page calls). Callers that need a large
 * list now fetch it one page per request/step instead, same "never do more
 * than a small bounded amount of ConnectWise work in one PHP request"
 * discipline as the rest of this sync.
 */
function register_cw_list_page(string $path, string $conditions, array $fields, int $page, int $pageSize = 200): array
{
    return register_cw_request($path, [
        'conditions' => $conditions,
        'fields' => implode(',', $fields),
        'pageSize' => (string) $pageSize,
        'page' => (string) $page,
    ]);
}

/**
 * Pages through a ConnectWise list endpoint and returns every row as a
 * single flat array. Same shape/reasoning as
 * relationships_cw_list() in relationships/api/connectwise.php.
 *
 * NOTE: looping through many pages inside one request risks the same
 * execution-time-limit failure register_cw_list_page() above was added to
 * avoid -- prefer that function (one page per call, driven by a bounded
 * queue/step loop) for any list that could grow into dozens of pages, e.g.
 * ConnectWise's Procurement Catalog. Kept here for small, bounded lists
 * where a handful of pages is genuinely safe.
 */
function register_cw_list(string $path, string $conditions, array $fields, int $pageSize = 200): array
{
    $all = [];
    $page = 1;
    while (true) {
        $query = [
            'conditions' => $conditions,
            'fields' => implode(',', $fields),
            'pageSize' => (string) $pageSize,
            'page' => (string) $page,
        ];
        $rows = register_cw_request($path, $query);
        if ($rows === []) {
            break;
        }
        foreach ($rows as $row) {
            $all[] = $row;
        }
        if (count($rows) < $pageSize) {
            break; // last page
        }
        $page++;
        if ($page > 200) {
            // Sanity guard, same as relationships/api/connectwise.php.
            break;
        }
    }
    return $all;
}
