<?php
/**
 * relationships/api/connectwise.php
 *
 * Small REST client for CodeBlue's own self-hosted ConnectWise Manage
 * instance (connect.codebluetechnology.com). Used by sync-connectwise.php
 * to pull active pillar agreements + their active additions and populate
 * customers/customer_services (source = 'connectwise') -- see catalog.php
 * for the pillar/service catalog those rows get classified into.
 *
 * Auth: HTTP Basic where the "username" is "{companyId}+{publicKey}" and
 * the "password" is the API Member's private key, base64-encoded together,
 * PLUS a separate required `clientId` header (a GUID from ConnectWise's
 * developer portal -- distinct from the API Member's public/private keys).
 * All four values plus the instance base URL live in connectwise-config.php
 * (gitignored -- see connectwise-config.sample.php for the template).
 */

declare(strict_types=1);

class RelationshipsConnectWiseError extends RuntimeException
{
}

function relationships_cw_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $path = __DIR__ . '/connectwise-config.php';
    if (!is_file($path)) {
        throw new RelationshipsConnectWiseError(
            'connectwise-config.php is missing. Copy connectwise-config.sample.php to ' .
            'connectwise-config.php in this same folder and fill in real values.'
        );
    }

    $config = require $path;
    foreach (['base_url', 'company_id', 'public_key', 'private_key', 'client_id'] as $key) {
        if (empty($config[$key])) {
            throw new RelationshipsConnectWiseError("connectwise-config.php is missing required key \"$key\".");
        }
    }
    return $config;
}

/**
 * One authenticated request against the ConnectWise REST API. $path is
 * relative to the v3.0 apis root, e.g. "/finance/agreements". $query is a
 * plain key => value array (this function handles urlencoding).
 *
 * $method/$jsonBody added 2026-09-11 for the ConnectWise Activity-creation
 * feature (checklist.php's 'set' action, via connectwise-activity-create.php)
 * -- every OTHER caller in this codebase before that was a GET, so both
 * default to the old GET-only behavior and every existing call site is
 * unaffected. Passing $jsonBody implies a POST body encoded as JSON with a
 * Content-Type header; $method lets a caller request PATCH/PUT/DELETE too,
 * though nothing uses those yet.
 *
 * Returns the decoded JSON body (array). Throws RelationshipsConnectWiseError
 * on any transport failure, non-2xx response, or malformed JSON body.
 */
function relationships_cw_request(string $path, array $query = [], string $method = 'GET', ?array $jsonBody = null): array
{
    $config = relationships_cw_config();
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
        throw new RelationshipsConnectWiseError("ConnectWise request failed (cURL error $errNo): $errStr — $url");
    }
    if ($status < 200 || $status >= 300) {
        // Widened from 500 to 3000 chars 2026-09-11 -- a real ConnectWise
        // 400 on POST /sales/activities came back with a multi-item
        // "errors" array (one entry per invalid/missing field), and the old
        // 500-char cap was cutting that array off mid-entry, hiding exactly
        // the detail needed to diagnose the next field. 3000 chars comfortably
        // fits ConnectWise's typical validation-error bodies while still
        // bounding runaway/unexpected response sizes.
        $snippet = is_string($body) ? substr($body, 0, 3000) : '';
        throw new RelationshipsConnectWiseError("ConnectWise request returned HTTP $status for $url — $snippet");
    }

    // A successful write can legitimately return an empty body (204) or a
    // JSON object (the created record) rather than the JSON *array* every
    // list/count GET in this codebase returns -- normalize both to an array
    // so callers don't have to special-case is_array() vs is_object()-shaped
    // JSON themselves.
    if ($body === '' || $body === false) {
        return [];
    }
    $decoded = json_decode((string) $body, true);
    if (!is_array($decoded)) {
        throw new RelationshipsConnectWiseError("ConnectWise response was not valid JSON for $url");
    }
    return $decoded;
}

/**
 * Attaches a local file to a ConnectWise record via POST /system/documents
 * (multipart/form-data) -- added 2026-09-23 for risk-scans.php, so an
 * uploaded risk scan also lands in the customer's Documents/attachments
 * tab in ConnectWise. $recordType is ConnectWise's own record-type name
 * ("Company" here); $recordId the numeric id. The file is streamed from
 * disk via CURLFile (never read into memory -- these zips can be hundreds
 * of MB), with a long timeout to match. Returns the created document
 * (its 'id' is what callers store). Throws RelationshipsConnectWiseError
 * on any transport failure or non-2xx response, same as
 * relationships_cw_request().
 *
 * NOT exercised against the live instance from this build environment (no
 * local ConnectWise credentials) -- the field names below (recordType,
 * recordId, title, file) are ConnectWise's documented multipart contract
 * for this endpoint, but confirm on the first real upload.
 */
function relationships_cw_upload_document(string $recordType, string $recordId, string $title, string $filePath, string $fileName, string $description = ''): array
{
    if (!is_file($filePath)) {
        throw new RelationshipsConnectWiseError('Local file to upload is missing: ' . $filePath);
    }
    $config = relationships_cw_config();
    $url = rtrim((string) $config['base_url'], '/') . '/v4_6_release/apis/3.0/system/documents';

    $authString = $config['company_id'] . '+' . $config['public_key'] . ':' . $config['private_key'];
    $headers = [
        'Authorization: Basic ' . base64_encode($authString),
        'clientId: ' . $config['client_id'],
        'Accept: application/json',
    ];

    $fields = [
        'recordType' => $recordType,
        'recordId' => $recordId,
        'title' => $title,
        'file' => new CURLFile($filePath, 'application/zip', $fileName),
    ];
    if ($description !== '') {
        $fields['description'] = $description;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $fields,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 240,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $body = curl_exec($ch);
    $errNo = curl_errno($ch);
    $errStr = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errNo !== 0) {
        throw new RelationshipsConnectWiseError("ConnectWise document upload failed (cURL error $errNo): $errStr");
    }
    if ($status < 200 || $status >= 300) {
        $snippet = is_string($body) ? substr($body, 0, 1500) : '';
        throw new RelationshipsConnectWiseError("ConnectWise document upload returned HTTP $status — $snippet");
    }
    $decoded = json_decode((string) $body, true);
    if (!is_array($decoded) || !isset($decoded['id'])) {
        throw new RelationshipsConnectWiseError('ConnectWise document upload succeeded but returned no document id.');
    }
    return $decoded;
}

/**
 * Pages through a ConnectWise list endpoint (agreements, additions, etc.)
 * and returns every row as a single flat array. $conditions is a raw
 * ConnectWise "conditions" query string, e.g. "type/id=65 and
 * agreementStatus='Active'" -- left as a string rather than built up here
 * since ConnectWise's condition syntax (quoting, "and"/"or", nested
 * field paths) doesn't map cleanly onto a generic query builder.
 *
 * $pageSize caps out at 1000 on ConnectWise's side; 200 is used by default
 * to keep individual requests reasonably fast on Bluehost's outbound
 * connection.
 */
function relationships_cw_list(string $path, string $conditions, array $fields, int $pageSize = 200): array
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
        $rows = relationships_cw_request($path, $query);
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
            // Sanity guard -- 200 pages * pageSize would be 40k+ rows,
            // far beyond anything this sync should ever see. Bail rather
            // than loop forever on an unexpected server response.
            break;
        }
    }
    return $all;
}

/**
 * Shared PUT-with-auto-strip mechanism for Company writes -- added
 * 2026-09-16 (Bug fix: saving an OutGrow Last Touch date 400'd instead of
 * reaching ConnectWise, see connectwise-outgrow.php's file header for the
 * full report). ConnectWise's Company PATCH endpoint on this instance
 * turns out to fully revalidate the ENTIRE stored record on every PATCH,
 * regardless of which field is actually targeted -- confirmed live: a
 * PATCH touching only the OutGrow Last Touch custom field on company 6790
 * came back HTTP 400 "company object is invalid" citing two completely
 * unrelated, already-invalid legacy field values ("The field
 * yearEstablished must be between 1900 and 9999.", same for
 * revenueYear). This is the SAME underlying quirk already found and
 * worked around for Company writes in the register app -- see
 * register/api/customers.php's register_cw_put_company_with_retry()
 * docblock (there it 500s with a generic DateTime error instead of a
 * structured 400, but the root cause and the fix are the same: PUT the
 * full record back instead of PATCH).
 *
 * Fetches the full Company record, merges $fieldsToSet on top (array
 * union -- a key present in both means $fieldsToSet's value wins, so only
 * the fields this call actually wants to change are ever touched
 * on purpose), and PUTs it back. If ConnectWise's response names a
 * specific offending field -- either "X can only be used when creating a
 * new company" (a create-only field the GET response includes but PUT
 * rejects) or ANY OTHER named-field error (OutOfRange, etc., on a
 * pre-existing legacy value this call never intended to change) -- that
 * field is removed from the payload and the PUT is retried, up to
 * $maxAttempts times (one bad field stripped per attempt, so a response
 * naming several fields resolves over a few retries, same as the
 * yearEstablished + revenueYear case above).
 *
 * IMPORTANT, NOT YET LIVE-CONFIRMED: when a field is stripped this way,
 * it's unconfirmed whether ConnectWise's PUT leaves that field's stored
 * value UNCHANGED (the intended, no-side-effect behavior) or resets it to
 * null/a default -- this build environment has no ConnectWise credentials
 * to test against real data (see connectwise-outgrow.php's file header
 * for the standing constraint). Michael should check company 6790's Year
 * Established / Revenue Year fields in ConnectWise right after the next
 * real OutGrow Last Touch save to confirm neither was cleared or changed
 * as a side effect.
 */
function relationships_cw_put_company_with_retry(string $cwCompanyId, array $fieldsToSet, int $maxAttempts = 5): array
{
    $full = relationships_cw_request('/company/companies/' . rawurlencode($cwCompanyId), []);
    $modified = $fieldsToSet + $full;

    for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
        try {
            return relationships_cw_request('/company/companies/' . rawurlencode($cwCompanyId), [], 'PUT', $modified);
        } catch (RelationshipsConnectWiseError $e) {
            $realKey = relationships_cw_offending_field($e->getMessage(), $modified);
            if ($realKey === null) {
                throw $e;
            }
            unset($modified[$realKey]);
        }
    }

    throw new RelationshipsConnectWiseError('Could not update company ' . $cwCompanyId . ' after ' . $maxAttempts . ' attempts.');
}

/**
 * Parses the JSON body embedded in a RelationshipsConnectWiseError message
 * (see relationships_cw_request()'s "HTTP $status ... $snippet" shape) for
 * a ConnectWise structured `errors[]` entry naming a specific field, and
 * resolves it to the matching key actually present in $payload -- trying
 * the literal name first, then a plural "XIds" -> "Xs" rewrite (ConnectWise's
 * own internal field-name convention, confirmed live via the register
 * app's identical mechanism). Returns null if the error body isn't
 * ConnectWise's structured shape, or names a field this payload doesn't
 * have under any of those spellings.
 */
function relationships_cw_offending_field(string $errorMessage, array $payload): ?string
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
