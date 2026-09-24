<?php
/**
 * relationships/api/prospecting-core.php
 *
 * Shared helpers for the Prospecting feature (prospecting.php): cleaning
 * whatever the research agent returned, geocoding + distance to Richmond,
 * duplicate detection against CodeBlue's own customers and ConnectWise, the
 * deterministic confidence score, and the candidate -> JSON row shape.
 *
 * Everything the model returns is treated as untrusted: strings are
 * trimmed and length-capped, URLs must be http(s), emails must validate,
 * numbers are clamped -- see relationships_prospect_clean_candidate().
 */

declare(strict_types=1);

require_once __DIR__ . '/prospecting-agent.php';

/** Richmond, VA -- the centre of CodeBlue's 150-mile target territory. */
const RELATIONSHIPS_PROSPECT_HQ_LAT = 37.5407;
const RELATIONSHIPS_PROSPECT_HQ_LNG = -77.4360;
const RELATIONSHIPS_PROSPECT_MAX_MILES = 150;
const RELATIONSHIPS_PROSPECT_EMP_MIN = 2;
const RELATIONSHIPS_PROSPECT_EMP_MAX = 350;

function relationships_prospect_str(mixed $v, int $max = 200): ?string
{
    if (!is_string($v) && !is_numeric($v)) {
        return null;
    }
    $s = trim(preg_replace('/\s+/', ' ', (string) $v) ?? '');
    if ($s === '' || strcasecmp($s, 'null') === 0) {
        return null;
    }
    return mb_substr($s, 0, $max);
}

function relationships_prospect_url(mixed $v): ?string
{
    $s = relationships_prospect_str($v, 500);
    if ($s === null) {
        return null;
    }
    if (!preg_match('#^https?://#i', $s) && preg_match('#^[a-z0-9.-]+\.[a-z]{2,}(/.*)?$#i', $s)) {
        $s = 'https://' . $s;
    }
    if (filter_var($s, FILTER_VALIDATE_URL) === false || !preg_match('#^https?://#i', $s)) {
        return null;
    }
    return $s;
}

function relationships_prospect_email(mixed $v): ?string
{
    $s = relationships_prospect_str($v, 200);
    if ($s === null) {
        return null;
    }
    $s = preg_replace('/^mailto:/i', '', $s) ?? $s;
    return filter_var($s, FILTER_VALIDATE_EMAIL) !== false ? strtolower($s) : null;
}

function relationships_prospect_int(mixed $v, int $min = 0, int $max = 1000000): ?int
{
    if (!is_numeric($v)) {
        return null;
    }
    $n = (int) round((float) $v);
    return ($n < $min || $n > $max) ? null : $n;
}

/**
 * Normalizes one raw candidate from the model into the columns of
 * prospect_candidates (minus computed ones). Returns null if it has no
 * usable business name.
 */
function relationships_prospect_clean_candidate(mixed $raw): ?array
{
    if (!is_array($raw)) {
        return null;
    }
    $name = relationships_prospect_str($raw['name'] ?? null, 200);
    if ($name === null) {
        return null;
    }
    $contact = is_array($raw['contact'] ?? null) ? $raw['contact'] : [];

    $industry = relationships_prospect_str($raw['industry'] ?? null, 80);
    $allowed = array_merge(RELATIONSHIPS_PROSPECT_INDUSTRIES, [RELATIONSHIPS_PROSPECT_OTHER_INDUSTRY]);
    if ($industry !== null && !in_array($industry, $allowed, true)) {
        $industry = RELATIONSHIPS_PROSPECT_OTHER_INDUSTRY;
    }

    $sources = [];
    foreach ((array) ($raw['source_urls'] ?? []) as $u) {
        $u = relationships_prospect_url($u);
        if ($u !== null) {
            $sources[$u] = true;
        }
    }

    $low = relationships_prospect_int($raw['employee_low'] ?? null);
    $high = relationships_prospect_int($raw['employee_high'] ?? null);
    if ($low !== null && $high !== null && $low > $high) {
        [$low, $high] = [$high, $low];
    }

    return [
        'name' => $name,
        'website' => relationships_prospect_url($raw['website'] ?? null),
        'address_line1' => relationships_prospect_str($raw['address_line1'] ?? null, 160),
        'city' => relationships_prospect_str($raw['city'] ?? null, 80),
        'state' => relationships_prospect_str($raw['state'] ?? null, 30),
        'zip' => relationships_prospect_str($raw['zip'] ?? null, 15),
        'phone' => relationships_prospect_str($raw['phone'] ?? null, 40),
        'industry' => $industry,
        'employee_low' => $low,
        'employee_high' => $high,
        'employee_evidence' => relationships_prospect_str($raw['employee_evidence'] ?? null, 300),
        'employee_source_url' => relationships_prospect_url($raw['employee_source_url'] ?? null),
        'summary' => relationships_prospect_str($raw['summary'] ?? null, 600),
        'contact_first_name' => relationships_prospect_str($contact['first_name'] ?? null, 60),
        'contact_last_name' => relationships_prospect_str($contact['last_name'] ?? null, 60),
        'contact_title' => relationships_prospect_str($contact['title'] ?? null, 100),
        'contact_email' => relationships_prospect_email($contact['email'] ?? null),
        'contact_phone' => relationships_prospect_str($contact['phone'] ?? null, 40),
        'contact_profile_url' => relationships_prospect_url($contact['profile_url'] ?? null),
        'contact_name_source_url' => relationships_prospect_url($contact['name_source_url'] ?? null),
        'contact_email_source_url' => relationships_prospect_url($contact['email_source_url'] ?? null),
        'contact_phone_source_url' => relationships_prospect_url($contact['phone_source_url'] ?? null),
        'source_urls' => array_slice(array_keys($sources), 0, 8),
    ];
}

// ---------------------------------------------------------------------
// Geocoding + distance
// ---------------------------------------------------------------------

function relationships_prospect_http_get_json(string $url, int $timeout = 12, array $headers = []): ?array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $headers),
    ]);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false || $status < 200 || $status >= 300) {
        return null;
    }
    $decoded = json_decode((string) $raw, true);
    return is_array($decoded) ? $decoded : null;
}

/**
 * Free geocoding: the US Census one-line geocoder first (no key), then
 * OpenStreetMap Nominatim as a fallback (needs a User-Agent, max ~1
 * request/second). Returns [lat, lng] or null.
 */
function relationships_prospect_geocode(string $address): ?array
{
    $address = trim($address);
    if ($address === '') {
        return null;
    }
    $census = relationships_prospect_http_get_json(
        'https://geocoding.geo.census.gov/geocoder/locations/onelineaddress?benchmark=Public_AR_Current&format=json&address=' . rawurlencode($address)
    );
    $match = $census['result']['addressMatches'][0]['coordinates'] ?? null;
    if (is_array($match) && isset($match['x'], $match['y'])) {
        return [(float) $match['y'], (float) $match['x']];
    }

    usleep(1100000); // Nominatim usage policy: at most one request per second
    $osm = relationships_prospect_http_get_json(
        'https://nominatim.openstreetmap.org/search?format=json&limit=1&countrycodes=us&q=' . rawurlencode($address),
        12,
        ['User-Agent: CodeBlue-Relationships/1.0 (mbergamo@codebluetechnology.com)']
    );
    if (is_array($osm) && isset($osm[0]['lat'], $osm[0]['lon'])) {
        return [(float) $osm[0]['lat'], (float) $osm[0]['lon']];
    }
    return null;
}

function relationships_prospect_haversine_miles(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $r = 3958.8;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

/** Geocodes a cleaned candidate and returns [lat, lng, miles-from-Richmond] (all null if it can't). */
function relationships_prospect_locate(array $c): array
{
    $tail = trim(implode(' ', array_filter([(string) ($c['state'] ?? ''), (string) ($c['zip'] ?? '')])));
    $cityLine = trim(implode(', ', array_filter([(string) ($c['city'] ?? ''), $tail])));
    $full = trim(implode(', ', array_filter([(string) ($c['address_line1'] ?? ''), $cityLine])));

    $coords = relationships_prospect_geocode($full);
    if ($coords === null && $cityLine !== '' && $full !== $cityLine) {
        $coords = relationships_prospect_geocode($cityLine);
    }
    if ($coords === null) {
        return [null, null, null];
    }
    $miles = relationships_prospect_haversine_miles(RELATIONSHIPS_PROSPECT_HQ_LAT, RELATIONSHIPS_PROSPECT_HQ_LNG, $coords[0], $coords[1]);
    return [$coords[0], $coords[1], round($miles, 1)];
}

// ---------------------------------------------------------------------
// Duplicate detection
// ---------------------------------------------------------------------

/** Lowercase, strip punctuation and common legal suffixes, so "Acme Dental, LLC" == "ACME DENTAL". */
function relationships_prospect_normalize_name(string $name): string
{
    $n = mb_strtolower($name);
    $n = str_replace('&', ' and ', $n);
    $n = preg_replace('/[^a-z0-9 ]+/', ' ', $n) ?? $n;
    $n = preg_replace('/\b(the|inc|incorporated|llc|l l c|llp|pllc|pc|p c|pa|co|corp|corporation|company|ltd|limited)\b/', ' ', $n) ?? $n;
    return trim(preg_replace('/\s+/', ' ', $n) ?? $n);
}

function relationships_prospect_names_match(string $a, string $b): bool
{
    if ($a === '' || $b === '') {
        return false;
    }
    if ($a === $b) {
        return true;
    }
    if (min(strlen($a), strlen($b)) >= 8 && (str_contains($a, $b) || str_contains($b, $a))) {
        return true;
    }
    similar_text($a, $b, $pct);
    return $pct >= 90.0;
}

/** [normalizedName => display name] for every local customer/prospect. */
function relationships_prospect_local_names(PDO $pdo): array
{
    $out = [];
    foreach ($pdo->query('SELECT name FROM customers')->fetchAll(PDO::FETCH_COLUMN) as $name) {
        $norm = relationships_prospect_normalize_name((string) $name);
        if ($norm !== '') {
            $out[$norm] = (string) $name;
        }
    }
    return $out;
}

/** Local duplicate: returns the existing customer's name, or null. */
function relationships_prospect_local_dup(array $localNames, string $candidateName): ?string
{
    $norm = relationships_prospect_normalize_name($candidateName);
    foreach ($localNames as $existingNorm => $display) {
        if (relationships_prospect_names_match($norm, (string) $existingNorm)) {
            return $display;
        }
    }
    return null;
}

/**
 * ConnectWise duplicate: a live company-name search. Fail-open -- if
 * ConnectWise is unreachable/unconfigured this returns null (the claim step
 * re-checks before anything is written). Returns "Name (#id)" or null.
 */
function relationships_prospect_cw_dup(string $candidateName): ?string
{
    require_once __DIR__ . '/connectwise.php';
    $core = relationships_prospect_normalize_name($candidateName);
    if (strlen($core) < 4) {
        return null;
    }
    try {
        $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $core);
        $rows = relationships_cw_request('/company/companies', [
            'conditions' => 'name like "%' . $escaped . '%"',
            'fields' => 'id,name',
            'pageSize' => '5',
        ]);
        foreach ($rows as $row) {
            if (is_array($row) && isset($row['name']) && relationships_prospect_names_match($core, relationships_prospect_normalize_name((string) $row['name']))) {
                return $row['name'] . ' (#' . ($row['id'] ?? '?') . ')';
            }
        }
    } catch (Throwable $e) {
        error_log('[relationships/prospecting] ConnectWise duplicate check failed for "' . $candidateName . '": ' . $e->getMessage());
    }
    return null;
}

// ---------------------------------------------------------------------
// Confidence score -- deterministic, computed here, never model-rated.
// ---------------------------------------------------------------------

/**
 * In range of Richmond 25 + employee estimate inside 2-350 (20) + industry
 * in the target list (15; 8 for "other") + named primary contact (15) +
 * public email (15; 7 if no source URL) + a phone (10). Returns
 * [score 0-100, tier, missing[]] -- tiers High >= 80, Medium 50-79, Low < 50.
 * A candidate with a named contact + email + phone + in-range location is
 * the "complete" case that ranks at the top.
 */
function relationships_prospect_score(array $c, ?float $distanceMiles): array
{
    $score = 0;
    $missing = [];

    if ($distanceMiles === null) {
        $missing[] = 'location unverified';
    } elseif ($distanceMiles <= RELATIONSHIPS_PROSPECT_MAX_MILES) {
        $score += 25;
    } else {
        $missing[] = 'outside 150 miles';
    }

    $low = $c['employee_low'] ?? null;
    $high = $c['employee_high'] ?? null;
    if ($low === null && $high === null) {
        $missing[] = 'employee count';
    } else {
        $lo = $low ?? $high;
        $hi = $high ?? $low;
        if ($hi >= RELATIONSHIPS_PROSPECT_EMP_MIN && $lo <= RELATIONSHIPS_PROSPECT_EMP_MAX) {
            $score += 20;
        } else {
            $missing[] = 'size outside 2-350';
        }
    }

    $industry = (string) ($c['industry'] ?? '');
    if ($industry === '') {
        $missing[] = 'industry';
    } elseif ($industry === RELATIONSHIPS_PROSPECT_OTHER_INDUSTRY) {
        $score += 8;
    } else {
        $score += 15;
    }

    if (($c['contact_first_name'] ?? '') !== '' && ($c['contact_last_name'] ?? '') !== '') {
        $score += 15;
    } else {
        $missing[] = 'contact name';
    }

    if (($c['contact_email'] ?? '') !== '') {
        $score += ($c['contact_email_source_url'] ?? '') !== '' ? 15 : 7;
    } else {
        $missing[] = 'email';
    }

    if (($c['contact_phone'] ?? '') !== '' || ($c['phone'] ?? '') !== '') {
        $score += 10;
    } else {
        $missing[] = 'phone';
    }

    $tier = $score >= 80 ? 'High' : ($score >= 50 ? 'Medium' : 'Low');
    return [$score, $tier, $missing];
}

// ---------------------------------------------------------------------
// Row shape sent to the browser
// ---------------------------------------------------------------------

function relationships_prospect_candidate_out(array $r): array
{
    $sources = json_decode((string) ($r['source_urls_json'] ?? '[]'), true);
    $missing = json_decode((string) ($r['missing_json'] ?? '[]'), true);
    $profile = $r['profile_json'] !== null && $r['profile_json'] !== '' ? json_decode((string) $r['profile_json'], true) : null;

    return [
        'id' => (int) $r['id'],
        'search_id' => (int) $r['search_id'],
        'name' => $r['name'],
        'website' => $r['website'],
        'address_line1' => $r['address_line1'],
        'city' => $r['city'],
        'state' => $r['state'],
        'zip' => $r['zip'],
        'phone' => $r['phone'],
        'industry' => $r['industry'],
        'employee_low' => $r['employee_low'] !== null ? (int) $r['employee_low'] : null,
        'employee_high' => $r['employee_high'] !== null ? (int) $r['employee_high'] : null,
        'employee_evidence' => $r['employee_evidence'],
        'employee_source_url' => $r['employee_source_url'],
        'summary' => $r['summary'],
        'contact' => [
            'first_name' => $r['contact_first_name'],
            'last_name' => $r['contact_last_name'],
            'title' => $r['contact_title'],
            'email' => $r['contact_email'],
            'phone' => $r['contact_phone'],
            'profile_url' => $r['contact_profile_url'],
            'name_source_url' => $r['contact_name_source_url'],
            'email_source_url' => $r['contact_email_source_url'],
            'phone_source_url' => $r['contact_phone_source_url'],
        ],
        'distance_mi' => $r['distance_mi'] !== null ? (float) $r['distance_mi'] : null,
        'confidence' => (int) $r['confidence'],
        'tier' => $r['confidence_tier'],
        'missing' => is_array($missing) ? $missing : [],
        'source_urls' => is_array($sources) ? $sources : [],
        'profile' => is_array($profile) ? $profile : null,
        'dup_of' => $r['dup_of'],
        'claimed_customer_id' => $r['claimed_customer_id'] !== null ? (int) $r['claimed_customer_id'] : null,
    ];
}

/** Start of "today" in America/New_York as a UTC 'Y-m-d H:i:s' string, for the daily cap. */
function relationships_prospect_today_start_utc(): string
{
    $eastern = new DateTimeZone('America/New_York');
    $start = (new DateTimeImmutable('now', $eastern))->setTime(0, 0, 0);
    return $start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

/** Whole days until a claim's deadline (negative = overdue). */
function relationships_prospect_days_left(string $deadlineUtc): int
{
    $diff = strtotime($deadlineUtc . ' UTC') - time();
    return $diff >= 0 ? (int) ceil($diff / 86400) : -((int) ceil(-$diff / 86400));
}

/**
 * The ACTIVE 90-day prospect claim for a customer (or null): who claimed it,
 * when, the deadline and days left. A claim stops being 'active' when
 * ConnectWise Sync sees the company's Status is no longer "Prospect"
 * (the Sales Manager promoted it) -- see the prospect sync's start().
 */
function relationships_prospect_claim_for_customer(PDO $pdo, int $customerId): ?array
{
    $stmt = $pdo->prepare("SELECT claimed_by_name, claimed_at, deadline_at FROM prospect_claims WHERE customer_id = :id AND status = 'active'");
    $stmt->execute([':id' => $customerId]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($r === false) {
        return null;
    }
    return [
        'claimed_by_name' => $r['claimed_by_name'],
        'claimed_at' => $r['claimed_at'],
        'deadline_at' => $r['deadline_at'],
        'days_left' => relationships_prospect_days_left((string) $r['deadline_at']),
    ];
}
