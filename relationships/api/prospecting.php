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
 *   -> same shape as 'latest' for the NEW search. Takes roughly 1-3
 *   minutes (web research). Counts against the rep's daily cap.
 */

declare(strict_types=1);

require_once __DIR__ . '/_util.php';
require_once __DIR__ . '/prospecting-core.php';

$pdo = relationships_db();
$user = relationships_require_login($pdo);

$action = $_GET['action'] ?? '';

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
    if ($search === false) {
        relationships_respond(200, $payload + ['search' => null, 'candidates' => [], 'skipped' => []]);
    }
    relationships_respond(200, $payload + relationships_prospect_search_payload($pdo, $search));
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    relationships_respond(405, ['ok' => false, 'error' => 'Method not allowed.']);
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

    // Keep going (and save the results) even if the rep closes the tab --
    // a run takes minutes and costs real money.
    ignore_user_abort(true);

    $pdo->prepare('INSERT INTO prospect_searches (user_id, user_name, industry, location_text, radius_miles, status) VALUES (:u, :n, :i, :l, :r, \'running\')')
        ->execute([':u' => $user['id'], ':n' => $user['name'], ':i' => $industry, ':l' => $location, ':r' => $radius]);
    $searchId = (int) $pdo->lastInsertId();

    $fail = static function (string $message, int $code = 502) use ($pdo, $searchId): never {
        $pdo->prepare("UPDATE prospect_searches SET status = 'failed', error = :e, completed_at = datetime('now') WHERE id = :id")
            ->execute([':e' => mb_substr($message, 0, 500), ':id' => $searchId]);
        relationships_respond($code, ['ok' => false, 'error' => $message]);
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
            max(1, (int) ($config['max_web_searches'] ?? 8))
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

    $searchStmt = $pdo->prepare('SELECT * FROM prospect_searches WHERE id = :id');
    $searchStmt->execute([':id' => $searchId]);
    $search = $searchStmt->fetch(PDO::FETCH_ASSOC);

    relationships_respond(200, ['ok' => true] + relationships_prospect_status_fields($pdo, $user) + relationships_prospect_search_payload($pdo, $search));
}

relationships_respond(400, ['ok' => false, 'error' => 'Unknown action.']);
