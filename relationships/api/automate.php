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

// ------------------------------------------------------------------ requests
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

    if ($action === 'computers' && isset($_GET['automate_client'])) {
        // Admin-only direct lookup by Automate's own client Id (the companyId in Automate's browse URLs), for testing.
        if (!relationships_current_user_is_territory_admin($pdo)) {
            relationships_respond(403, ['ok' => false, 'error' => 'Only an administrator can look up an Automate client directly.']);
        }
        $cid = (int) $_GET['automate_client'];
        $rows = relationships_automate_get('computers', ['condition' => 'Client.Id=' . $cid, 'pageSize' => 1000]);
        $out = [];
        foreach ($rows as $r) {
            $r = (array) $r;
            $out[] = [
                'id' => (int) relationships_automate_pick($r, ['Id', 'id'], 0),
                'name' => (string) relationships_automate_pick($r, ['ComputerName', 'computerName', 'Name', 'name'], ''),
                'os' => (string) relationships_automate_pick($r, ['OperatingSystemName', 'operatingSystemName', 'Os', 'os'], ''),
                'status' => (string) relationships_automate_pick($r, ['Status', 'status'], ''),
                'last_contact' => (string) relationships_automate_pick($r, ['RemoteAgentLastContact', 'LastContact', 'lastContact'], ''),
            ];
        }
        $first = $rows ? (array) $rows[0] : [];
        relationships_respond(200, ['ok' => true, 'automate_client_id' => $cid, 'count' => count($out), 'computers' => $out, 'raw_fields_of_first' => array_keys($first)]);
    }

    if ($action === 'computers') {
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
        $clients = relationships_automate_get('clients', ['pageSize' => 1000]);
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
        $rows = relationships_automate_get('computers', ['condition' => 'Client.Id=' . $cid, 'pageSize' => 1000]);
        $out = [];
        foreach ($rows as $r) {
            $r = (array) $r;
            $out[] = [
                'id' => (int) relationships_automate_pick($r, ['Id', 'id'], 0),
                'name' => (string) relationships_automate_pick($r, ['ComputerName', 'computerName', 'Name', 'name'], ''),
                'os' => (string) relationships_automate_pick($r, ['OperatingSystemName', 'operatingSystemName', 'Os', 'os'], ''),
                'status' => (string) relationships_automate_pick($r, ['Status', 'status'], ''),
                'last_contact' => (string) relationships_automate_pick($r, ['RemoteAgentLastContact', 'LastContact', 'lastContact'], ''),
            ];
        }
        relationships_respond(200, [
            'ok' => true,
            'matched_client' => ['id' => $cid, 'name' => (string) relationships_automate_pick($match, ['Name', 'name'], '')],
            'computers' => $out,
        ]);
    }
} catch (RelationshipsAutomateError $e) {
    error_log('[relationships/automate] ' . $e->getMessage());
    relationships_respond(502, ['ok' => false, 'error' => $e->getMessage()]);
}

relationships_respond(400, ['ok' => false, 'error' => 'Unknown action.']);
