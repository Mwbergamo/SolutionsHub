<?php
/**
 * relationships/api/automate.php
 *
 * Read-only bridge to CodeBlue's ConnectWise Automate (RMM) server. Added 2026-10-07.
 * Credentials live in automate-config.php (gitignored; see automate-config.sample.php).
 *
 * Auth: Automate issues a short-lived bearer token from POST /cwa/api/v1/apitoken
 * ({UserName, Password}); every call also carries the integration "ClientId" header.
 * The token is cached in data/automate-token.json until shortly before it expires.
 *
 * GET ?action=test
 *   Admin only (Territory Admin list). Signs in and counts Automate clients -> { ok, clients, sample: [names] }.
 *   Use this once after creating automate-config.php to prove the connection works.
 *
 * GET ?action=computers&automate_client=<Automate client Id>   (admin only, for testing; also lists the field names Automate returned)
 *
 * GET ?action=computers&customer_id=N   (or &cw_company=<ConnectWise company number>)
 *   Any signed-in user who can see customer N. Finds the Automate client whose name matches the
 *   customer's name (exact, case-insensitive; falls back to "contains") and lists its computers.
 *   -> { ok, matched_client: {id,name}|null, computers: [ { id, name, os, status, last_contact, ... } ] }
 *
 * NOTE: nothing here writes to Automate. Field names are read defensively because Automate's
 * payload casing differs between versions.
 */

declare(strict_types=1);

require_once __DIR__ . '/_util.php';
require_once __DIR__ . '/territory-access.php';

class RelationshipsAutomateError extends RuntimeException {}

function relationships_automate_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }
    $path = __DIR__ . '/automate-config.php';
    if (!is_file($path)) {
        throw new RelationshipsAutomateError('automate-config.php is missing. Copy automate-config.sample.php to automate-config.php in this folder and fill in real values.');
    }
    $config = require $path;
    foreach (['base_url', 'client_id', 'username', 'password'] as $key) {
        if (empty($config[$key]) || $config[$key] === 'REPLACE_ME') {
            throw new RelationshipsAutomateError("automate-config.php is missing a real value for \"$key\".");
        }
    }
    $config['base_url'] = rtrim((string) $config['base_url'], '/');
    return $config;
}

function relationships_automate_http(string $method, string $path, ?array $json = null, ?string $token = null): array
{
    $cfg = relationships_automate_config();
    $headers = ['Accept: application/json', 'ClientId: ' . $cfg['client_id']];
    if ($token !== null) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    $ch = curl_init($cfg['base_url'] . '/cwa/api/v1/' . ltrim($path, '/'));
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    if ($json !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json));
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body === false) {
        throw new RelationshipsAutomateError('Could not reach the Automate server: ' . $err);
    }
    $data = json_decode((string) $body, true);
    if ($status < 200 || $status >= 300) {
        $msg = is_array($data) ? (string) ($data['Message'] ?? $data['message'] ?? '') : '';
        throw new RelationshipsAutomateError('Automate returned HTTP ' . $status . ($msg !== '' ? ': ' . $msg : '.'));
    }
    return is_array($data) ? $data : [];
}

function relationships_automate_token(): string
{
    $cache = __DIR__ . '/../data/automate-token.json';
    if (is_file($cache)) {
        $c = json_decode((string) @file_get_contents($cache), true);
        if (is_array($c) && !empty($c['token']) && (int) ($c['expires'] ?? 0) > time() + 120) {
            return (string) $c['token'];
        }
    }
    $cfg = relationships_automate_config();
    $r = relationships_automate_http('POST', 'apitoken', ['UserName' => $cfg['username'], 'Password' => $cfg['password']]);
    $token = (string) ($r['AccessToken'] ?? $r['accessToken'] ?? '');
    if ($token === '') {
        throw new RelationshipsAutomateError('Automate did not return a token. Check the username and password (the API user cannot use two-factor).');
    }
    $exp = strtotime((string) ($r['ExpiresAt'] ?? $r['expiresAt'] ?? '')) ?: (time() + 3000);
    @file_put_contents($cache, json_encode(['token' => $token, 'expires' => $exp]), LOCK_EX);
    @chmod($cache, 0600);
    return $token;
}

function relationships_automate_get(string $path, array $query = []): array
{
    $url = $path . ($query ? '?' . http_build_query($query) : '');
    try {
        return relationships_automate_http('GET', $url, null, relationships_automate_token());
    } catch (RelationshipsAutomateError $e) {
        if (strpos($e->getMessage(), 'HTTP 401') !== false) { // token revoked early: sign in again once
            @unlink(__DIR__ . '/../data/automate-token.json');
            return relationships_automate_http('GET', $url, null, relationships_automate_token());
        }
        throw $e;
    }
}

function relationships_automate_pick(array $row, array $keys, $default = null)
{
    foreach ($keys as $k) {
        if (array_key_exists($k, $row) && $row[$k] !== null && $row[$k] !== '') {
            return $row[$k];
        }
    }
    return $default;
}

/** Clients list, cached for 10 minutes (it is ~300 rows and every dashboard open needs it). */
function relationships_automate_clients(): array
{
    $cache = __DIR__ . '/../data/automate-clients.json';
    if (is_file($cache) && (time() - (int) @filemtime($cache)) < 600) {
        $c = json_decode((string) @file_get_contents($cache), true);
        if (is_array($c)) {
            return $c;
        }
    }
    $clients = relationships_automate_get('clients', ['pageSize' => 1000]);
    @file_put_contents($cache, json_encode($clients), LOCK_EX);
    @chmod($cache, 0600);
    return $clients;
}

/** One Automate computer, trimmed to what the dashboard card shows. */
function relationships_automate_computer_row(array $r): array
{
    $osFull = (string) relationships_automate_pick($r, ['OperatingSystemName', 'operatingSystemName', 'Os', 'os'], '');
    $os = trim((string) preg_replace('/\s*x(64|86)\s*$/i', '', str_replace('Microsoft ', '', $osFull)));
    return [
        'id' => (int) relationships_automate_pick($r, ['Id', 'id'], 0),
        'name' => (string) relationships_automate_pick($r, ['ComputerName', 'computerName', 'Name', 'name'], ''),
        'os' => $os,
        'os_full' => $osFull,
        'status' => (string) relationships_automate_pick($r, ['Status', 'status'], ''),
        'last_contact' => (string) relationships_automate_pick($r, ['RemoteAgentLastContact', 'LastContact', 'lastContact'], ''),
        'last_user' => (string) relationships_automate_pick($r, ['LastUserName', 'lastUserName'], ''),
        'ip' => (string) relationships_automate_pick($r, ['LocalIPAddress', 'localIPAddress'], ''),
        'type' => (string) relationships_automate_pick($r, ['Type', 'type'], ''),
        'reboot_needed' => (bool) relationships_automate_pick($r, ['IsRebootNeeded', 'isRebootNeeded'], false),
        'windows_update' => (string) relationships_automate_pick($r, ['WindowsUpdateDate', 'windowsUpdateDate'], ''),
        'av_date' => (string) relationships_automate_pick($r, ['AntivirusDefinitionDate', 'antivirusDefinitionDate'], ''),
        'warranty_end' => (string) relationships_automate_pick($r, ['WarrantyEndDate', 'warrantyEndDate'], ''),
        'serial' => (string) relationships_automate_pick($r, ['SerialNumber', 'serialNumber'], ''),
    ];
}

/** Online first, then by name; plus the counts the card header shows. */
function relationships_automate_computers_payload(array $rows): array
{
    $out = array_map(static fn ($r): array => relationships_automate_computer_row((array) $r), $rows);
    usort($out, static function (array $a, array $b): int {
        $oa = strcasecmp($a['status'], 'Online') === 0 ? 0 : 1;
        $ob = strcasecmp($b['status'], 'Online') === 0 ? 0 : 1;
        return $oa <=> $ob ?: strcasecmp($a['name'], $b['name']);
    });
    $online = count(array_filter($out, static fn (array $c): bool => strcasecmp($c['status'], 'Online') === 0));
    return ['computers' => $out, 'summary' => ['total' => count($out), 'online' => $online, 'offline' => count($out) - $online, 'reboot_needed' => count(array_filter($out, static fn (array $c): bool => $c['reboot_needed']))]];
}

require_once __DIR__ . '/automate-network.php';

/**
 * Finds the Automate client for a customer (customer_id or cw_company in the query string), with the same
 * territory rules and matching as the Computers card. Responds and exits when it cannot.
 * Returns [ matched Automate client row, Automate client id, customer row ].
 */
function relationships_automate_resolve(PDO $pdo): array
{
    $customerId = (int) ($_GET['customer_id'] ?? 0);
    $cwId = trim((string) ($_GET['cw_company'] ?? ''));
    if ($customerId > 0) {
        $stmt = $pdo->prepare('SELECT id, name, territory_name, connectwise_id FROM customers WHERE id = :id');
        $stmt->execute([':id' => $customerId]);
    } else {
        // same ConnectWise company number the ?cw_company=6216 dashboard links use
        $stmt = $pdo->prepare('SELECT id, name, territory_name, connectwise_id FROM customers WHERE connectwise_id = :cw ORDER BY id ASC LIMIT 1');
        $stmt->execute([':cw' => $cwId]);
    }
    $cust = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($cust === false) {
        relationships_respond(404, ['ok' => false, 'error' => 'Customer not found.']);
    }
    $allowed = relationships_allowed_territories($pdo); // same territory scoping as customers.php
    if ($allowed !== null && !in_array((string) $cust['territory_name'], $allowed, true)) {
        relationships_respond(403, ['ok' => false, 'error' => 'That customer is not in your territory.']);
    }
    $clients = relationships_automate_clients();
    // Normalise names: lower-case, "&" -> "and", drop punctuation and company suffixes (LLC, Inc, ...).
    $norm = static function (string $n): string {
        $n = mb_strtolower(trim($n));
        $n = str_replace('&', ' and ', $n);
        $n = preg_replace('/[^a-z0-9 ]+/', ' ', $n);
        $n = preg_replace('/\b(llc|inc|incorporated|corp|corporation|co|company|ltd|pllc|pc|llp|the)\b/', ' ', (string) $n);
        return trim((string) preg_replace('/\s+/', ' ', (string) $n));
    };
    $want = $norm((string) $cust['name']);
    $match = null;
    // 1) Automate's own link to the ConnectWise company, when the Manage plugin filled it in.
    $cwKey = trim((string) ($cwId !== '' ? $cwId : ($cust['connectwise_id'] ?? '')));
    if ($cwKey !== '') {
        foreach ($clients as $c) {
            $ext = trim((string) relationships_automate_pick((array) $c, ['ExternalId', 'externalId'], ''));
            if ($ext !== '' && $ext === $cwKey) { $match = (array) $c; break; }
        }
    }
    // 2) exact normalised name, then 3) one name contains the other.
    if ($match === null) {
        foreach ($clients as $c) {
            if ($want !== '' && $norm((string) relationships_automate_pick((array) $c, ['Name', 'name'], '')) === $want) { $match = (array) $c; break; }
        }
    }
    if ($match === null && $want !== '') {
        foreach ($clients as $c) {
            $n = $norm((string) relationships_automate_pick((array) $c, ['Name', 'name'], ''));
            if ($n !== '' && (strpos($n, $want) !== false || strpos($want, $n) !== false)) { $match = (array) $c; break; }
        }
    }
    if ($match === null) {
        // Help find out why: the closest Automate client names, with their ExternalId.
        $scored = [];
        foreach ($clients as $c) {
            $c = (array) $c;
            $name = (string) relationships_automate_pick($c, ['Name', 'name'], '');
            similar_text($want, $norm($name), $pct);
            $scored[] = ['name' => $name, 'external_id' => (string) relationships_automate_pick($c, ['ExternalId', 'externalId'], ''), 'score' => round($pct)];
        }
        usort($scored, static fn (array $x, array $y): int => $y['score'] <=> $x['score']);
        relationships_respond(200, [
            'ok' => true, 'matched_client' => null, 'computers' => [],
            'customer' => ['name' => (string) $cust['name'], 'connectwise_id' => $cwKey],
            'closest_automate_clients' => array_slice($scored, 0, 5),
        ]);
    }
    $cid = (int) relationships_automate_pick($match, ['Id', 'id'], 0);
    return [$match, $cid, $cust];
}

// ------------------------------------------------------------------ requests
if (defined('RELATIONSHIPS_AUTOMATE_LIB')) { return; } // included by the snapshot script: functions only
$pdo = relationships_db();
relationships_require_login($pdo);
$action = $_GET['action'] ?? '';

try {
    if ($action === 'test') {
        if (!relationships_current_user_is_territory_admin($pdo)) {
            relationships_respond(403, ['ok' => false, 'error' => 'Only an administrator can test the Automate connection.']);
        }
        $clients = relationships_automate_get('clients', ['pageSize' => 1000]);
        $names = [];
        foreach ($clients as $c) {
            $names[] = (string) relationships_automate_pick((array) $c, ['Name', 'name'], '');
        }
        relationships_respond(200, ['ok' => true, 'clients' => count($clients), 'sample' => array_slice($names, 0, 5)]);
    }

    if ($action === 'discover') {
        // Admin-only exploration of what this Automate server exposes, so the customer network page can be built on
        // real field names (make/model, storage, CPU, network devices...). Read-only; trims long values.
        if (!relationships_current_user_is_territory_admin($pdo)) {
            relationships_respond(403, ['ok' => false, 'error' => 'Only an administrator can run discovery.']);
        }
        $cid = (int) ($_GET['automate_client'] ?? 0);
        if ($cid <= 0) {
            relationships_respond(400, ['ok' => false, 'error' => 'Add &automate_client=<Automate client Id>.']);
        }
        $trim = static function ($v) use (&$trim) {
            if (is_array($v)) { return array_map($trim, array_slice($v, 0, 12, true)); }
            return is_string($v) && strlen($v) > 160 ? substr($v, 0, 160) . '...' : $v;
        };
        $probe = static function (string $path, array $q = []) use ($trim): array {
            try {
                $d = relationships_automate_get($path, $q);
                return ['path' => $path, 'ok' => true, 'count' => count($d), 'sample' => $trim(array_slice($d, 0, 2, true))];
            } catch (Throwable $e) {
                return ['path' => $path, 'ok' => false, 'error' => $e->getMessage()];
            }
        };
        $rows = relationships_automate_get('computers', ['condition' => 'Client.Id=' . $cid, 'pageSize' => 1000]);
        $hist = static function (array $rows, array $keys): array {
            $h = [];
            foreach ($rows as $r) {
                $v = (string) relationships_automate_pick((array) $r, $keys, '(blank)');
                $h[$v] = ($h[$v] ?? 0) + 1;
            }
            arsort($h);
            return array_slice($h, 0, 15, true);
        };
        $pickId = (int) ($_GET['computer'] ?? 0);
        $one = null;
        foreach ($rows as $r) {
            $r = (array) $r;
            if ($pickId ? (int) ($r['Id'] ?? 0) === $pickId : strcasecmp((string) ($r['Status'] ?? ''), 'Online') === 0) { $one = $r; break; }
        }
        $one = $one ?? ($rows ? (array) $rows[0] : []);
        $skip = ['UserAccounts', 'OpenPortsTCP', 'OpenPortsUDP', 'PowerProfiles', 'DomainNameServers'];
        $oneOut = [];
        foreach ($one as $k => $v) { if (!in_array($k, $skip, true)) { $oneOut[$k] = $trim($v); } }
        $id = (int) ($one['Id'] ?? 0);
        $paths = [];
        foreach (['/cwa/api/swagger/v1/swagger.json', '/cwa/api/v1/swagger.json', '/cwa/api/swagger.json', '/cwa/api/v1/docs/swagger.json'] as $sp) {
            try {
                $ch = curl_init(relationships_automate_config()['base_url'] . $sp);
                curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . relationships_automate_token(), 'ClientId: ' . relationships_automate_config()['client_id']]]);
                $body = curl_exec($ch); $st = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
                $j = json_decode((string) $body, true);
                if ($st === 200 && is_array($j) && isset($j['paths'])) {
                    $paths = array_values(array_filter(array_keys($j['paths']), static fn ($p) => (bool) preg_match('/device|network|drive|processor|bios|software|antivirus|hardware|monitor|probe|asset|warranty|patch|disk|memory|inventory/i', $p)));
                    $paths = ['swagger_url' => $sp, 'interesting_paths' => array_slice($paths, 0, 120), 'total_paths' => count($j['paths'])];
                    break;
                }
            } catch (Throwable $e) { /* try the next URL */ }
        }
        relationships_respond(200, [
            'ok' => true,
            'automate_client_id' => $cid,
            'computer_count' => count($rows),
            'type_counts' => $hist($rows, ['Type', 'type']),
            'os_counts' => $hist($rows, ['OperatingSystemName']),
            'virus_scanner_counts' => $hist($rows, ['VirusScanner']),
            'comment_samples' => array_slice(array_values(array_filter(array_map(static fn ($r) => (string) (((array) $r)['Comment'] ?? ''), $rows))), 0, 6),
            'location_samples' => array_slice(array_values(array_unique(array_map(static fn ($r) => json_encode(((array) $r)['Location'] ?? null), $rows))), 0, 3),
            'one_computer_all_fields' => $oneOut,
            'probes' => [
                $probe('computers/' . $id . '/drives'),
                $probe('computers/' . $id . '/processors'),
                $probe('computers/' . $id . '/bios'),
                $probe('computers/' . $id . '/software', ['pageSize' => 3]),
                $probe('computers/' . $id . '/networkadapters'),
                $probe('computers/' . $id . '/patches', ['pageSize' => 3]),
                $probe('networkdevices', ['pageSize' => 3]),
                $probe('networking/devices', ['pageSize' => 3]),
                $probe('devices', ['pageSize' => 3]),
                $probe('probes'),
                $probe('locations', ['pageSize' => 3, 'condition' => 'Client.Id=' . $cid]),
            ],
            'swagger' => $paths,
        ]);
    }

    if ($action === 'computers' && isset($_GET['automate_client'])) {
        // Admin-only direct lookup by Automate's own client Id (the companyId in Automate's browse URLs), for testing.
        if (!relationships_current_user_is_territory_admin($pdo)) {
            relationships_respond(403, ['ok' => false, 'error' => 'Only an administrator can look up an Automate client directly.']);
        }
        $cid = (int) $_GET['automate_client'];
        $rows = relationships_automate_get('computers', ['condition' => 'Client.Id=' . $cid, 'pageSize' => 1000]);
        $first = $rows ? (array) $rows[0] : [];
        relationships_respond(200, ['ok' => true, 'automate_client_id' => $cid, 'raw_fields_of_first' => array_keys($first)] + relationships_automate_computers_payload($rows));
    }

    if ($action === 'network') {
        [$match, $cid, $cust] = relationships_automate_resolve($pdo);
        relationships_respond(200, [
            'ok' => true,
            'matched_client' => ['id' => $cid, 'name' => (string) relationships_automate_pick($match, ['Name', 'name'], '')],
            'customer' => ['id' => (int) $cust['id'], 'name' => (string) $cust['name']],
            'console_url' => relationships_automate_config()['base_url'] . '/automate/browse/companies/computers?companyId=' . $cid,
        ] + relationships_automate_network_payload($pdo, $cid, true));
    }

    if ($action === 'computers') {
        [$match, $cid] = relationships_automate_resolve($pdo);
        $rows = relationships_automate_get('computers', ['condition' => 'Client.Id=' . $cid, 'pageSize' => 1000]);
        relationships_respond(200, [
            'ok' => true,
            'matched_client' => ['id' => $cid, 'name' => (string) relationships_automate_pick($match, ['Name', 'name'], '')],
            'console_url' => relationships_automate_config()['base_url'] . '/automate/browse/companies/computers?companyId=' . $cid,
        ] + relationships_automate_computers_payload($rows));
    }
} catch (RelationshipsAutomateError $e) {
    if (strpos($e->getMessage(), 'automate-config.php is missing') === 0) {
        relationships_respond(200, ['ok' => true, 'configured' => false, 'matched_client' => null, 'computers' => []]);
    }
    error_log('[relationships/automate] ' . $e->getMessage());
    relationships_respond(502, ['ok' => false, 'error' => $e->getMessage()]);
}

relationships_respond(400, ['ok' => false, 'error' => 'Unknown action.']);
