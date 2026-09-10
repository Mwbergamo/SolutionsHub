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
 * Returns the decoded JSON body (array). Throws RelationshipsConnectWiseError
 * on any transport failure, non-2xx response, or malformed JSON body.
 */
function relationships_cw_request(string $path, array $query = []): array
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

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 60,
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
        throw new RelationshipsConnectWiseError("ConnectWise request failed (cURL error $errNo): $errStr — $url");
    }
    if ($status < 200 || $status >= 300) {
        $snippet = is_string($body) ? substr($body, 0, 500) : '';
        throw new RelationshipsConnectWiseError("ConnectWise request returned HTTP $status for $url — $snippet");
    }

    $decoded = json_decode((string) $body, true);
    if (!is_array($decoded)) {
        throw new RelationshipsConnectWiseError("ConnectWise response was not valid JSON for $url");
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
