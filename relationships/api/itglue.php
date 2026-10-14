<?php
/**
 * relationships/api/itglue.php
 *
 * Read-only bridge to CodeBlue's IT Glue account. Added 2026-10-14 for the
 * "Asset Assignments" view (contacts -> hardware / software / licenses).
 * Credentials live in itglue-config.php (gitignored; see itglue-config.sample.php).
 *
 * IT Glue API: JSON:API, header "x-api-key", max page[size] 1000, 3000 requests
 * per 5 minutes. Nothing here writes to IT Glue (GET only).
 *
 * GET ?action=test
 *   Admin only (Territory Admin list). Proves the key works -> { ok, organizations, sample: [names] }.
 *
 * GET ?action=match&customer_id=N   (or &cw_company=<ConnectWise company number>)
 *   Any signed-in user who can see customer N. Finds the IT Glue organization whose name matches the
 *   customer's name (exact normalised, then "contains") -> { ok, matched_org: {id,name}|null, closest: [...] }.
 *
 * GET ?action=discover[&org=<IT Glue organization id>]
 *   Admin only. Lists the flexible asset types and their fields (to see how software / licenses are
 *   recorded), the configuration types with counts, and -- when org= is given -- counts of that
 *   organization's contacts / configurations / flexible assets per type.
 */

declare(strict_types=1);

require_once __DIR__ . '/_util.php';
require_once __DIR__ . '/territory-access.php';

class RelationshipsItglueError extends RuntimeException {}

function relationships_itglue_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }
    $path = __DIR__ . '/itglue-config.php';
    if (!is_file($path)) {
        throw new RelationshipsItglueError('itglue-config.php is missing. Copy itglue-config.sample.php to itglue-config.php in this folder and fill in the API key.');
    }
    $config = require $path;
    foreach (['base_url', 'api_key'] as $key) {
        if (empty($config[$key]) || $config[$key] === 'REPLACE_ME') {
            throw new RelationshipsItglueError("itglue-config.php is missing a real value for \"$key\".");
        }
    }
    $config['base_url'] = rtrim((string) $config['base_url'], '/');
    return $config;
}

/** One GET. $path like "organizations"; $query uses IT Glue's bracket style, e.g. ['page' => ['size' => 50]]. */
function relationships_itglue_get(string $path, array $query = []): array
{
    $cfg = relationships_itglue_config();
    $url = $cfg['base_url'] . '/' . ltrim($path, '/') . ($query ? '?' . http_build_query($query) : '');
    for ($attempt = 0; $attempt < 2; $attempt++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Accept: application/vnd.api+json', 'x-api-key: ' . $cfg['api_key']],
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            throw new RelationshipsItglueError('Could not reach IT Glue: ' . $err);
        }
        if ($status === 429 && $attempt === 0) { // rate limited: wait once and retry
            sleep(2);
            continue;
        }
        $data = json_decode((string) $body, true);
        if ($status === 403 || $status === 401) {
            throw new RelationshipsItglueError('IT Glue rejected the API key (HTTP ' . $status . '). Check the key and the base_url data center.');
        }
        if ($status < 200 || $status >= 300) {
            $msg = '';
            if (is_array($data) && isset($data['errors'][0])) {
                $msg = (string) ($data['errors'][0]['detail'] ?? $data['errors'][0]['title'] ?? '');
            }
            throw new RelationshipsItglueError('IT Glue returned HTTP ' . $status . ($msg !== '' ? ': ' . $msg : '.'));
        }
        return is_array($data) ? $data : [];
    }
    throw new RelationshipsItglueError('IT Glue is rate limiting requests (HTTP 429). Try again in a minute.');
}

/**
 * Every page of a list endpoint. Returns [ rows (data items), included (merged), total ].
 * $maxPages is a safety cap (1000 rows per page).
 */
function relationships_itglue_get_all(string $path, array $query = [], int $maxPages = 10): array
{
    $query['page'] = ['size' => 1000, 'number' => 1];
    $rows = [];
    $included = [];
    $total = 0;
    for ($p = 1; $p <= $maxPages; $p++) {
        $query['page']['number'] = $p;
        $r = relationships_itglue_get($path, $query);
        foreach ((array) ($r['data'] ?? []) as $row) { $rows[] = $row; }
        foreach ((array) ($r['included'] ?? []) as $inc) { $included[] = $inc; }
        $total = (int) ($r['meta']['total-count'] ?? count($rows));
        if (empty($r['meta']['next-page'])) {
            break;
        }
    }
    return [$rows, $included, $total];
}

function relationships_itglue_attr(array $row, string $key, $default = null)
{
    $a = $row['attributes'] ?? [];
    return array_key_exists($key, $a) && $a[$key] !== null && $a[$key] !== '' ? $a[$key] : $default;
}

/** Organizations list, cached for 10 minutes (a few hundred rows; every match needs it). */
function relationships_itglue_orgs(): array
{
    $cache = __DIR__ . '/../data/itglue-orgs.json';
    if (is_file($cache) && (time() - (int) @filemtime($cache)) < 600) {
        $c = json_decode((string) @file_get_contents($cache), true);
        if (is_array($c)) {
            return $c;
        }
    }
    [$rows] = relationships_itglue_get_all('organizations', ['sort' => 'name']);
    $orgs = array_map(static fn (array $r): array => [
        'id' => (int) $r['id'],
        'name' => (string) relationships_itglue_attr($r, 'name', ''),
        'short_name' => (string) relationships_itglue_attr($r, 'short-name', ''),
        'status' => (string) relationships_itglue_attr($r, 'organization-status-name', ''),
        'psa_id' => (string) relationships_itglue_attr($r, 'psa-id', ''),
    ], $rows);
    @file_put_contents($cache, json_encode($orgs), LOCK_EX);
    @chmod($cache, 0600);
    return $orgs;
}

/** Lower-case, "&" -> "and", drop punctuation and company suffixes (same rule as the Automate match). */
function relationships_itglue_norm(string $n): string
{
    $n = mb_strtolower(trim($n));
    $n = str_replace('&', ' and ', $n);
    $n = preg_replace('/[^a-z0-9 ]+/', ' ', $n);
    $n = preg_replace('/\b(llc|inc|incorporated|corp|corporation|co|company|ltd|pllc|pc|llp|the)\b/', ' ', (string) $n);
    return trim((string) preg_replace('/\s+/', ' ', (string) $n));
}

/**
 * Finds the IT Glue organization for a Relationships customer (customer_id or cw_company in the query string),
 * with the same territory rules as customers.php. Returns [ matched org|null, customer row, closest orgs ].
 */
function relationships_itglue_resolve(PDO $pdo): array
{
    $customerId = (int) ($_GET['customer_id'] ?? 0);
    $cwId = trim((string) ($_GET['cw_company'] ?? ''));
    if ($customerId > 0) {
        $stmt = $pdo->prepare('SELECT id, name, territory_name, connectwise_id FROM customers WHERE id = :id');
        $stmt->execute([':id' => $customerId]);
    } else {
        $stmt = $pdo->prepare('SELECT id, name, territory_name, connectwise_id FROM customers WHERE connectwise_id = :cw ORDER BY id ASC LIMIT 1');
        $stmt->execute([':cw' => $cwId]);
    }
    $cust = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($cust === false) {
        relationships_respond(404, ['ok' => false, 'error' => 'Customer not found.']);
    }
    $allowed = relationships_allowed_territories($pdo);
    if ($allowed !== null && !in_array((string) $cust['territory_name'], $allowed, true)) {
        relationships_respond(403, ['ok' => false, 'error' => 'That customer is not in your territory.']);
    }
    $orgs = relationships_itglue_orgs();
    $want = relationships_itglue_norm((string) $cust['name']);
    $match = null;
    // 1) IT Glue organizations synced from ConnectWise carry the PSA company number.
    $cwKey = trim((string) ($cwId !== '' ? $cwId : ($cust['connectwise_id'] ?? '')));
    if ($cwKey !== '') {
        foreach ($orgs as $o) {
            if ($o['psa_id'] !== '' && $o['psa_id'] === $cwKey) { $match = $o; break; }
        }
    }
    // 2) exact normalised name, then 3) one name contains the other.
    if ($match === null && $want !== '') {
        foreach ($orgs as $o) {
            if (relationships_itglue_norm($o['name']) === $want) { $match = $o; break; }
        }
    }
    if ($match === null && $want !== '') {
        foreach ($orgs as $o) {
            $n = relationships_itglue_norm($o['name']);
            if ($n !== '' && (strpos($n, $want) !== false || strpos($want, $n) !== false)) { $match = $o; break; }
        }
    }
    $closest = [];
    if ($match === null) {
        $scored = [];
        foreach ($orgs as $o) {
            similar_text($want, relationships_itglue_norm($o['name']), $pct);
            $scored[] = ['id' => $o['id'], 'name' => $o['name'], 'score' => round($pct)];
        }
        usort($scored, static fn (array $x, array $y): int => $y['score'] <=> $x['score']);
        $closest = array_slice($scored, 0, 5);
    }
    return [$match, $cust, $closest];
}

// ------------------------------------------------------------------ requests
if (defined('RELATIONSHIPS_ITGLUE_LIB')) { return; } // included by another script: functions only
$pdo = relationships_db();
relationships_require_login($pdo);
$action = $_GET['action'] ?? '';

try {
    if ($action === 'test') {
        if (!relationships_current_user_is_territory_admin($pdo)) {
            relationships_respond(403, ['ok' => false, 'error' => 'Only an administrator can test the IT Glue connection.']);
        }
        $r = relationships_itglue_get('organizations', ['page' => ['size' => 5], 'sort' => 'name']);
        $names = array_map(static fn (array $o): string => (string) relationships_itglue_attr($o, 'name', ''), (array) ($r['data'] ?? []));
        relationships_respond(200, ['ok' => true, 'organizations' => (int) ($r['meta']['total-count'] ?? count($names)), 'sample' => $names]);
    }

    if ($action === 'match') {
        [$match, $cust, $closest] = relationships_itglue_resolve($pdo);
        relationships_respond(200, [
            'ok' => true,
            'configured' => true,
            'customer' => ['id' => (int) $cust['id'], 'name' => (string) $cust['name']],
            'matched_org' => $match === null ? null : ['id' => $match['id'], 'name' => $match['name']],
            'closest' => $closest,
        ]);
    }

    if ($action === 'discover') {
        if (!relationships_current_user_is_territory_admin($pdo)) {
            relationships_respond(403, ['ok' => false, 'error' => 'Only an administrator can run discovery.']);
        }
        // Flexible asset types with their fields: this is where software / license records usually live.
        [$types, $incl] = relationships_itglue_get_all('flexible_asset_types', ['include' => 'flexible_asset_fields']);
        $fieldsByType = [];
        foreach ($incl as $f) {
            if (($f['type'] ?? '') !== 'flexible-asset-fields') { continue; }
            $tid = (int) relationships_itglue_attr($f, 'flexible-asset-type-id', 0);
            $fieldsByType[$tid][] = [
                'name' => (string) relationships_itglue_attr($f, 'name', ''),
                'kind' => (string) relationships_itglue_attr($f, 'kind', ''),
                'tag_type' => (string) relationships_itglue_attr($f, 'tag-type', ''),
            ];
        }
        $faTypes = array_map(static fn (array $t): array => [
            'id' => (int) $t['id'],
            'name' => (string) relationships_itglue_attr($t, 'name', ''),
            'enabled' => (bool) relationships_itglue_attr($t, 'enabled', true),
            'fields' => $fieldsByType[(int) $t['id']] ?? [],
        ], $types);
        [$cfgTypes] = relationships_itglue_get_all('configuration_types');
        $out = [
            'ok' => true,
            'flexible_asset_types' => $faTypes,
            'configuration_types' => array_map(static fn (array $t): array => [
                'id' => (int) $t['id'],
                'name' => (string) relationships_itglue_attr($t, 'name', ''),
                'count' => (int) relationships_itglue_attr($t, 'configurations-count', 0),
            ], $cfgTypes),
        ];
        $orgId = (int) ($_GET['org'] ?? 0);
        if ($orgId > 0) {
            $count = static function (string $path, array $q) : int {
                $r = relationships_itglue_get($path, $q + ['page' => ['size' => 1]]);
                return (int) ($r['meta']['total-count'] ?? 0);
            };
            $perType = [];
            foreach ($faTypes as $t) {
                $n = $count('flexible_assets', ['filter' => ['flexible-asset-type-id' => $t['id'], 'organization-id' => $orgId]]);
                if ($n > 0) { $perType[$t['name']] = $n; }
            }
            $out['org'] = [
                'id' => $orgId,
                'contacts' => $count('contacts', ['filter' => ['organization_id' => $orgId]]),
                'configurations' => $count('configurations', ['filter' => ['organization_id' => $orgId]]),
                'flexible_assets_by_type' => $perType,
            ];
        }
        relationships_respond(200, $out);
    }

    relationships_respond(400, ['ok' => false, 'error' => 'Unknown action.']);
} catch (RelationshipsItglueError $e) {
    if (strpos($e->getMessage(), 'itglue-config.php is missing') === 0) {
        relationships_respond(200, ['ok' => true, 'configured' => false, 'matched_org' => null]);
    }
    error_log('[relationships/itglue] ' . $e->getMessage());
    relationships_respond(502, ['ok' => false, 'error' => $e->getMessage()]);
}
