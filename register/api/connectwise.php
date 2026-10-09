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
    // Decoded JSON error body, when ConnectWise's response was itself
    // valid JSON (e.g. ['code'=>'InvalidObject','errors'=>[['code'=>
    // 'ObjectExists','field'=>'identifier',...]]]). Null for a transport
    // failure, a non-JSON body, or any error raised without one -- added
    // 2026-09-29 (ported from ratesheet/api/connectwise.php's identical
    // fix, same day) so a caller can detect a specific field-level error
    // (like a duplicate Company identifier, see
    // register_cw_create_company() below) instead of string-matching
    // this exception's message.
    public ?array $responseBody;

    public function __construct(string $message, ?array $responseBody = null)
    {
        parent::__construct($message);
        $this->responseBody = $responseBody;
    }

    // True if ConnectWise's response body included a top-level `errors`
    // entry matching this exact field + code, e.g.
    // hasFieldError('identifier', 'ObjectExists') for a duplicate Company
    // ID. ConnectWise's error shape for that case (confirmed live,
    // 2026-09-29): {"code":"InvalidObject","errors":[{"code":"ObjectExists",
    // "message":"Company ID already in use.","field":"identifier"}]}.
    public function hasFieldError(string $field, string $code): bool
    {
        if ($this->responseBody === null) {
            return false;
        }
        foreach ($this->responseBody['errors'] ?? [] as $err) {
            if (($err['field'] ?? null) === $field && ($err['code'] ?? null) === $code) {
                return true;
            }
        }
        return false;
    }
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
 * $timeoutSeconds/$connectTimeoutSeconds default to a generous 60/15 --
 * fine for the small number of calls most callers make. catalog.php's
 * sync-step passes much tighter values for its per-item filter/sync calls
 * (added 2026-09-13 -- see catalog.php's "part 3" bug-fix note): a sync
 * batch makes several of these calls back-to-back inside one PHP request,
 * so a single slow/hung ConnectWise call at the default 60s timeout could
 * by itself push that request past Bluehost's own front-end/gateway
 * timeout -- which kills the connection before PHP's own error handlers
 * ever get a chance to run, so the browser sees a dropped/non-JSON
 * response and reports a generic "check your connection" failure instead
 * of a real error. Keeping each individual call short-leashed is what
 * actually bounds a batch's worst-case wall time, not batch size alone.
 *
 * Returns the decoded JSON body (array). Throws RegisterConnectWiseError on
 * any transport failure, non-2xx response, or malformed JSON body.
 */
function register_cw_request(
    string $path,
    array $query = [],
    string $method = 'GET',
    ?array $jsonBody = null,
    int $timeoutSeconds = 60,
    int $connectTimeoutSeconds = 15
): array {
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
        throw new RegisterConnectWiseError("ConnectWise request failed (cURL error $errNo): $errStr — $url");
    }
    if ($status < 200 || $status >= 300) {
        $snippet = is_string($body) ? substr($body, 0, 3000) : '';
        $decodedError = is_string($body) ? json_decode($body, true) : null;
        throw new RegisterConnectWiseError("ConnectWise request returned HTTP $status for $url — $snippet", is_array($decodedError) ? $decodedError : null);
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
    return register_cw_request(
        $path,
        [
            'conditions' => $conditions,
            'fields' => implode(',', $fields),
            'pageSize' => (string) $pageSize,
            'page' => (string) $page,
        ],
        'GET',
        null,
        // A page fetch is the only ConnectWise call a listing-stage
        // sync-step makes, so it alone determines that request's wall
        // time -- tighter than the 60s default, still generous for a
        // ~200-row page, and safely under a typical hosting gateway
        // timeout. See register_cw_request()'s docblock.
        30,
        8
    );
}

/**
 * Escapes a string for safe interpolation into a single ConnectWise
 * "field like "%...%"" or "field = "..."" condition value (added 2026-09-14
 * for customers.php's live Company/Contact search-as-you-type). Only
 * single-field conditions built with this helper are used anywhere in this
 * app -- no "and"/"or" combinations of multiple fields, since those were
 * never confirmed against this ConnectWise instance (only single conditions
 * were proven via customers.php's probe action: "name like ...", "lastName
 * like ...", "company/id = ..."). Combining fields client-side in PHP after
 * a single confirmed condition avoids guessing at compound-condition syntax
 * -- same "diagnose before guessing" discipline as everywhere else in this
 * project (see relationships-connectwise-sync.md's board-name saga).
 */
function register_cw_condition_escape(string $value): string
{
    $value = str_replace('\\', '\\\\', $value);
    $value = str_replace('"', '\\"', $value);
    return $value;
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

/**
 * Generalized version of customers.php's register_cw_put_company_with_retry()
 * -- fetch the full record, overlay the fields to change, PUT the whole
 * thing back, and if ConnectWise names a specific "<field> can only be
 * used when creating" offender, strip that exact field (resolving
 * ConnectWise's internal name, e.g. "typeIds", back to the real JSON key,
 * e.g. "types", when they differ) and retry. Used as the fallback when a
 * plain PATCH fails -- proven against Company (always needs this path,
 * PATCH is broken on this instance for Company) and against Service
 * Ticket (PATCH succeeded on the first live test, 2026-10-09, so this
 * fallback has not actually been exercised for Tickets yet, but is kept
 * as a safety net in case a future ticket/status combination hits a case
 * plain PATCH can't handle).
 */
function register_cw_put_entity_with_retry(string $path, array $fieldsToSet, int $maxAttempts = 5): array
{
    $full = register_cw_request($path, [], 'GET', null, 20, 6);
    $modified = $fieldsToSet + $full;

    for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
        try {
            return register_cw_request($path, [], 'PUT', $modified, 20, 6);
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            $jsonStart = strpos($msg, '{');
            $decoded = $jsonStart !== false ? json_decode(substr($msg, $jsonStart), true) : null;
            $offendingField = null;
            if (is_array($decoded) && isset($decoded['errors']) && is_array($decoded['errors'])) {
                foreach ($decoded['errors'] as $err) {
                    $field = $err['field'] ?? null;
                    $errMsg = $err['message'] ?? '';
                    if (is_string($field) && $field !== '' && stripos($errMsg, 'can only be used when creating') !== false) {
                        $offendingField = $field;
                        break;
                    }
                }
            }
            $realKey = null;
            if ($offendingField !== null) {
                $candidates = [$offendingField];
                if (substr($offendingField, -3) === 'Ids') {
                    $base = substr($offendingField, 0, -3);
                    $candidates[] = $base . 's';
                    $candidates[] = $base;
                }
                foreach ($candidates as $candidate) {
                    if (array_key_exists($candidate, $modified)) {
                        $realKey = $candidate;
                        break;
                    }
                }
            }
            if ($realKey === null) {
                throw new RegisterConnectWiseError('Could not update ' . $path . ': ' . $msg);
            }
            unset($modified[$realKey]);
        }
    }

    throw new RegisterConnectWiseError('Could not update ' . $path . ' after ' . $maxAttempts . ' attempts.');
}

/**
 * Tries a plain PATCH (a single JSON-Patch replace op) first -- proven to
 * work for Service Ticket status (2026-10-09 live test) -- and falls back
 * to register_cw_put_entity_with_retry() if PATCH fails, same pattern the
 * ticket_invoice_write_test.php probe proved out. Returns an array shaped
 * ['method' => 'PATCH'|'PUT', 'result' => <decoded response>] so a caller
 * can tell which path actually worked.
 */
function register_cw_patch_then_put(string $path, string $fieldPath, array $fieldsToSet): array
{
    try {
        $result = register_cw_request($path, [], 'PATCH', [
            ['op' => 'replace', 'path' => $fieldPath, 'value' => reset($fieldsToSet)],
        ], 20, 6);
        return ['method' => 'PATCH', 'result' => $result];
    } catch (Throwable $e) {
        $result = register_cw_put_entity_with_retry($path, $fieldsToSet);
        return ['method' => 'PUT', 'result' => $result];
    }
}
