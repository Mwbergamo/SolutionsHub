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

// ---------------------------------------------------------------------
// Company update (PUT-with-retry) -- added 2026-09-18. ConnectWise's PATCH
// endpoint is confirmed broken on this instance for the Company entity:
// the register app's own live testing (claude/register-app.md) found
// every PATCH attempt 500s with a generic "String was not recognized as a
// valid DateTime" error regardless of target field or payload. The proven
// working mechanism, first built as register_cw_finalize_company_invoicing()
// and then generalized into register_cw_put_company_with_retry() /
// relationships_cw_put_company_with_retry(), is: fetch the full Company
// record, merge the intended field changes on top, PUT the whole thing
// back. ConnectWise's PUT validator rejects a handful of create-only
// fields the GET response itself includes (e.g. "typeIds can only be used
// when creating a new company") -- when that happens, strip exactly that
// field from the payload and retry, up to 5 attempts. This is this app's
// own copy of that same proven pattern -- kept general-purpose (not tied
// to any one field). UPDATED 2026-09-22, per Michael: this is now also
// the Credit Hold feature's real enforcement step. The create-time
// `status` field (a plain POST) is left in place as a first attempt, but
// is no longer trusted alone -- a live test landed on the wrong Billing
// Status despite that field appearing to take. public.php's submit flow
// now follows a successful create with an explicit
// ratesheet_cw_put_company_with_retry($companyId, ['status' => [...]])
// call: the same fetch-merge-PUT-with-retry mechanism, mirroring what a
// person does by hand -- open the new Company's Finance tab, change
// Status to "Credit Hold," and click Save. A future feature updating
// some other part of a Company record can still reach for this same
// helper rather than re-diagnosing the PATCH landmine from scratch.
// ---------------------------------------------------------------------

/**
 * Parses the one field name ConnectWise's error is complaining about out
 * of a RatesheetConnectWiseError's message, so
 * ratesheet_cw_put_company_with_retry() can strip it and retry. Tries the
 * structured errors[].field ConnectWise's JSON error body carries first
 * (ratesheet_cw_request() embeds the raw response snippet after " — " in
 * the exception message), then falls back to parsing "X can only be used
 * when creating a new company" out of the plain text. ConnectWise's
 * internal field name in either place can differ from the real JSON key
 * on the record (confirmed live in the register app: "typeIds" for the
 * real "types" array) -- an "Ids"/"Id" suffix is rewritten to a plural/
 * singular guess as a second try if the literal name isn't a key in
 * $payload. Returns null if nothing usable was found (caller then
 * rethrows the original error rather than retrying blindly).
 */
function ratesheet_cw_offending_field(string $message, array $payload): ?string
{
    $jsonStart = strpos($message, '{');
    if ($jsonStart !== false) {
        $decoded = json_decode(substr($message, $jsonStart), true);
        if (is_array($decoded) && isset($decoded['errors']) && is_array($decoded['errors'])) {
            foreach ($decoded['errors'] as $err) {
                $field = is_array($err) ? ($err['field'] ?? null) : null;
                if (is_string($field) && $field !== '') {
                    $resolved = ratesheet_cw_resolve_field_name($field, $payload);
                    if ($resolved !== null) {
                        return $resolved;
                    }
                }
            }
        }
    }
    if (preg_match('/([A-Za-z0-9_]+)\s+can only be used when creating/i', $message, $m)) {
        $resolved = ratesheet_cw_resolve_field_name($m[1], $payload);
        if ($resolved !== null) {
            return $resolved;
        }
    }
    return null;
}

function ratesheet_cw_resolve_field_name(string $field, array $payload): ?string
{
    if (array_key_exists($field, $payload)) {
        return $field;
    }
    if (str_ends_with($field, 'Ids')) {
        $rewritten = substr($field, 0, -3) . 's'; // e.g. typeIds -> types
        if (array_key_exists($rewritten, $payload)) {
            return $rewritten;
        }
    }
    if (str_ends_with($field, 'Id')) {
        $rewritten = substr($field, 0, -2); // e.g. someId -> some
        if (array_key_exists($rewritten, $payload)) {
            return $rewritten;
        }
    }
    return null;
}

/**
 * Updates a ConnectWise Company record by PUT-with-retry (see this
 * section's header for why PATCH isn't used). $fields is merged onto the
 * full current record before sending -- callers only need to name what
 * they're actually changing.
 */
function ratesheet_cw_put_company_with_retry(int $companyId, array $fields): array
{
    $current = ratesheet_cw_request('/company/companies/' . $companyId, [], 'GET');
    $payload = array_merge($current, $fields);

    for ($attempt = 1; $attempt <= 5; $attempt++) {
        try {
            return ratesheet_cw_request('/company/companies/' . $companyId, [], 'PUT', $payload);
        } catch (RatesheetConnectWiseError $e) {
            $offending = ratesheet_cw_offending_field($e->getMessage(), $payload);
            if ($offending === null || $attempt === 5) {
                throw $e;
            }
            unset($payload[$offending]);
        }
    }
    throw new RatesheetConnectWiseError('ConnectWise company PUT retry loop exhausted for company ' . $companyId . '.');
}

// ---------------------------------------------------------------------
// Company Status lookups -- added 2026-09-18 for the Credit Hold feature
// (see public.php's header for the full flow). Michael confirmed this
// instance has a real Company Status (the "Billing Status" dropdown, same
// field this app already sets to "Active" on every create) literally
// named "Credit Hold" -- so the new Company is created directly with that
// Status (a plain POST field, same as the existing `status: {id: 1}`
// Active default, not a PATCH/PUT) rather than a separate boolean flag.
// Resolved by a live name search the same way ratesheet_cw_resolve_territory_id()
// already does for Territory, since only Active's id (1) was previously
// confirmed in this codebase.
// ---------------------------------------------------------------------

/**
 * Looks up a ConnectWise Company Status id by name (e.g. "Credit Hold")
 * via a live `GET /company/statuses` name search -- *** ENDPOINT PATH
 * UNVERIFIED AGAINST THIS INSTANCE ***, guessed by analogy with
 * `/company/territories` and `/company/contacts/types`, both real,
 * confirmed endpoints elsewhere in this codebase (neither repeats
 * "company" in the resource name, which is why this doesn't try
 * `/company/companyStatuses` as an alternate guess -- no evidence on this
 * instance supports that shape). Returns null (never throws) on any
 * failure -- a missing/wrong status is HIGH STAKES here (it's the entire
 * Credit Hold safeguard), so unlike Territory's fail-open default this
 * deliberately does NOT invent a fallback id itself; the caller
 * (ratesheet_cw_create_company()) decides what to do when this returns
 * null, and does so loudly rather than silently.
 *
 * Deliberately does NOT trust $rows[0] blindly (added 2026-09-19, after a
 * live test landed on the wrong Status despite this function apparently
 * "succeeding") -- some ConnectWise reference/catalog endpoints silently
 * ignore an unsupported `conditions` filter and just return their default
 * page, which would otherwise make this quietly return the id of whatever
 * status happens to sort first (often "Active") while looking like a
 * clean match. Requests a larger page and only accepts a row whose own
 * `name` field actually contains the target string (case-insensitive),
 * so a filter that got ignored server-side is still caught client-side.
 * This is belt-and-suspenders alongside public.php's post-create
 * verification (ratesheet_cw_company_status_name() read-back), which is
 * the real safety net -- see that call site's comment.
 */
function ratesheet_cw_resolve_status_id_by_name(string $name): ?int
{
    try {
        $condition = 'name like "%' . ratesheet_cw_condition_escape($name) . '%"';
        $rows = ratesheet_cw_request('/company/statuses', ['conditions' => $condition, 'fields' => 'id,name', 'pageSize' => 200], 'GET', null, 15, 6);
        foreach ($rows as $row) {
            $rowName = $row['name'] ?? null;
            $rowId = $row['id'] ?? null;
            if (is_string($rowName) && is_int($rowId) && stripos($rowName, $name) !== false) {
                return (int) $rowId;
            }
        }
        error_log('ratesheet_cw_resolve_status_id_by_name: no ConnectWise Company Status matched "' . $name . '" among ' . count($rows) . ' returned row(s).');
    } catch (Throwable $e) {
        error_log('ratesheet_cw_resolve_status_id_by_name: lookup failed for "' . $name . '": ' . $e->getMessage());
    }
    return null;
}

/**
 * Live per-company Status name for ONE company (used by requests.php's
 * ?action=detail -- the printable record's single row). Returns null on
 * any failure (fail-open: the caller shows a neutral "unknown" state
 * rather than erroring the whole page).
 */
function ratesheet_cw_company_status_name(int $companyId): ?string
{
    try {
        $company = ratesheet_cw_request('/company/companies/' . $companyId, ['fields' => 'id,status'], 'GET', null, 15, 6);
        $name = $company['status']['name'] ?? null;
        return is_string($name) ? $name : null;
    } catch (Throwable $e) {
        error_log('ratesheet_cw_company_status_name: lookup failed for company ' . $companyId . ': ' . $e->getMessage());
        return null;
    }
}

/**
 * Live Status names for MULTIPLE companies in one batched request (used
 * by requests.php's ?action=list -- the dashboard, so N rows cost one
 * ConnectWise call, not N). Returns [companyId => statusName]; a company
 * id ConnectWise didn't return (deleted, or the lookup partially failed)
 * is simply absent from the result -- the caller treats a missing entry
 * as "unknown," never as any specific status. Returns [] (never throws)
 * if the whole batched call fails, same fail-open reasoning as the
 * single-company version above.
 */
function ratesheet_cw_company_statuses(array $companyIds): array
{
    $ids = array_values(array_unique(array_map('intval', $companyIds)));
    if ($ids === []) {
        return [];
    }
    try {
        $condition = 'id in (' . implode(',', $ids) . ')';
        $rows = ratesheet_cw_request('/company/companies', ['conditions' => $condition, 'fields' => 'id,status'], 'GET', null, 20, 8);
    } catch (Throwable $e) {
        error_log('ratesheet_cw_company_statuses: batched lookup failed for ' . count($ids) . ' companies: ' . $e->getMessage());
        return [];
    }
    $out = [];
    foreach ($rows as $row) {
        $id = $row['id'] ?? null;
        $name = $row['status']['name'] ?? null;
        if (is_int($id) && is_string($name)) {
            $out[$id] = $name;
        }
    }
    return $out;
}
