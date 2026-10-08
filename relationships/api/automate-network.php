<?php
/**
 * relationships/api/automate-network.php
 *
 * Library for the "Show Customer Network" page (loaded by automate.php; not a web endpoint).
 * Builds, for one Automate client: network devices, servers and computers with specs, plus the
 * replacement / attention flags, and records one capacity snapshot per device per day
 * (table automate_snapshots) so RAM / CPU / storage trends over 90 days become possible.
 */

declare(strict_types=1);

if (!function_exists('relationships_automate_get')) {
    http_response_code(404);
    exit;
}

/** Several GETs at once (curl_multi). Returns path => decoded array, or null where a call failed. */
function relationships_automate_get_multi(array $paths): array
{
    $cfg = relationships_automate_config();
    $token = relationships_automate_token();
    $out = [];
    foreach (array_chunk($paths, 12) as $chunk) {
        $mh = curl_multi_init();
        $handles = [];
        foreach ($chunk as $p) {
            $ch = curl_init($cfg['base_url'] . '/cwa/api/v1/' . ltrim($p, '/'));
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_HTTPHEADER => ['Accept: application/json', 'ClientId: ' . $cfg['client_id'], 'Authorization: Bearer ' . $token],
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[$p] = $ch;
        }
        do {
            $status = curl_multi_exec($mh, $running);
            if ($running) {
                curl_multi_select($mh, 1.0);
            }
        } while ($running && $status === CURLM_OK);
        foreach ($handles as $p => $ch) {
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $d = json_decode((string) curl_multi_getcontent($ch), true);
            $out[$p] = ($code >= 200 && $code < 300 && is_array($d)) ? $d : null;
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
    }
    return $out;
}

function relationships_automate_snapshot_table(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS automate_snapshots (
        day TEXT NOT NULL, client_id INTEGER NOT NULL, kind TEXT NOT NULL, device_id INTEGER NOT NULL,
        online INTEGER NOT NULL DEFAULT 0, ram_pct REAL, cpu_pct REAL, disk_pct REAL,
        PRIMARY KEY (day, kind, device_id))');
}

/** Drive list -> total MB and used %. Ignores missing drives and recovery / reserved partitions. */
function relationships_automate_storage(?array $drives): array
{
    $size = 0.0;
    $free = 0.0;
    foreach ((array) $drives as $d) {
        $d = (array) $d;
        if (!empty($d['IsMissing']) || (float) ($d['Size'] ?? 0) <= 0) {
            continue;
        }
        if (preg_match('/recover|restore|system reserved|\bEFI\b/i', (string) ($d['VolumeName'] ?? ''))) {
            continue;
        }
        $size += (float) $d['Size'];
        $free += (float) ($d['FreeSpace'] ?? 0);
    }
    return $size > 0
        ? ['total_mb' => $size, 'used_pct' => round(($size - $free) / $size * 100, 1)]
        : ['total_mb' => 0.0, 'used_pct' => null];
}

function relationships_automate_make(string $m): string
{
    $m = trim((string) preg_replace('/\b(inc\.?|corporation|corp\.?|co\.?|ltd\.?|international)\b/i', '', $m), " ,.");
    if (preg_match('/hewlett|^hp$/i', $m)) { return 'HP'; }
    if (preg_match('/^dell/i', $m)) { return 'Dell'; }
    if (preg_match('/lenovo/i', $m)) { return 'Lenovo'; }
    if (preg_match('/microsoft/i', $m)) { return 'Microsoft'; }
    if (preg_match('/asus/i', $m)) { return 'ASUS'; }
    if (preg_match('/acer/i', $m)) { return 'Acer'; }
    return $m;
}

/** Operating systems that are out of support or about to be (today: October 2026). */
function relationships_automate_os_old(string $os): bool
{
    if (preg_match('/windows\s*(xp|vista|7|8|8\.1|10)(\D|$)/i', $os)) { return true; }
    return (bool) preg_match('/server\s*(2003|2008|2012|2016)/i', $os);
}

function relationships_automate_date_ok(string $s): bool
{
    return $s !== '' && (int) substr($s, 0, 4) > 1990;
}

/**
 * Everything the Customer Network page draws for one Automate client.
 * Per-computer drives / processors / bios are cached 3 hours in data/automate-detail-<client>.json.
 */
function relationships_automate_network_payload(PDO $pdo, int $cid, bool $record = true): array
{
    $rows = relationships_automate_get('computers', ['condition' => 'Client.Id=' . $cid, 'pageSize' => 1000]);

    $cache = __DIR__ . '/../data/automate-detail-' . $cid . '.json';
    $detail = [];
    if (is_file($cache) && (time() - (int) @filemtime($cache)) < 10800) {
        $detail = json_decode((string) @file_get_contents($cache), true) ?: [];
    }
    $need = [];
    foreach ($rows as $r) {
        $id = (int) ((array) $r)['Id'];
        if (!isset($detail[$id])) {
            $need[] = $id;
        }
    }
    if ($need) {
        $paths = [];
        foreach ($need as $id) {
            foreach (['drives', 'processors', 'bios'] as $k) {
                $paths[] = 'computers/' . $id . '/' . $k;
            }
        }
        $got = relationships_automate_get_multi($paths);
        foreach ($need as $id) {
            $bios = $got['computers/' . $id . '/bios'] ?? null;
            $keep = [];
            if (is_array($bios)) {
                foreach ($bios as $k => $v) {
                    if (is_scalar($v) && preg_match('/model|product|system|family|sku|name/i', (string) $k)) {
                        $keep[$k] = $v;
                    }
                }
            }
            $detail[$id] = ['drives' => $got['computers/' . $id . '/drives'] ?? [], 'processors' => $got['computers/' . $id . '/processors'] ?? [], 'bios' => $keep];
        }
        @file_put_contents($cache, json_encode($detail), LOCK_EX);
        @chmod($cache, 0600);
    }

    $now = time();
    $computers = [];
    $servers = [];
    foreach ($rows as $r) {
        $r = (array) $r;
        $id = (int) $r['Id'];
        $d = $detail[$id] ?? ['drives' => [], 'processors' => [], 'bios' => []];
        $base = relationships_automate_computer_row($r);
        $online = strcasecmp($base['status'], 'Online') === 0;
        $contact = trim((string) relationships_automate_pick($r, ['PrimaryContactName'], ''));
        if ($contact === '' && isset($r['Contact']) && is_array($r['Contact'])) {
            $contact = trim(($r['Contact']['FirstName'] ?? '') . ' ' . ($r['Contact']['LastName'] ?? ''));
        }
        $lastUser = (string) ($r['LastUserName'] ?? '');
        $lastUserShort = preg_match('/\\\\([^\\\\]+)$/', $lastUser, $mm) ? $mm[1] : $lastUser;
        $total = (float) ($r['TotalMemory'] ?? 0);
        $free = (float) ($r['FreeMemory'] ?? 0);
        $ramPct = $total > 0 ? round(($total - $free) / $total * 100, 1) : null;
        $st = relationships_automate_storage($d['drives']);
        $proc = (array) ($d['processors'][0] ?? []);
        $cpuName = trim((string) ($proc['Family']['Name'] ?? ''));
        $cpuName = trim((string) preg_replace('/\s*processor\s*$/i', '', str_replace(['(R)', '(TM)', '(tm)'], '', $cpuName)));
        if ($cpuName !== '' && !empty($proc['Cores'])) {
            $cpuName .= ' · ' . (int) $proc['Cores'] . ' cores';
        }
        $vs = $r['VirusScanner'] ?? '';
        $av = is_array($vs) ? (string) ($vs['Name'] ?? '') : (string) $vs;
        $added = (string) relationships_automate_pick($r, ['AssetDate', 'DateAdded'], '');
        $ageYears = relationships_automate_date_ok($added) ? round(($now - (int) strtotime($added)) / 31557600, 1) : null;
        $warr = relationships_automate_date_ok($base['warranty_end']) ? substr($base['warranty_end'], 0, 10) : '';
        $lastTs = $base['last_contact'] !== '' ? (int) strtotime($base['last_contact']) : 0;
        $offDays = (!$online && $lastTs) ? (int) floor(($now - $lastTs) / 86400) : 0;
        $model = '';
        foreach ($d['bios'] as $k => $v) {
            if (preg_match('/model|productname|^product$|systemfamily/i', (string) $k) && !preg_match('/vendor|manufacturer|bios/i', (string) $k) && trim((string) $v) !== '') {
                $model = trim((string) $v);
                break;
            }
        }
        $isServer = (bool) preg_match('/server|controller/i', $base['type'] . ' ' . $base['os_full']);
        $dev = [
            'id' => $id, 'name' => $base['name'], 'friendly' => $contact, 'last_user' => $lastUserShort,
            'os' => $base['os'], 'os_full' => $base['os_full'], 'av' => $av, 'warranty_end' => $warr,
            'storage_mb' => $st['total_mb'], 'storage_pct' => $st['used_pct'],
            'ram_bytes' => $total, 'ram_pct' => $ramPct, 'cpu_pct' => isset($r['CpuUsage']) ? (float) $r['CpuUsage'] : null,
            'cpu_model' => $cpuName, 'make' => relationships_automate_make((string) ($r['BiosManufacturer'] ?? '')), 'model' => $model,
            'serial' => $base['serial'], 'online' => $online, 'last_contact' => $base['last_contact'], 'offline_days' => $offDays,
            'ip' => $base['ip'], 'age_years' => $ageYears, 'added' => relationships_automate_date_ok($added) ? substr($added, 0, 10) : '',
            'virtual' => !empty($r['IsVirtualMachine']), 'kind' => $isServer ? 'server' : 'pc',
        ];
        if ($isServer) { $servers[] = $dev; } else { $computers[] = $dev; }
    }

    // Network devices belong to Automate "locations", so find this client's locations first.
    $net = [];
    try {
        $locs = relationships_automate_get('locations', ['condition' => 'Client.Id=' . $cid, 'pageSize' => 1000]);
        $locIds = [];
        foreach ($locs as $l) {
            $l = (array) $l;
            $lid = (int) ($l['Id'] ?? $l['LocationId'] ?? 0);
            if ($lid > 0) { $locIds[$lid] = (string) ($l['Name'] ?? ''); }
        }
        if ($locIds) {
            $cond = implode(' or ', array_map(static fn ($i): string => 'Location.Id=' . $i, array_keys($locIds)));
            foreach (relationships_automate_get('networkdevices', ['condition' => $cond, 'pageSize' => 1000]) as $n) {
                $n = (array) $n;
                $lid = (int) ($n['Location']['Id'] ?? 0);
                if (!isset($locIds[$lid])) { continue; } // safety net if Automate ignored the condition
                $st = strtolower((string) ($n['Status'] ?? ''));
                $net[] = [
                    'id' => (int) ($n['Id'] ?? 0),
                    'name' => (string) ($n['FriendlyName'] ?? '') !== '' ? (string) $n['FriendlyName'] : (string) ($n['Name'] ?? ''),
                    'raw_name' => (string) ($n['Name'] ?? ''), 'type' => (string) ($n['DeviceType']['Name'] ?? ''),
                    'template' => (string) ($n['DetectionTemplateName'] ?? ''), 'ip' => (string) ($n['LocalIPAddress'] ?? ''),
                    'mac' => (string) ($n['MACAddress'] ?? ''), 'location' => $locIds[$lid],
                    'online' => $st === 'on' || $st === 'online', 'kind' => 'net', 'offline_days' => 0,
                ];
            }
        }
    } catch (RelationshipsAutomateError $e) {
        error_log('[relationships/automate] network devices: ' . $e->getMessage());
    }

    // Daily snapshots (first reading each day) feed the 90-day capacity trends.
    $hist = [];
    try {
        relationships_automate_snapshot_table($pdo);
        if ($record) {
            $ins = $pdo->prepare('INSERT OR IGNORE INTO automate_snapshots (day, client_id, kind, device_id, online, ram_pct, cpu_pct, disk_pct) VALUES (:d,:c,:k,:i,:o,:r,:u,:s)');
            $pdo->beginTransaction();
            foreach (array_merge($computers, $servers, $net) as $x) {
                $ins->execute([':d' => date('Y-m-d'), ':c' => $cid, ':k' => $x['kind'] === 'net' ? 'net' : 'pc', ':i' => $x['id'], ':o' => $x['online'] ? 1 : 0, ':r' => $x['ram_pct'] ?? null, ':u' => $x['cpu_pct'] ?? null, ':s' => $x['storage_pct'] ?? null]);
            }
            $pdo->commit();
        }
        $q = $pdo->prepare("SELECT kind, device_id, COUNT(*) n, MIN(day) first_day,
            SUM(CASE WHEN ram_pct > 90 THEN 1 ELSE 0 END) ram_hi, SUM(CASE WHEN cpu_pct > 90 THEN 1 ELSE 0 END) cpu_hi,
            SUM(CASE WHEN disk_pct > 90 THEN 1 ELSE 0 END) disk_hi, MAX(CASE WHEN online = 1 THEN day END) last_online
            FROM automate_snapshots WHERE client_id = :c AND day >= date('now','-90 day') GROUP BY kind, device_id");
        $q->execute([':c' => $cid]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $h) {
            $hist[$h['kind'] . ':' . $h['device_id']] = $h;
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('[relationships/automate] snapshots: ' . $e->getMessage());
    }

    $flagDefs = [
        'offline30' => 'Offline 30+ days', 'os_old' => 'Older operating system', 'age3' => 'Older than 3 years',
        'warranty' => 'Warranty expired', 'ram_hi' => 'RAM over 90% (90 days)', 'cpu_hi' => 'CPU over 90% (90 days)',
        'disk_hi' => 'Storage over 90%', 'server_disk' => 'Server storage over 80%',
    ];
    $firstDay = null;
    $maxDays = 0;
    $apply = static function (array &$x) use ($hist, $now, &$firstDay, &$maxDays): void {
        $flags = [];
        $h = $hist[($x['kind'] === 'net' ? 'net' : 'pc') . ':' . $x['id']] ?? null;
        if ($h) {
            $x['history'] = ['days' => (int) $h['n'], 'ram_hi' => (int) $h['ram_hi'], 'cpu_hi' => (int) $h['cpu_hi'], 'disk_hi' => (int) $h['disk_hi']];
            $firstDay = ($firstDay === null || $h['first_day'] < $firstDay) ? $h['first_day'] : $firstDay;
            $maxDays = max($maxDays, (int) $h['n']);
        }
        if ($x['kind'] === 'net') {
            if (!$x['online']) {
                $lo = $h['last_online'] ?? null;
                $x['offline_days'] = $lo ? (int) floor(($now - (int) strtotime((string) $lo)) / 86400) : 0;
                if ($x['offline_days'] >= 30) { $flags[] = 'offline30'; }
            }
            $x['flags'] = $flags;
            return;
        }
        if (!$x['online'] && $x['offline_days'] >= 30) { $flags[] = 'offline30'; }
        if (relationships_automate_os_old($x['os_full'])) { $flags[] = 'os_old'; }
        if ($x['age_years'] !== null && $x['age_years'] > 3) { $flags[] = 'age3'; }
        if ($x['warranty_end'] !== '' && $x['warranty_end'] < date('Y-m-d')) { $flags[] = 'warranty'; }
        if ($h && (int) $h['n'] >= 7) { // a week of readings before calling it a pattern
            if ($h['ram_hi'] / $h['n'] >= 0.5) { $flags[] = 'ram_hi'; }
            if ($h['cpu_hi'] / $h['n'] >= 0.5) { $flags[] = 'cpu_hi'; }
        }
        if ($x['kind'] === 'server') {
            if ($x['storage_pct'] !== null && $x['storage_pct'] > 80) { $flags[] = 'server_disk'; }
        } elseif ($x['storage_pct'] !== null && $x['storage_pct'] > 90) {
            $flags[] = 'disk_hi';
        }
        $x['flags'] = $flags;
    };
    foreach ($computers as &$x) { $apply($x); }
    unset($x);
    foreach ($servers as &$x) { $apply($x); }
    unset($x);
    foreach ($net as &$x) { $apply($x); }
    unset($x);
    $counts = array_fill_keys(array_keys($flagDefs), 0);
    foreach (array_merge($computers, $servers, $net) as $x) {
        foreach ($x['flags'] as $f) { $counts[$f]++; }
    }
    $byName = static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']);
    usort($computers, $byName);
    usort($servers, $byName);
    usort($net, $byName);

    return [
        'network' => $net, 'servers' => $servers, 'computers' => $computers,
        'flag_labels' => $flagDefs, 'flag_counts' => $counts,
        'history' => ['first_day' => $firstDay, 'max_days' => $maxDays],
        'generated_at' => date('c'),
    ];
}
