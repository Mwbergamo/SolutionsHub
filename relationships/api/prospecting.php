<?php
/**
 * relationships/api/prospecting.php
 *
 * Endpoints for the Prospecting view (added 2026-09-23, per Michael). A rep
 * issues a "Prospect" command; a research agent (prospecting-agent.php)
 * searches the public web for target-market businesses; this file dedupes,
 * geocodes, scores, stores and returns them; the rep opens one for a
 * profile and then claims it as a ConnectWise Prospect. See the two files
 * this one is built on for the details that matter:
 *   - prospecting-agent.php: what the agent can/can't see, prompts, and
 *     the prompt-injection posture.
 *   - prospecting-core.php: cleaning, geocoding, duplicate checks, the
 *     deterministic confidence score.
 *
 * GET  ?action=latest
 *   -> { ok, configured, industries[], remaining_today, daily_cap,
 *        search: {id, industry, location_text, created_at, status} | null,
 *        candidates: [...], skipped: [{name, reason}] }
 *   The rep's most recent finished search (so a refresh or a closed tab
 *   doesn't lose results), plus what the search form needs.
 *
 * POST ?action=search { industry, location?, radius_miles? }
 *   -> { ok, status: 'running', search_id } IMMEDIATELY; the research keeps
 *   running server-side (a run can take several minutes -- far too long to
 *   hold a browser request open; the first synchronous version timed out).
 *   Counts against the rep's daily cap.
 *
 * GET  ?action=search_status&search_id=N
 *   -> { ok, status: 'running' | 'failed' | 'done', error?, + the same
 *   fields as 'latest' once done }. The UI polls this every few seconds;
 *   'latest' also reports a still-running search so a page reload resumes.
 *
 * POST ?action=profile { candidate_id }
 *   -> { ok, candidate } -- runs the profile agent for one candidate (about
 *   a minute): business summary, primary-contact background, CodeBlue
 *   service recommendations (validated against the real catalog), and any
 *   contact details it newly found (never overwrites what's already there).
 *
 * POST ?action=update_candidate { candidate_id, website?, phone?, address_line1?,
 *      city?, state?, zip?, contact_first_name?, contact_last_name?,
 *      contact_title?, contact_email?, contact_phone? }
 *   -> { ok, candidate } -- the rep fills in what the search couldn't find.
 *   Rep-entered email/phone/name count as sourced ("rep-entered").
 *
 * POST ?action=claim { candidate_id }
 *   -> { ok, customer_id, warnings[] } -- creates the Company (Status
 *   "Prospect", the rep's territory), its primary Contact and the Team
 *   assignment in ConnectWise, then the local customer row (prospect) and
 *   the 90-day claim. See connectwise-prospect-create.php.
 *
 * GET  ?action=my_claims
 *   -> { ok, claims: [{customer_id, name, city, state, claimed_by_name,
 *        claimed_at, deadline_at, days_left, is_mine}] } (everyone's,
 *        soonest deadline first; days_left < 0 = overdue).
 */

declare(strict_types=1);

require_once __DIR__ . '/_util.php';
require_once __DIR__ . '/prospecting-core.php';
require_once __DIR__ . '/catalog.php';
require_once __DIR__ . '/connectwise-prospect-create.php';

$pdo = relationships_db();
$user = relationships_require_login($pdo);
// Nothing below writes to the session, and searches/profiles run for
// minutes: release PHP's session file lock now, or every other request
// this rep's browser makes (including the status polling) would queue
// behind this one.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$action = $_GET['action'] ?? '';

/**
 * Sends the JSON response NOW and lets the script keep running in the
 * background (fastcgi_finish_request() on PHP-FPM; the Connection: close /
 * Content-Length / flush trick otherwise), for work that outlives any
 * sensible browser request.
 */
function relationships_prospect_respond_and_continue(array $payload): void
{
    ignore_user_abort(true);
    @set_time_limit(900);
    $json = (string) json_encode($payload);
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Connection: close');
    header('Content-Length: ' . strlen($json));
    http_response_code(200);
    echo $json;
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        flush();
    }
}

/** Discovery runs this rep has started today (Eastern). */
function relationships_prospect_runs_today(PDO $pdo, int $userId): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM prospect_searches WHERE user_id = :u AND created_at >= :start');
    $stmt->execute([':u' => $userId, ':start' => relationships_prospect_today_start_utc()]);
    return (int) $stmt->fetchColumn();
}

/** [configured?, daily cap] -- config problems never throw out of here. */
function relationships_prospect_settings(): array
{
    try {
        $config = relationships_anthropic_config();
        return [true, max(1, (int) ($config['daily_search_cap_per_rep'] ?? 10))];
    } catch (RelationshipsProspectingError $e) {
        return [false, 10];
    }
}

/** Search row + its non-duplicate candidates (ranked) + the skipped duplicates. */
function relationships_prospect_search_payload(PDO $pdo, array $search): array
{
    $stmt = $pdo->prepare(
        'SELECT * FROM prospect_candidates WHERE search_id = :s ORDER BY (dup_of IS NOT NULL) ASC, confidence DESC, distance_mi ASC, id ASC'
    );
    $stmt->execute([':s' => $search['id']]);
    $candidates = [];
    $skipped = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ($r['dup_of'] !== null) {
            $skipped[] = ['name' => $r['name'], 'reason' => $r['dup_of']];
        } elseif (count($candidates) < 8) {
            $candidates[] = relationships_prospect_candidate_out($r);
        }
    }
    return [
        'search' => [
            'id' => (int) $search['id'],
            'industry' => $search['industry'],
            'location_text' => $search['location_text'],
            'radius_miles' => (int) $search['radius_miles'],
            'created_at' => $search['created_at'],
            'status' => $search['status'],
        ],
        'candidates' => $candidates,
        'skipped' => $skipped,
    ];
}

function relationships_prospect_status_fields(PDO $pdo, array $user): array
{
    [$configured, $cap] = relationships_prospect_settings();
    return [
        'configured' => $configured,
        'industries' => array_merge(RELATIONSHIPS_PROSPECT_INDUSTRIES, [RELATIONSHIPS_PROSPECT_OTHER_INDUSTRY]),
        'daily_cap' => $cap,
        'remaining_today' => max(0, $cap - relationships_prospect_runs_today($pdo, (int) $user['id'])),
    ];
}

if ($action === 'latest') {
    $stmt = $pdo->prepare("SELECT * FROM prospect_searches WHERE user_id = :u AND status = 'done' ORDER BY id DESC LIMIT 1");
    $stmt->execute([':u' => $user['id']]);
    $search = $stmt->fetch(PDO::FETCH_ASSOC);

    $payload = ['ok' => true] + relationships_prospect_status_fields($pdo, $user);
    $payload['running_search_id'] = relationships_prospect_running_search_id($pdo, (int) $user['id']);
    if ($search === false) {
        relationships_respond(200, $payload + ['search' => null, 'candidates' => [], 'skipped' => []]);
    }
    relationships_respond(200, $payload + relationships_prospect_search_payload($pdo, $search));
}

/** This rep's still-running search id (started within the last 15 minutes), or null. Older ones are marked failed. */
function relationships_prospect_running_search_id(PDO $pdo, int $userId): ?int
{
    $pdo->prepare(
        "UPDATE prospect_searches SET status = 'failed', error = 'The search timed out on the server.', completed_at = datetime('now')
         WHERE user_id = :u AND status = 'running' AND created_at < datetime('now', '-15 minutes')"
    )->execute([':u' => $userId]);
    $stmt = $pdo->prepare("SELECT id FROM prospect_searches WHERE user_id = :u AND status = 'running' ORDER BY id DESC LIMIT 1");
    $stmt->execute([':u' => $userId]);
    $id = $stmt->fetchColumn();
    return $id === false ? null : (int) $id;
}

if ($action === 'search_status') {
    $searchId = (int) ($_GET['search_id'] ?? 0);
    relationships_prospect_running_search_id($pdo, (int) $user['id']); // expire stale ones first
    $stmt = $pdo->prepare('SELECT * FROM prospect_searches WHERE id = :id AND user_id = :u');
    $stmt->execute([':id' => $searchId, ':u' => $user['id']]);
    $search = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($search === false) {
        relationships_respond(404, ['ok' => false, 'error' => 'That search no longer exists.']);
    }
    if ($search['status'] === 'running') {
        relationships_respond(200, ['ok' => true, 'status' => 'running', 'search_id' => $searchId]);
    }
    if ($search['status'] === 'failed') {
        relationships_respond(200, ['ok' => true, 'status' => 'failed', 'search_id' => $searchId, 'error' => (string) $search['error']]);
    }
    relationships_respond(200, ['ok' => true, 'status' => 'done', 'search_id' => $searchId] + relationships_prospect_status_fields($pdo, $user) + relationships_prospect_search_payload($pdo, $search));
}

if ($action === 'my_claims') {
    $rows = $pdo->query(
        "SELECT pc.customer_id, pc.claimed_by_user_id, pc.claimed_by_name, pc.claimed_at, pc.deadline_at, c.name,
                pcand.city, pcand.state
         FROM prospect_claims pc
         JOIN customers c ON c.id = pc.customer_id
         LEFT JOIN prospect_candidates pcand ON pcand.id = pc.candidate_id
         WHERE pc.status = 'active' AND c.is_prospect_only = 1
         ORDER BY pc.deadline_at ASC"
    )->fetchAll(PDO::FETCH_ASSOC);
    $claims = [];
    foreach ($rows as $r) {
        $claims[] = [
            'customer_id' => (int) $r['customer_id'],
            'name' => $r['name'],
            'city' => $r['city'],
            'state' => $r['state'],
            'claimed_by_name' => $r['claimed_by_name'],
            'claimed_at' => $r['claimed_at'],
            'deadline_at' => $r['deadline_at'],
            'days_left' => relationships_prospect_days_left((string) $r['deadline_at']),
            'is_mine' => (int) $r['claimed_by_user_id'] === (int) $user['id'],
        ];
    }
    relationships_respond(200, ['ok' => true, 'claims' => $claims]);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    relationships_respond(405, ['ok' => false, 'error' => 'Method not allowed.']);
}

/** Loads a candidate, 404s if missing and 403s if it belongs to another rep's search. */
function relationships_prospect_load_candidate(PDO $pdo, int $id, array $user): array
{
    $stmt = $pdo->prepare(
        'SELECT c.*, s.user_id AS owner_user_id FROM prospect_candidates c JOIN prospect_searches s ON s.id = c.search_id WHERE c.id = :id'
    );
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        relationships_respond(404, ['ok' => false, 'error' => 'That prospect result no longer exists.']);
    }
    if ((int) $row['owner_user_id'] !== (int) $user['id']) {
        relationships_respond(403, ['ok' => false, 'error' => 'That result belongs to another rep\'s search.']);
    }
    return $row;
}

/** Recomputes confidence/tier/missing for a stored candidate from its current column values. */
function relationships_prospect_rescore(PDO $pdo, int $id): array
{
    $stmt = $pdo->prepare('SELECT * FROM prospect_candidates WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $distance = $row['distance_mi'] !== null ? (float) $row['distance_mi'] : null;
    [$score, $tier, $missing] = relationships_prospect_score([
        'employee_low' => $row['employee_low'] !== null ? (int) $row['employee_low'] : null,
        'employee_high' => $row['employee_high'] !== null ? (int) $row['employee_high'] : null,
        'industry' => $row['industry'],
        'contact_first_name' => $row['contact_first_name'],
        'contact_last_name' => $row['contact_last_name'],
        'contact_email' => $row['contact_email'],
        'contact_email_source_url' => $row['contact_email_source_url'],
        'contact_phone' => $row['contact_phone'],
        'phone' => $row['phone'],
    ], $distance);
    $pdo->prepare('UPDATE prospect_candidates SET confidence = :c, confidence_tier = :t, missing_json = :m WHERE id = :id')
        ->execute([':c' => $score, ':t' => $tier, ':m' => json_encode($missing), ':id' => $id]);
    $stmt->execute([':id' => $id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

if ($action === 'search') {
    $data = relationships_read_json_body();

    $industry = trim((string) ($data['industry'] ?? ''));
    $validIndustries = array_merge(RELATIONSHIPS_PROSPECT_INDUSTRIES, [RELATIONSHIPS_PROSPECT_OTHER_INDUSTRY]);
    if ($industry === '' || strcasecmp($industry, 'Any') === 0) {
        $industry = 'Any';
    } elseif (!in_array($industry, $validIndustries, true)) {
        relationships_respond(400, ['ok' => false, 'error' => 'Unknown industry.']);
    }
    $location = mb_substr(trim((string) ($data['location'] ?? '')), 0, 80);
    if ($location === '') {
        $location = 'Richmond, VA';
    }
    $radius = (int) ($data['radius_miles'] ?? RELATIONSHIPS_PROSPECT_MAX_MILES);
    $radius = max(10, min(RELATIONSHIPS_PROSPECT_MAX_MILES, $radius ?: RELATIONSHIPS_PROSPECT_MAX_MILES));

    [$configured, $cap] = relationships_prospect_settings();
    if (!$configured) {
        relationships_respond(503, ['ok' => false, 'error' => 'Prospecting is not set up yet: the research service API key (relationships/api/anthropic-config.php) is missing.']);
    }
    if (relationships_prospect_runs_today($pdo, (int) $user['id']) >= $cap) {
        relationships_respond(429, ['ok' => false, 'error' => "You've used all $cap Prospect searches for today. Try again tomorrow."]);
    }

    // One search at a time per rep (a second click while one is running
    // would just burn money).
    $alreadyRunning = relationships_prospect_running_search_id($pdo, (int) $user['id']);
    if ($alreadyRunning !== null) {
        relationships_respond(200, ['ok' => true, 'status' => 'running', 'search_id' => $alreadyRunning]);
    }

    $pdo->prepare('INSERT INTO prospect_searches (user_id, user_name, industry, location_text, radius_miles, status) VALUES (:u, :n, :i, :l, :r, \'running\')')
        ->execute([':u' => $user['id'], ':n' => $user['name'], ':i' => $industry, ':l' => $location, ':r' => $radius]);
    $searchId = (int) $pdo->lastInsertId();

    // Answer the browser now; the research runs on in the background and
    // the UI polls ?action=search_status. Results are saved either way,
    // even if the rep closes the tab.
    relationships_prospect_respond_and_continue(['ok' => true, 'status' => 'running', 'search_id' => $searchId]);

    // Safety net: if anything below dies unexpectedly (fatal, uncaught
    // exception), don't leave the search 'running' for the UI to wait on.
    register_shutdown_function(static function () use ($pdo, $searchId): void {
        try {
            $pdo->prepare("UPDATE prospect_searches SET status = 'failed', error = 'The search stopped unexpectedly.', completed_at = datetime('now') WHERE id = :id AND status = 'running'")
                ->execute([':id' => $searchId]);
        } catch (Throwable $e) {
            // nothing more we can do
        }
    });

    $fail = static function (string $message) use ($pdo, $searchId): never {
        $pdo->prepare("UPDATE prospect_searches SET status = 'failed', error = :e, completed_at = datetime('now') WHERE id = :id")
            ->execute([':e' => mb_substr($message, 0, 500), ':id' => $searchId]);
        exit;
    };

    // Names this rep has already been shown (recent, any search) so a
    // repeat "Prospect" command surfaces new businesses.
    $exclStmt = $pdo->prepare(
        'SELECT DISTINCT c.name FROM prospect_candidates c JOIN prospect_searches s ON s.id = c.search_id
         WHERE s.user_id = :u AND s.id != :sid ORDER BY c.id DESC LIMIT 60'
    );
    $exclStmt->execute([':u' => $user['id'], ':sid' => $searchId]);
    $exclude = $exclStmt->fetchAll(PDO::FETCH_COLUMN);

    try {
        $config = relationships_anthropic_config();
        $run = relationships_prospect_run_agent(
            relationships_prospect_system_prompt(),
            relationships_prospect_discovery_prompt($industry, $location, $radius, array_map('strval', $exclude)),
            max(1, (int) ($config['max_web_searches'] ?? 6)),
            0,      // no web fetch in discovery -- profile reads the site
            'low',
            8000
        );
    } catch (Throwable $e) {
        error_log('[relationships/prospecting] discovery failed for search ' . $searchId . ': ' . $e->getMessage());
        $fail('The research agent could not finish: ' . $e->getMessage());
    }

    @set_time_limit(150); // fresh budget for the geocoding / duplicate checks below

    $parsed = relationships_prospect_extract_json($run['text']);
    $rawList = is_array($parsed['candidates'] ?? null) ? $parsed['candidates'] : null;
    if ($rawList === null) {
        error_log('[relationships/prospecting] unparseable discovery output for search ' . $searchId . ': ' . mb_substr($run['text'], 0, 500));
        $fail('The research agent returned results in an unexpected format. Please try again.');
    }

    $localNames = relationships_prospect_local_names($pdo);
    $insert = $pdo->prepare(
        'INSERT INTO prospect_candidates (
            search_id, name, website, address_line1, city, state, zip, phone, industry,
            employee_low, employee_high, employee_evidence, employee_source_url, summary,
            contact_first_name, contact_last_name, contact_title, contact_email, contact_phone, contact_profile_url,
            contact_name_source_url, contact_email_source_url, contact_phone_source_url,
            source_urls_json, lat, lng, distance_mi, confidence, confidence_tier, missing_json, dup_of
         ) VALUES (
            :search_id, :name, :website, :address_line1, :city, :state, :zip, :phone, :industry,
            :employee_low, :employee_high, :employee_evidence, :employee_source_url, :summary,
            :contact_first_name, :contact_last_name, :contact_title, :contact_email, :contact_phone, :contact_profile_url,
            :contact_name_source_url, :contact_email_source_url, :contact_phone_source_url,
            :source_urls_json, :lat, :lng, :distance_mi, :confidence, :confidence_tier, :missing_json, :dup_of
         )'
    );

    $seen = [];
    $kept = 0;
    foreach (array_slice($rawList, 0, 12) as $raw) {
        $c = relationships_prospect_clean_candidate($raw);
        if ($c === null) {
            continue;
        }
        $norm = relationships_prospect_normalize_name($c['name']);
        if (isset($seen[$norm])) {
            continue; // the agent listed the same business twice
        }
        $seen[$norm] = true;

        $dup = relationships_prospect_local_dup($localNames, $c['name']);
        if ($dup !== null) {
            $dup = 'Already a CodeBlue customer/prospect: ' . $dup;
        } else {
            $cwDup = relationships_prospect_cw_dup($c['name']);
            $dup = $cwDup !== null ? 'Already in ConnectWise: ' . $cwDup : null;
        }

        // Don't spend geocoding calls on businesses that will be skipped
        // anyway, or once we already have 8 good ones.
        $lat = $lng = $miles = null;
        if ($dup === null && $kept < 8) {
            [$lat, $lng, $miles] = relationships_prospect_locate($c);
        }
        [$score, $tier, $missing] = relationships_prospect_score($c, $miles);
        if ($dup === null) {
            $kept++;
        }

        $insert->execute([
            ':search_id' => $searchId,
            ':name' => $c['name'], ':website' => $c['website'],
            ':address_line1' => $c['address_line1'], ':city' => $c['city'], ':state' => $c['state'], ':zip' => $c['zip'],
            ':phone' => $c['phone'], ':industry' => $c['industry'],
            ':employee_low' => $c['employee_low'], ':employee_high' => $c['employee_high'],
            ':employee_evidence' => $c['employee_evidence'], ':employee_source_url' => $c['employee_source_url'],
            ':summary' => $c['summary'],
            ':contact_first_name' => $c['contact_first_name'], ':contact_last_name' => $c['contact_last_name'],
            ':contact_title' => $c['contact_title'], ':contact_email' => $c['contact_email'],
            ':contact_phone' => $c['contact_phone'], ':contact_profile_url' => $c['contact_profile_url'],
            ':contact_name_source_url' => $c['contact_name_source_url'],
            ':contact_email_source_url' => $c['contact_email_source_url'],
            ':contact_phone_source_url' => $c['contact_phone_source_url'],
            ':source_urls_json' => json_encode($c['source_urls']),
            ':lat' => $lat, ':lng' => $lng, ':distance_mi' => $miles,
            ':confidence' => $score, ':confidence_tier' => $tier, ':missing_json' => json_encode($missing),
            ':dup_of' => $dup,
        ]);
    }

    $pdo->prepare(
        "UPDATE prospect_searches SET status = 'done', tokens_in = :ti, tokens_out = :to, web_searches = :ws, completed_at = datetime('now') WHERE id = :id"
    )->execute([':ti' => $run['tokens_in'], ':to' => $run['tokens_out'], ':ws' => $run['searches'], ':id' => $searchId]);

    exit; // the browser already has its answer; it picks results up via search_status
}

if ($action === 'profile') {
    $data = relationships_read_json_body();
    $cand = relationships_prospect_load_candidate($pdo, (int) ($data['candidate_id'] ?? 0), $user);
    [$configured] = relationships_prospect_settings();
    if (!$configured) {
        relationships_respond(503, ['ok' => false, 'error' => 'Prospecting is not set up yet: the research service API key is missing on the server.']);
    }
    ignore_user_abort(true);

    $catalogLines = [];
    $catalogIndex = []; // lowercase service name => [pillar name, service name]
    foreach (relationships_catalog() as $pillar) {
        $catalogLines[] = '- ' . $pillar['name'] . ': ' . implode('; ', array_values($pillar['services']));
        foreach ($pillar['services'] as $svcName) {
            $catalogIndex[mb_strtolower((string) $svcName)] = [(string) $pillar['name'], (string) $svcName];
        }
    }

    try {
        $config = relationships_anthropic_config();
        $run = relationships_prospect_run_agent(
            relationships_prospect_system_prompt(),
            relationships_prospect_profile_prompt($cand, implode("\n", $catalogLines)),
            max(1, (int) ($config['max_web_searches_profile'] ?? 4)),
            4,
            'medium',
            6000,
            280
        );
    } catch (Throwable $e) {
        error_log('[relationships/prospecting] profile failed for candidate ' . $cand['id'] . ': ' . $e->getMessage());
        relationships_respond(502, ['ok' => false, 'error' => 'The research agent could not finish: ' . $e->getMessage()]);
    }
    @set_time_limit(60);

    $parsed = relationships_prospect_extract_json($run['text']);
    if ($parsed === null) {
        error_log('[relationships/prospecting] unparseable profile output for candidate ' . $cand['id'] . ': ' . mb_substr($run['text'], 0, 500));
        relationships_respond(502, ['ok' => false, 'error' => 'The research agent returned results in an unexpected format. Please try again.']);
    }

    $pc = is_array($parsed['primary_contact'] ?? null) ? $parsed['primary_contact'] : [];
    $profContact = [
        'first_name' => relationships_prospect_str($pc['first_name'] ?? null, 60),
        'last_name' => relationships_prospect_str($pc['last_name'] ?? null, 60),
        'title' => relationships_prospect_str($pc['title'] ?? null, 100),
        'email' => relationships_prospect_email($pc['email'] ?? null),
        'phone' => relationships_prospect_str($pc['phone'] ?? null, 40),
        'profile_url' => relationships_prospect_url($pc['profile_url'] ?? null),
        'background' => relationships_prospect_str($pc['background'] ?? null, 500),
        'name_source_url' => relationships_prospect_url($pc['name_source_url'] ?? null),
        'email_source_url' => relationships_prospect_url($pc['email_source_url'] ?? null),
        'phone_source_url' => relationships_prospect_url($pc['phone_source_url'] ?? null),
    ];

    $recs = [];
    foreach ((array) ($parsed['recommendations'] ?? []) as $rec) {
        if (!is_array($rec)) {
            continue;
        }
        $svcKey = mb_strtolower(trim((string) ($rec['service'] ?? '')));
        if (!isset($catalogIndex[$svcKey]) || count($recs) >= 5) {
            continue; // only real CodeBlue services
        }
        $recs[] = ['pillar' => $catalogIndex[$svcKey][0], 'service' => $catalogIndex[$svcKey][1], 'why' => relationships_prospect_str($rec['why'] ?? null, 300) ?? ''];
    }

    $points = [];
    foreach ((array) ($parsed['talking_points'] ?? []) as $tp) {
        $t = relationships_prospect_str($tp, 240);
        if ($t !== null && count($points) < 4) {
            $points[] = $t;
        }
    }
    $sources = [];
    foreach ((array) ($parsed['sources'] ?? []) as $u) {
        $u = relationships_prospect_url($u);
        if ($u !== null && count($sources) < 8) {
            $sources[$u] = $u;
        }
    }

    $profile = [
        'business_summary' => relationships_prospect_str($parsed['business_summary'] ?? null, 900),
        'employee_note' => relationships_prospect_str($parsed['employee_note'] ?? null, 300),
        'primary_contact' => $profContact,
        'recommendations' => $recs,
        'talking_points' => $points,
        'sources' => array_values($sources),
    ];

    // Fill contact gaps from what the profile found -- never overwrite
    // anything already on the candidate (including rep-entered values).
    $fill = [];
    $map = [
        'contact_first_name' => 'first_name', 'contact_last_name' => 'last_name', 'contact_title' => 'title',
        'contact_email' => 'email', 'contact_phone' => 'phone', 'contact_profile_url' => 'profile_url',
    ];
    foreach ($map as $col => $key) {
        if (($cand[$col] ?? '') === '' && $profContact[$key] !== null) {
            $fill[$col] = $profContact[$key];
        }
    }
    $srcMap = ['contact_name_source_url' => 'name_source_url', 'contact_email_source_url' => 'email_source_url', 'contact_phone_source_url' => 'phone_source_url'];
    foreach ($srcMap as $col => $key) {
        if (($cand[$col] ?? '') === '' && $profContact[$key] !== null) {
            $fill[$col] = $profContact[$key];
        }
    }
    if (($cand['summary'] ?? '') === '' && $profile['business_summary'] !== null) {
        $fill['summary'] = mb_substr((string) $profile['business_summary'], 0, 600);
    }

    $sets = ['profile_json = :profile_json', "profile_at = datetime('now')"];
    $params = [':profile_json' => json_encode($profile), ':id' => $cand['id']];
    foreach ($fill as $col => $val) {
        $sets[] = "$col = :f_$col";
        $params[":f_$col"] = $val;
    }
    $pdo->prepare('UPDATE prospect_candidates SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($params);
    $pdo->prepare('UPDATE prospect_searches SET tokens_in = tokens_in + :ti, tokens_out = tokens_out + :to, web_searches = web_searches + :ws WHERE id = :sid')
        ->execute([':ti' => $run['tokens_in'], ':to' => $run['tokens_out'], ':ws' => $run['searches'], ':sid' => $cand['search_id']]);

    $row = relationships_prospect_rescore($pdo, (int) $cand['id']);
    relationships_respond(200, ['ok' => true, 'candidate' => relationships_prospect_candidate_out($row)]);
}

if ($action === 'update_candidate') {
    $data = relationships_read_json_body();
    $cand = relationships_prospect_load_candidate($pdo, (int) ($data['candidate_id'] ?? 0), $user);
    if ($cand['claimed_customer_id'] !== null) {
        relationships_respond(409, ['ok' => false, 'error' => 'This prospect has already been claimed.']);
    }

    $new = [];
    $textFields = [
        'phone' => 40, 'address_line1' => 160, 'city' => 80, 'state' => 30, 'zip' => 15,
        'contact_first_name' => 60, 'contact_last_name' => 60, 'contact_title' => 100, 'contact_phone' => 40,
    ];
    foreach ($textFields as $field => $max) {
        if (array_key_exists($field, $data)) {
            $new[$field] = relationships_prospect_str($data[$field], $max);
        }
    }
    if (array_key_exists('website', $data)) {
        $raw = trim((string) $data['website']);
        $url = relationships_prospect_url($raw);
        if ($raw !== '' && $url === null) {
            relationships_respond(400, ['ok' => false, 'error' => 'That website address doesn\'t look valid.']);
        }
        $new['website'] = $url;
    }
    if (array_key_exists('contact_email', $data)) {
        $raw = trim((string) $data['contact_email']);
        $email = relationships_prospect_email($raw);
        if ($raw !== '' && $email === null) {
            relationships_respond(400, ['ok' => false, 'error' => 'Enter a valid email address.']);
        }
        $new['contact_email'] = $email;
    }

    // Anything the rep changed is "sourced" by the rep from here on.
    $sourceFor = ['contact_email' => 'contact_email_source_url', 'contact_phone' => 'contact_phone_source_url'];
    foreach ($sourceFor as $field => $srcCol) {
        if (array_key_exists($field, $new) && $new[$field] !== ($cand[$field] ?: null)) {
            $new[$srcCol] = $new[$field] !== null ? 'rep-entered' : null;
        }
    }
    foreach (['contact_first_name', 'contact_last_name'] as $field) {
        if (array_key_exists($field, $new) && $new[$field] !== ($cand[$field] ?: null) && $new[$field] !== null) {
            $new['contact_name_source_url'] = 'rep-entered';
        }
    }

    // Re-geocode if the location changed.
    $addrChanged = false;
    foreach (['address_line1', 'city', 'state', 'zip'] as $field) {
        if (array_key_exists($field, $new) && $new[$field] !== ($cand[$field] ?: null)) {
            $addrChanged = true;
        }
    }
    if ($addrChanged) {
        $merged = array_merge($cand, $new);
        [$lat, $lng, $miles] = relationships_prospect_locate($merged);
        $new['lat'] = $lat;
        $new['lng'] = $lng;
        $new['distance_mi'] = $miles;
    }

    if ($new !== []) {
        $sets = [];
        $params = [':id' => $cand['id']];
        foreach ($new as $col => $val) {
            $sets[] = "$col = :v_$col";
            $params[":v_$col"] = $val;
        }
        $pdo->prepare('UPDATE prospect_candidates SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($params);
    }
    $row = relationships_prospect_rescore($pdo, (int) $cand['id']);
    relationships_respond(200, ['ok' => true, 'candidate' => relationships_prospect_candidate_out($row)]);
}

if ($action === 'claim') {
    $data = relationships_read_json_body();
    $cand = relationships_prospect_load_candidate($pdo, (int) ($data['candidate_id'] ?? 0), $user);
    if ($cand['claimed_customer_id'] !== null) {
        relationships_respond(409, ['ok' => false, 'error' => 'This prospect has already been claimed.']);
    }

    // Required by Michael: business name + primary contact + phone + email.
    $missing = [];
    if (trim((string) $cand['name']) === '') $missing[] = 'business name';
    if (trim((string) $cand['contact_first_name']) === '') $missing[] = 'contact first name';
    if (trim((string) $cand['contact_last_name']) === '') $missing[] = 'contact last name';
    if (relationships_prospect_email($cand['contact_email']) === null) $missing[] = 'contact email';
    $phone = trim((string) ($cand['contact_phone'] ?: $cand['phone']));
    if ($phone === '') $missing[] = 'phone number';
    if ($missing !== []) {
        relationships_respond(400, ['ok' => false, 'error' => 'Before claiming, fill in: ' . implode(', ', $missing) . '.']);
    }

    ignore_user_abort(true);
    @set_time_limit(120);

    // Everything ConnectWise needs is resolved BEFORE anything is written,
    // so a missing Prospect status / member record can't leave a half-made company.
    try {
        relationships_cw_config();
        $statusId = relationships_cw_resolve_company_status('Prospect');
        $memberId = relationships_cw_resolve_member_id((string) $user['email'], (string) $user['name']);
    } catch (Throwable $e) {
        error_log('[relationships/prospecting] claim pre-checks failed: ' . $e->getMessage());
        relationships_respond(502, ['ok' => false, 'error' => 'Could not reach ConnectWise to set this up: ' . mb_substr($e->getMessage(), 0, 300)]);
    }
    if ($statusId === null) {
        relationships_respond(422, ['ok' => false, 'error' => 'ConnectWise has no Company Status named "Prospect". Add it (Setup Tables → Company Statuses) and try again.']);
    }
    if ($memberId === null) {
        relationships_respond(422, ['ok' => false, 'error' => 'Could not find your ConnectWise member record (looked for ' . $user['email'] . ' and the name "' . $user['name'] . '"). Ask an admin to check your ConnectWise member email.']);
    }
    [$territoryId, $territoryName] = relationships_cw_resolve_rep_territory($pdo, (string) $user['email'], (string) $user['name']);

    // Last duplicate check right before writing (the search-time check can be stale).
    $localDup = relationships_prospect_local_dup(relationships_prospect_local_names($pdo), (string) $cand['name']);
    if ($localDup !== null) {
        relationships_respond(409, ['ok' => false, 'error' => 'Already a CodeBlue customer/prospect: ' . $localDup . '.']);
    }
    $cwDup = relationships_prospect_cw_dup((string) $cand['name']);
    if ($cwDup !== null) {
        relationships_respond(409, ['ok' => false, 'error' => 'Already in ConnectWise: ' . $cwDup . '.']);
    }

    try {
        $company = relationships_cw_create_prospect_company([
            'name' => $cand['name'], 'address_line1' => $cand['address_line1'],
            'city' => $cand['city'], 'state' => $cand['state'], 'zip' => $cand['zip'],
        ], $statusId, $territoryId);
    } catch (Throwable $e) {
        error_log('[relationships/prospecting] company create failed: ' . $e->getMessage());
        relationships_respond(502, ['ok' => false, 'error' => 'ConnectWise rejected the new company: ' . mb_substr($e->getMessage(), 0, 400)]);
    }
    $companyId = (int) $company['id'];

    // From here on the company EXISTS in ConnectWise, so record it locally
    // immediately -- any later hiccup becomes a warning, never an orphan.
    $pdo->prepare(
        'INSERT INTO customers (connectwise_id, name, is_mock, is_prospect_only, territory_name) VALUES (:cw, :name, 0, 1, :terr)'
    )->execute([':cw' => (string) $companyId, ':name' => $cand['name'], ':terr' => $territoryName]);
    $customerId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        "INSERT INTO prospect_claims (customer_id, candidate_id, claimed_by_user_id, claimed_by_name, claimed_by_email, cw_member_id, cw_company_id, deadline_at)
         VALUES (:cid, :cand, :uid, :uname, :uemail, :mid, :cwid, datetime('now', '+90 days'))"
    )->execute([
        ':cid' => $customerId, ':cand' => $cand['id'], ':uid' => $user['id'], ':uname' => $user['name'],
        ':uemail' => $user['email'], ':mid' => $memberId, ':cwid' => (string) $companyId,
    ]);
    $pdo->prepare('UPDATE prospect_candidates SET claimed_customer_id = :cid WHERE id = :id')
        ->execute([':cid' => $customerId, ':id' => $cand['id']]);

    $warnings = [];
    try {
        relationships_cw_set_prospect_company_extras($companyId, $phone, $cand['website']);
    } catch (Throwable $e) {
        error_log('[relationships/prospecting] company phone/website failed for ' . $companyId . ': ' . $e->getMessage());
        $warnings[] = 'The company phone/website could not be saved in ConnectWise — please add them there.';
    }
    try {
        [$contact, $contactWarnings] = relationships_cw_create_prospect_contact(
            $companyId, (string) $cand['contact_first_name'], (string) $cand['contact_last_name'],
            $cand['contact_title'], (string) $cand['contact_email'], $phone
        );
        $warnings = array_merge($warnings, $contactWarnings);
        $pdo->prepare('UPDATE prospect_claims SET cw_contact_id = :c WHERE customer_id = :cid')
            ->execute([':c' => (string) $contact['id'], ':cid' => $customerId]);
    } catch (Throwable $e) {
        error_log('[relationships/prospecting] contact create failed for company ' . $companyId . ': ' . $e->getMessage());
        $warnings[] = 'The company was created but its primary contact could not be added — please add ' . $cand['contact_first_name'] . ' ' . $cand['contact_last_name'] . ' in ConnectWise.';
    }
    $warnings = array_merge($warnings, relationships_cw_assign_prospect_team($companyId, $memberId));

    relationships_respond(200, ['ok' => true, 'customer_id' => $customerId, 'warnings' => $warnings]);
}

relationships_respond(400, ['ok' => false, 'error' => 'Unknown action.']);
