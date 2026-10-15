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
 * GET ?action=assignments&customer_id=N   (or &cw_company=...; add &refresh=1 to skip the 5-minute cache)
 *   Any signed-in user who can see customer N. Matches the customer to its IT Glue organization, then returns
 *   { contacts[], devices[] (with contact_id), licenses[] (contact_ids), software[] (device_ids), protection[]
 *   (antivirus/backup, device_ids) } -- the Asset Assignments page joins them. No passwords are returned.
 *
 * POST ?action=link_set|link_clear&customer_id=N   (needs header X-Requested-With: fetch)
 *   Manual device -> person links, stored in asset_links. Anyone who can see the customer may set or clear them.
 *
 * GET ?action=discover[&org=<IT Glue organization id>]
 *   Admin only. Lists the flexible asset types and their fields (to see how software / licenses are
 *   recorded), the configuration types with counts, and -- when org= is given -- counts of that
 *   organization's contacts / configurations / flexible assets per type.
 */

declare(strict_types=1);

require_once __DIR__ . '/_util.php';
require_once __DIR__ . '/territory-access.php';
require_once __DIR__ . '/connectwise-meeting-activity.php';

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

/** Letters+digits only, lower-case: lets "Device(s)" match the trait key "device-s" and "Licensed Users" match "licensed-users". */
function relationships_itglue_key(string $s): string
{
    return (string) preg_replace('/[^a-z0-9]+/', '', mb_strtolower($s));
}

/** A flexible asset trait by its field name (e.g. "Licensed Users"), whatever punctuation IT Glue used in the key. */
function relationships_itglue_trait(array $asset, string $fieldName)
{
    $want = relationships_itglue_key($fieldName);
    foreach ((array) ($asset['attributes']['traits'] ?? []) as $k => $v) {
        if (relationships_itglue_key((string) $k) === $want) {
            return $v;
        }
    }
    return null;
}

/** The ids a Tag trait points at. IT Glue returns either a list of objects with "id" or plain ids. */
function relationships_itglue_tag_ids($v): array
{
    $ids = [];
    foreach ((array) ($v ?? []) as $item) {
        if (is_array($item)) {
            $id = $item['id'] ?? $item['values']['id'] ?? null;
        } else {
            $id = $item;
        }
        if (is_numeric($id)) {
            $ids[] = (int) $id;
        }
    }
    return array_values(array_unique($ids));
}

/** Flexible asset type name -> id, cached for an hour (the list almost never changes). */
function relationships_itglue_type_ids(): array
{
    $cache = __DIR__ . '/../data/itglue-types.json';
    if (is_file($cache) && (time() - (int) @filemtime($cache)) < 3600) {
        $c = json_decode((string) @file_get_contents($cache), true);
        if (is_array($c)) {
            return $c;
        }
    }
    [$rows] = relationships_itglue_get_all('flexible_asset_types');
    $map = [];
    foreach ($rows as $r) {
        $map[relationships_itglue_key((string) relationships_itglue_attr($r, 'name', ''))] = (int) $r['id'];
    }
    @file_put_contents($cache, json_encode($map), LOCK_EX);
    @chmod($cache, 0600);
    return $map;
}

/**
 * Everything the Asset Assignments page draws for one IT Glue organization: people, devices, licenses,
 * software, antivirus and backup records, with the links between them as ids. The page does the joining.
 * No passwords are requested or returned. Cached 5 minutes per organization (?refresh=1 skips the cache).
 */
function relationships_itglue_assignments(int $orgId, bool $refresh): array
{
    $cache = __DIR__ . '/../data/itglue-assign-' . $orgId . '.json';
    if (!$refresh && is_file($cache) && (time() - (int) @filemtime($cache)) < 300) {
        $c = json_decode((string) @file_get_contents($cache), true);
        if (is_array($c)) {
            return $c;
        }
    }
    $pick = static fn (array $r, string $k, $d = '') => relationships_itglue_attr($r, $k, $d);

    [$contactRows] = relationships_itglue_get_all('contacts', ['filter' => ['organization_id' => $orgId], 'sort' => 'last_name']);
    $contacts = [];
    foreach ($contactRows as $r) {
        $email = '';
        foreach ((array) ($r['attributes']['contact-emails'] ?? []) as $e) {
            if (!empty($e['primary']) || $email === '') { $email = (string) ($e['value'] ?? $email); }
        }
        $contacts[] = [
            'id' => (int) $r['id'],
            'name' => (string) $pick($r, 'name', trim($pick($r, 'first-name') . ' ' . $pick($r, 'last-name'))),
            'title' => (string) $pick($r, 'title'),
            'type' => (string) $pick($r, 'contact-type-name'),
            'email' => $email,
            'important' => (bool) $pick($r, 'important', false),
            'url' => (string) $pick($r, 'resource-url'),
        ];
    }

    [$cfgRows] = relationships_itglue_get_all('configurations', ['filter' => ['organization_id' => $orgId, 'archived' => 'false'], 'sort' => 'name']);
    $devices = [];
    foreach ($cfgRows as $r) {
        $devices[] = [
            'id' => (int) $r['id'],
            'name' => (string) $pick($r, 'name'),
            'hostname' => (string) $pick($r, 'hostname'),
            'type' => (string) $pick($r, 'configuration-type-name'),
            'status' => (string) $pick($r, 'configuration-status-name'),
            'contact_id' => (int) $pick($r, 'contact-id', 0),
            'make' => (string) $pick($r, 'manufacturer-name'),
            'model' => (string) $pick($r, 'model-name'),
            'os' => (string) $pick($r, 'operating-system-name'),
            'serial' => (string) $pick($r, 'serial-number'),
            'ip' => (string) $pick($r, 'primary-ip'),
            'warranty_expires' => (string) $pick($r, 'warranty-expires-at'),
            'url' => (string) $pick($r, 'resource-url'),
        ];
    }

    $typeIds = relationships_itglue_type_ids();
    $fa = static function (string $typeName) use ($typeIds, $orgId): array {
        $tid = $typeIds[relationships_itglue_key($typeName)] ?? 0;
        if ($tid === 0) {
            return [];
        }
        [$rows] = relationships_itglue_get_all('flexible_assets', ['filter' => ['flexible-asset-type-id' => $tid, 'organization-id' => $orgId]]);
        return $rows;
    };
    $txt = static fn ($v): string => is_scalar($v) ? trim((string) $v) : '';

    $licenses = [];
    foreach ($fa('Microsoft Licenses') as $a) {
        $licenses[] = [
            'id' => (int) $a['id'],
            'name' => $txt(relationships_itglue_trait($a, 'License Name')) ?: (string) $pick($a, 'name'),
            'active' => (int) relationships_itglue_trait($a, 'Active'),
            'consumed' => (int) relationships_itglue_trait($a, 'Consumed'),
            'unused' => (int) relationships_itglue_trait($a, 'Unused'),
            'contact_ids' => relationships_itglue_tag_ids(relationships_itglue_trait($a, 'Licensed Users')),
            'url' => (string) $pick($a, 'resource-url'),
        ];
    }
    $software = [];
    foreach ($fa('Software') as $a) {
        $software[] = [
            'id' => (int) $a['id'],
            'name' => $txt(relationships_itglue_trait($a, 'Software Name')) ?: (string) $pick($a, 'name'),
            'vendor' => $txt(relationships_itglue_trait($a, 'Vendor Name')),
            'device_ids' => relationships_itglue_tag_ids(relationships_itglue_trait($a, 'Device(s)')),
            'url' => (string) $pick($a, 'resource-url'),
        ];
    }
    $protection = [];
    foreach ([['Antivirus', 'Antivirus Platform', 'antivirus'], ['Backup', 'Backup Platform', 'backup']] as [$type, $platformField, $kind]) {
        foreach ($fa($type) as $a) {
            $protection[] = [
                'id' => (int) $a['id'],
                'kind' => $kind,
                'platform' => $txt(relationships_itglue_trait($a, $platformField)) ?: (string) $pick($a, 'name'),
                'device_ids' => relationships_itglue_tag_ids(relationships_itglue_trait($a, 'Protected Devices')),
                'url' => (string) $pick($a, 'resource-url'),
            ];
        }
    }

    $out = [
        'contacts' => $contacts,
        'devices' => $devices,
        'licenses' => $licenses,
        'software' => $software,
        'protection' => $protection,
        'generated_at' => gmdate('c'),
    ];
    @file_put_contents($cache, json_encode($out), LOCK_EX);
    @chmod($cache, 0600);
    return $out;
}

/** Manual links for one customer, newest first: [ {device_id, contact_id, contact_name, linked_by, linked_at} ]. */
function relationships_itglue_links(PDO $pdo, int $customerId): array
{
    $stmt = $pdo->prepare('SELECT device_id, contact_id, contact_name, linked_by, linked_at FROM asset_links WHERE customer_id = :c ORDER BY linked_at DESC');
    $stmt->execute([':c' => $customerId]);
    return array_map(static fn (array $r): array => [
        'device_id' => (int) $r['device_id'],
        'contact_id' => (int) $r['contact_id'],
        'contact_name' => (string) $r['contact_name'],
        'linked_by' => (string) $r['linked_by'],
        'linked_at' => (string) $r['linked_at'],
    ], $stmt->fetchAll(PDO::FETCH_ASSOC));
}

/** Minimal CW company id lookup, mirroring meetings.php's relationships_meetings_cw_id() --
 *  kept as a local copy rather than require_once'ing meetings.php, which runs its own
 *  top-level request dispatch at file scope and would hijack this request. */
function relationships_itglue_cw_company_id(PDO $pdo, int $customerId): ?string
{
    $stmt = $pdo->prepare('SELECT connectwise_id FROM customers WHERE id = :id');
    $stmt->execute([':id' => $customerId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $cwId = $row['connectwise_id'] ?? null;
    return ($cwId === null || $cwId === '' || str_starts_with((string) $cwId, 'MOCK-')) ? null : (string) $cwId;
}

/** crc_users (id, email) for a name, matched case-insensitively -- mirrors meetings.php's
 *  relationships_meetings_user_by_name() (see the comment above). */
function relationships_itglue_user_by_name(PDO $pdo, string $name): ?array
{
    $stmt = $pdo->prepare('SELECT id, email FROM crc_users WHERE LOWER(name) = LOWER(:name) LIMIT 1');
    $stmt->execute([':name' => $name]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row !== false ? ['id' => (int) $row['id'], 'email' => (string) $row['email']] : null;
}

/**
 * Creates a general to-do under a customer and tries to push it to ConnectWise as an Activity --
 * the SAME create workflow meetings.php's add_general_todo action uses (local save first,
 * unconditionally; then try ConnectWise; never let a ConnectWise failure block or revert the
 * local save). Used by every Asset Assignments action that records a change a rep made during a
 * meeting (a Microsoft License swap, a PC assignment, a manual column edit, ...) so there's
 * always a dated, attributed record of what was decided -- IT Glue itself is never written to
 * (see this file's header), so the to-do is what actually drives the follow-through.
 * $assignedToName defaults to the signed-in rep if they're on the fixed 7-name roster
 * (relationships_todo_roster()), else the roster's first name -- every signed-in CRC in practice
 * is one of the 7, so this should always resolve to the real rep who made the change.
 */
function relationships_itglue_create_todo(PDO $pdo, array $user, int $customerId, string $description, ?string $assignedToName = null): void
{
    $roster = relationships_todo_roster();
    if ($assignedToName === null || !in_array($assignedToName, $roster, true)) {
        $assignedToName = in_array((string) $user['name'], $roster, true) ? (string) $user['name'] : $roster[0];
    }
    if (mb_strlen($description) > 500) {
        $description = mb_substr($description, 0, 497) . '...';
    }
    $assignee = relationships_itglue_user_by_name($pdo, $assignedToName);
    $insert = $pdo->prepare(
        'INSERT INTO general_todos (customer_id, description, assigned_to_user_id, assigned_to_name, created_by_user_id, created_by_name, due_date)
         VALUES (:cid, :desc, :auid, :aname, :cuid, :cname, NULL)'
    );
    $insert->execute([
        ':cid' => $customerId, ':desc' => $description,
        ':auid' => $assignee['id'] ?? null, ':aname' => $assignedToName,
        ':cuid' => $user['id'], ':cname' => $user['name'],
    ]);
    $todoId = (int) $pdo->lastInsertId();

    $cwCompanyId = relationships_itglue_cw_company_id($pdo, $customerId);
    if ($cwCompanyId === null) {
        $pdo->prepare('UPDATE general_todos SET cw_push_status = :status WHERE id = :id')->execute([':status' => 'skipped', ':id' => $todoId]);
        return;
    }
    try {
        $nowEastern = new DateTimeImmutable('now', new DateTimeZone('America/New_York'));
        $result = relationships_cw_create_general_todo_activity($pdo, [
            'customer_id' => $customerId,
            'cw_company_id' => $cwCompanyId,
            'description' => $description,
            'assigned_to_name' => $assignedToName,
            'assigned_to_email' => relationships_todo_roster_cw_email($assignedToName),
            'created_by_name' => $user['name'],
            'created_at_display' => $nowEastern->format('M j, Y g:i A T'),
            'due_date' => null,
        ]);
        $pdo->prepare('UPDATE general_todos SET cw_activity_id = :aid, cw_push_status = :status, cw_push_error = NULL WHERE id = :id')
            ->execute([':aid' => $result['id'], ':status' => 'pushed', ':id' => $todoId]);
    } catch (Throwable $e) {
        $pdo->prepare('UPDATE general_todos SET cw_push_status = :status, cw_push_error = :err WHERE id = :id')
            ->execute([':status' => 'error', ':err' => substr($e->getMessage(), 0, 4000), ':id' => $todoId]);
    }
}

/** Manual overrides for one customer's grid cells: [ {contact_id, column_key, value, set_by_name, set_at} ]. */
function relationships_itglue_overrides(PDO $pdo, int $customerId): array
{
    $stmt = $pdo->prepare('SELECT contact_id, column_key, value, set_by_name, set_at FROM asset_overrides WHERE customer_id = :c');
    $stmt->execute([':c' => $customerId]);
    return array_map(static fn (array $r): array => [
        'contact_id' => (int) $r['contact_id'], 'column_key' => (string) $r['column_key'],
        'value' => (string) $r['value'], 'set_by_name' => (string) $r['set_by_name'], 'set_at' => (string) $r['set_at'],
    ], $stmt->fetchAll(PDO::FETCH_ASSOC));
}

/** Inactive flags for one customer's IT Glue contacts: [ {contact_id, inactive, set_by_name, set_at} ]. */
function relationships_itglue_contact_status(PDO $pdo, int $customerId): array
{
    $stmt = $pdo->prepare('SELECT contact_id, inactive, set_by_name, set_at FROM asset_contact_status WHERE customer_id = :c');
    $stmt->execute([':c' => $customerId]);
    return array_map(static fn (array $r): array => [
        'contact_id' => (int) $r['contact_id'], 'inactive' => (bool) $r['inactive'],
        'set_by_name' => (string) $r['set_by_name'], 'set_at' => (string) $r['set_at'],
    ], $stmt->fetchAll(PDO::FETCH_ASSOC));
}

/**
 * This customer's real managed-service line items from their ConnectWise Agreement
 * (customer_services -- synced by connectwise-sync-core.php from the Agreement's Additions; see
 * customers.php's 'detail' action for the other consumer of this table). Passed through raw --
 * label + quantity only -- so the Managed Services column's device-type/coverage classification
 * (which services a specific person's computer actually gets) happens client-side in assets.js
 * (see SERVICE_FAMILIES there). This app never guesses at CW's product catalog server-side.
 */
function relationships_itglue_customer_services(PDO $pdo, int $customerId): array
{
    $stmt = $pdo->prepare('SELECT product_label, qty, unit FROM customer_services WHERE customer_id = :id ORDER BY product_label');
    $stmt->execute([':id' => $customerId]);
    return array_map(static function (array $r): array {
        $qty = (float) $r['qty'];
        return [
            'label' => (string) $r['product_label'],
            'qty' => $qty == (int) $qty ? (int) $qty : $qty,
            'unit' => (string) ($r['unit'] ?? ''),
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));
}

/** The shared manual-column definitions (same across every customer), ordered for display. */
function relationships_itglue_grid_columns(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT column_key, label, options FROM asset_grid_columns ORDER BY position, id');
    return array_map(static fn (array $r): array => [
        'column_key' => (string) $r['column_key'], 'label' => (string) $r['label'],
        'options' => $r['options'] === null ? null : array_values(array_filter(array_map('trim', explode(',', (string) $r['options'])))),
    ], $stmt->fetchAll(PDO::FETCH_ASSOC));
}

// ------------------------------------------------------------------ requests
if (defined('RELATIONSHIPS_ITGLUE_LIB')) { return; } // included by another script: functions only
$pdo = relationships_db();
$user = relationships_require_login($pdo);
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

    if ($action === 'assignments') {
        [$match, $cust, $closest] = relationships_itglue_resolve($pdo);
        if ($match === null) {
            relationships_respond(200, [
                'ok' => true, 'configured' => true, 'matched_org' => null,
                'customer' => ['id' => (int) $cust['id'], 'name' => (string) $cust['name']],
                'closest' => $closest,
            ]);
        }
        relationships_respond(200, [
            'ok' => true, 'configured' => true,
            'customer' => ['id' => (int) $cust['id'], 'name' => (string) $cust['name']],
            'matched_org' => ['id' => $match['id'], 'name' => $match['name']],
        ] + relationships_itglue_assignments((int) $match['id'], !empty($_GET['refresh'])) + [
            'links' => relationships_itglue_links($pdo, (int) $cust['id']),
            'overrides' => relationships_itglue_overrides($pdo, (int) $cust['id']),
            'contact_status' => relationships_itglue_contact_status($pdo, (int) $cust['id']),
            'columns' => relationships_itglue_grid_columns($pdo),
            'customer_services' => relationships_itglue_customer_services($pdo, (int) $cust['id']),
        ]);
    }

    if ($action === 'link_set' || $action === 'link_clear') {
        // POST ?action=link_set|link_clear&customer_id=N   body: { device_id, contact_id (link_set only) }
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            relationships_respond(405, ['ok' => false, 'error' => 'Method not allowed.']);
        }
        if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'fetch') { // a cross-site form cannot set this header
            relationships_respond(403, ['ok' => false, 'error' => 'Bad request.']);
        }
        [$match, $cust] = relationships_itglue_resolve($pdo); // also enforces the territory rule
        if ($match === null) {
            relationships_respond(404, ['ok' => false, 'error' => 'This customer is not matched to an IT Glue organization.']);
        }
        $body = relationships_read_json_body();
        $deviceId = (int) ($body['device_id'] ?? 0);
        $assign = relationships_itglue_assignments((int) $match['id'], false);
        $device = null;
        foreach ($assign['devices'] as $d) { if ($d['id'] === $deviceId) { $device = $d; break; } }
        if ($device === null) {
            relationships_respond(400, ['ok' => false, 'error' => 'That device is not in this customer\'s IT Glue organization.']);
        }
        if ($action === 'link_clear') {
            $stmt = $pdo->prepare('DELETE FROM asset_links WHERE customer_id = :c AND device_id = :d');
            $stmt->execute([':c' => (int) $cust['id'], ':d' => $deviceId]);
        } else {
            $contactId = (int) ($body['contact_id'] ?? 0);
            $contact = null;
            foreach ($assign['contacts'] as $c) { if ($c['id'] === $contactId) { $contact = $c; break; } }
            if ($contact === null) {
                relationships_respond(400, ['ok' => false, 'error' => 'That person is not a contact in this customer\'s IT Glue organization.']);
            }

            // Added 2026-10-15, per Michael: figure out who (if anyone) owned this device BEFORE
            // this link, so an unassigned-PC assignment and a reassignment both get logged.
            $priorLinkStmt = $pdo->prepare('SELECT contact_name FROM asset_links WHERE customer_id = :c AND device_id = :d');
            $priorLinkStmt->execute([':c' => (int) $cust['id'], ':d' => $deviceId]);
            $priorLink = $priorLinkStmt->fetch(PDO::FETCH_ASSOC);
            $priorContactName = $priorLink !== false ? (string) $priorLink['contact_name'] : null;
            if ($priorContactName === null && (int) $device['contact_id'] > 0) {
                foreach ($assign['contacts'] as $c) { if ($c['id'] === (int) $device['contact_id']) { $priorContactName = $c['name']; break; } }
            }

            $stmt = $pdo->prepare('INSERT OR REPLACE INTO asset_links (customer_id, device_id, device_name, contact_id, contact_name, linked_by, linked_at) VALUES (:c, :d, :dn, :p, :pn, :by, datetime(\'now\'))');
            $stmt->execute([
                ':c' => (int) $cust['id'], ':d' => $deviceId, ':dn' => (string) $device['name'],
                ':p' => $contactId, ':pn' => (string) $contact['name'],
                ':by' => (string) ($user['name'] ?: $user['email']),
            ]);

            if ($priorContactName === null) {
                relationships_itglue_create_todo($pdo, $user, (int) $cust['id'],
                    'PC assignment - update friendly name in Automate. ' . $device['name'] . ' was assigned to ' . $contact['name'] . '.');
            } elseif ($priorContactName !== $contact['name']) {
                relationships_itglue_create_todo($pdo, $user, (int) $cust['id'],
                    'PC reassignment - update friendly name in Automate. ' . $device['name'] . ' was reassigned to ' . $contact['name'] . ' (previously ' . $priorContactName . ').');
            }
        }
        relationships_respond(200, ['ok' => true, 'links' => relationships_itglue_links($pdo, (int) $cust['id'])]);
    }

    if ($action === 'contact_toggle_inactive') {
        // Mirrors app.js's contacts-toggle-inactive control (Account Contacts), scoped to this
        // screen's own IT Glue contact id -- see asset_contact_status's table comment in db.php
        // for why this isn't the same `contacts` row. Added 2026-10-15 per Michael.
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            relationships_respond(405, ['ok' => false, 'error' => 'Method not allowed.']);
        }
        if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'fetch') {
            relationships_respond(403, ['ok' => false, 'error' => 'Bad request.']);
        }
        [$match, $cust] = relationships_itglue_resolve($pdo);
        if ($match === null) {
            relationships_respond(404, ['ok' => false, 'error' => 'This customer is not matched to an IT Glue organization.']);
        }
        $body = relationships_read_json_body();
        $contactId = (int) ($body['contact_id'] ?? 0);
        $assign = relationships_itglue_assignments((int) $match['id'], false);
        $contact = null;
        foreach ($assign['contacts'] as $c) { if ($c['id'] === $contactId) { $contact = $c; break; } }
        if ($contact === null) {
            relationships_respond(400, ['ok' => false, 'error' => 'That person is not a contact in this customer\'s IT Glue organization.']);
        }
        $curStmt = $pdo->prepare('SELECT inactive FROM asset_contact_status WHERE customer_id = :c AND contact_id = :p');
        $curStmt->execute([':c' => (int) $cust['id'], ':p' => $contactId]);
        $cur = $curStmt->fetch(PDO::FETCH_ASSOC);
        $next = ($cur !== false && (int) $cur['inactive'] === 1) ? 0 : 1;
        $pdo->prepare('INSERT OR REPLACE INTO asset_contact_status (customer_id, contact_id, contact_name, inactive, set_by_name, set_at) VALUES (:c, :p, :pn, :inactive, :by, datetime(\'now\'))')
            ->execute([':c' => (int) $cust['id'], ':p' => $contactId, ':pn' => (string) $contact['name'], ':inactive' => $next, ':by' => (string) ($user['name'] ?: $user['email'])]);
        relationships_respond(200, ['ok' => true, 'contact_status' => relationships_itglue_contact_status($pdo, (int) $cust['id'])]);
    }

    if ($action === 'override_set') {
        // One grid-cell edit: Microsoft License, Software, or any manual column (Firewall/VPN/
        // DUO/Guardz/custom). Always creates a to-do recording the change -- IT Glue is never
        // written to (read-only API, see file header), so the to-do is the actionable record of
        // what still needs doing elsewhere (Microsoft 365 admin center, the firewall, Automate,
        // ...). Added 2026-10-15 per Michael.
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            relationships_respond(405, ['ok' => false, 'error' => 'Method not allowed.']);
        }
        if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'fetch') {
            relationships_respond(403, ['ok' => false, 'error' => 'Bad request.']);
        }
        [$match, $cust] = relationships_itglue_resolve($pdo);
        if ($match === null) {
            relationships_respond(404, ['ok' => false, 'error' => 'This customer is not matched to an IT Glue organization.']);
        }
        $body = relationships_read_json_body();
        $contactId = (int) ($body['contact_id'] ?? 0);
        $columnKey = trim((string) ($body['column_key'] ?? ''));
        $columnLabel = trim((string) ($body['column_label'] ?? $columnKey));
        $value = trim((string) ($body['value'] ?? ''));
        $previousValue = trim((string) ($body['previous_value'] ?? ''));
        $assign = relationships_itglue_assignments((int) $match['id'], false);
        $contact = null;
        foreach ($assign['contacts'] as $c) { if ($c['id'] === $contactId) { $contact = $c; break; } }
        if ($contact === null || $columnKey === '') {
            relationships_respond(400, ['ok' => false, 'error' => 'Missing or invalid contact_id/column_key.']);
        }
        if (mb_strlen($value) > 200 || mb_strlen($previousValue) > 200) {
            relationships_respond(400, ['ok' => false, 'error' => 'That value is too long (200 characters max).']);
        }
        if ($value === '') {
            $pdo->prepare('DELETE FROM asset_overrides WHERE customer_id = :c AND contact_id = :p AND column_key = :k')
                ->execute([':c' => (int) $cust['id'], ':p' => $contactId, ':k' => $columnKey]);
        } else {
            $pdo->prepare('INSERT OR REPLACE INTO asset_overrides (customer_id, contact_id, column_key, value, set_by_name, set_at) VALUES (:c, :p, :k, :v, :by, datetime(\'now\'))')
                ->execute([':c' => (int) $cust['id'], ':p' => $contactId, ':k' => $columnKey, ':v' => $value, ':by' => (string) ($user['name'] ?: $user['email'])]);
        }
        if ($value !== $previousValue) {
            $fromText = $previousValue !== '' ? $previousValue : 'nothing';
            $toText = $value !== '' ? $value : 'nothing';
            $description = $columnKey === 'microsoft_license'
                ? 'Microsoft License Change - ' . $contact['name'] . ' was changed from ' . $fromText . ' to ' . $toText . '.'
                : $columnLabel . ' changed for ' . $contact['name'] . ' - from ' . $fromText . ' to ' . $toText . '.';
            relationships_itglue_create_todo($pdo, $user, (int) $cust['id'], $description);
        }
        relationships_respond(200, ['ok' => true, 'overrides' => relationships_itglue_overrides($pdo, (int) $cust['id'])]);
    }

    if ($action === 'columns') {
        relationships_respond(200, ['ok' => true, 'columns' => relationships_itglue_grid_columns($pdo)]);
    }

    if ($action === 'column_add') {
        // The manual column set is shared/global (same grid for every customer) -- see
        // asset_grid_columns' table comment in db.php. Added 2026-10-15 per Michael's
        // "configurable like a spreadsheet" ask.
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            relationships_respond(405, ['ok' => false, 'error' => 'Method not allowed.']);
        }
        if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'fetch') {
            relationships_respond(403, ['ok' => false, 'error' => 'Bad request.']);
        }
        $body = relationships_read_json_body();
        $label = trim((string) ($body['label'] ?? ''));
        $options = trim((string) ($body['options'] ?? ''));
        if ($label === '' || mb_strlen($label) > 60) {
            relationships_respond(400, ['ok' => false, 'error' => 'Column name is required (60 characters max).']);
        }
        $key = (string) preg_replace('/[^a-z0-9]+/', '_', mb_strtolower($label));
        $key = trim($key, '_');
        if ($key === '') {
            relationships_respond(400, ['ok' => false, 'error' => 'That column name has no usable characters.']);
        }
        $pos = (int) $pdo->query('SELECT COALESCE(MAX(position), 9) + 1 FROM asset_grid_columns')->fetchColumn();
        try {
            $pdo->prepare('INSERT INTO asset_grid_columns (column_key, label, options, position, created_by_name) VALUES (:k, :l, :o, :p, :by)')
                ->execute([':k' => $key, ':l' => $label, ':o' => ($options !== '' ? $options : null), ':p' => $pos, ':by' => (string) ($user['name'] ?: $user['email'])]);
        } catch (PDOException $e) {
            relationships_respond(409, ['ok' => false, 'error' => 'A column with that name already exists.']);
        }
        relationships_respond(200, ['ok' => true, 'columns' => relationships_itglue_grid_columns($pdo)]);
    }

    if ($action === 'column_remove') {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            relationships_respond(405, ['ok' => false, 'error' => 'Method not allowed.']);
        }
        if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'fetch') {
            relationships_respond(403, ['ok' => false, 'error' => 'Bad request.']);
        }
        $body = relationships_read_json_body();
        $key = trim((string) ($body['column_key'] ?? ''));
        if ($key === '') {
            relationships_respond(400, ['ok' => false, 'error' => 'Missing column_key.']);
        }
        $pdo->prepare('DELETE FROM asset_grid_columns WHERE column_key = :k')->execute([':k' => $key]);
        $pdo->prepare('DELETE FROM asset_overrides WHERE column_key = :k')->execute([':k' => $key]);
        relationships_respond(200, ['ok' => true, 'columns' => relationships_itglue_grid_columns($pdo)]);
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
