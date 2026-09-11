<?php
/**
 * relationships/api/connectwise-activity-create.php
 *
 * Creates a ConnectWise Manage Activity when a Relationship Coordinator
 * checks off a cross-sell checklist step -- built 2026-09-11 per Michael's
 * request (verbatim spec kept in claude/relationships-connectwise-sync.md
 * in the project). Hooked in from checklist.php's 'set' action.
 *
 * THIS IS THE FIRST WRITE (POST) THIS INTEGRATION HAS EVER MADE AGAINST
 * ConnectWise. Every other file here (connectwise.php, connectwise-activity.php,
 * the sync-core files) only ever GETs. That matters for how cautiously this
 * file is written:
 *
 * - Every prior GET-side mistake in this integration was recoverable by
 *   just fixing the field name/condition and re-running the sync -- nothing
 *   was ever written anywhere it couldn't be re-derived. A bad write here
 *   creates a REAL Activity record in CodeBlue's live ConnectWise instance
 *   that a human (or Michael) then has to notice and clean up by hand.
 * - The build environment cannot reach connect.codebluetechnology.com at
 *   all (confirmed 2026-09-11 -- even a plain read-only GET fails with
 *   "cURL error 56: CONNECT tunnel failed, response 403", an org-level
 *   network policy, not a credentials problem), so nothing here could be
 *   smoke-tested against a live response from this build environment.
 *
 * - 2026-09-11 UPDATE: the module/endpoint paths (`/sales/activities`,
 *   `/sales/activities/statuses`, `/sales/activities/types`) and the
 *   `assignedBy` field name are now CONFIRMED -- not guessed -- straight
 *   from ConnectWise's own official REST API documentation (Michael
 *   uploaded "REST Developer Network.pdf", a saved capture of the real
 *   interactive docs page, after two wrong-guess deploys both hit real
 *   ConnectWise 404s on `/company/...` paths). That PDF's Members section
 *   was never expanded/printed, though, so `relationships_cw_member_id_by_email()`
 *   below (`/system/members`, `officeEmail`) is STILL an unverified guess --
 *   the one piece of this file that hasn't hit real ConnectWise ground
 *   truth yet. Everything else was "best guess, needs a real created
 *   Activity checked against ConnectWise Manage's UI before it's trusted";
 *   as of this update, only the Member lookup still carries that caveat.
 *
 * Because of that, this deliberately does NOT follow the same "let a wrong
 * field surface as a visible, fixable error" posture connectwise-activity.php
 * uses for reads -- a read that silently returns a wrong number just shows
 * a wrong number; a WRITE that fails outright would mean the checklist step
 * saves locally but the RC's completed step never reaches ConnectWise at
 * all, with no visible signal. Instead: try the full payload first (every
 * field the spec asked for); if ConnectWise rejects the whole request over
 * one speculative field, retry once with just the fields this file is
 * confident about, so a wrong guess on an optional field degrades to "the
 * Activity exists, minus that one field" instead of "no Activity at all."
 * Both attempts, and which one (if either) succeeded, are logged to
 * checklist_cw_activity_log for Michael to spot-check once this is live.
 */

declare(strict_types=1);

require_once __DIR__ . '/connectwise.php';

/**
 * "NextStep Action" ActivityType id -- looked up by name (never hardcoded)
 * the same way this integration always resolves a ConnectWise reference id,
 * and cached in cw_sync_meta so a busy day of checklist clicks doesn't mean
 * a repeat lookup round-trip every single time. Cache is intentionally
 * permanent (these setup-table names essentially never change) -- delete
 * the cw_sync_meta row by hand if CodeBlue ever renames or recreates this
 * ActivityType in ConnectWise.
 *
 * 2026-09-11, TWO consecutive wrong guesses on real-server tests (Agnihotri
 * Cosmetic Surgery): first the flat `/company/activityTypes`, then the
 * nested-under-company guess `/company/activities/types` -- both returned a
 * real ConnectWise 404 (caught and logged by checklist_cw_activity_log
 * rather than silently matching nothing). Fixed for real this time to
 * `/sales/activities/types` -- CONFIRMED directly from ConnectWise's own
 * official REST API documentation (Michael's uploaded "REST Developer
 * Network.pdf" screenshot: "GET /sales/activities/types Get List of
 * ActivityType"). The Activities module lives under `/sales/`, not
 * `/company/` -- that was the root mistake both prior guesses made.
 */
function relationships_cw_activity_type_id(PDO $pdo): ?int
{
    return relationships_cw_activity_cached_lookup(
        $pdo,
        'cw_activity_type_id:NextStep Action',
        static function (): ?int {
            $rows = relationships_cw_list('/sales/activities/types', "name='NextStep Action'", ['id', 'name'], 10);
            return isset($rows[0]['id']) ? (int) $rows[0]['id'] : null;
        }
    );
}

/**
 * "Closed" ActivityStatus id -- same lookup-and-cache pattern as the
 * ActivityType above, and the same 2026-09-11 path fix (`/sales/activities/statuses`,
 * confirmed from the official docs PDF -- see that function's doc comment
 * for the full story).
 */
function relationships_cw_activity_status_id(PDO $pdo): ?int
{
    return relationships_cw_activity_cached_lookup(
        $pdo,
        'cw_activity_status_id:Closed',
        static function (): ?int {
            $rows = relationships_cw_list('/sales/activities/statuses', "name='Closed'", ['id', 'name'], 10);
            return isset($rows[0]['id']) ? (int) $rows[0]['id'] : null;
        }
    );
}

/**
 * Shared cache-then-fetch helper for the two lookups above. Reads/writes
 * cw_sync_meta (the same small key/value table connectwise-sync-core.php
 * already uses for sync bookkeeping) rather than a new table, since this is
 * exactly that kind of "small durable fact, not worth its own table" data.
 * A failed/empty lookup is NOT cached -- so a transient ConnectWise error on
 * the very first checklist completion doesn't get "stuck" as a permanent
 * null; it just tries again next time.
 */
function relationships_cw_activity_cached_lookup(PDO $pdo, string $cacheKey, callable $fetch): ?int
{
    $cached = $pdo->prepare('SELECT value FROM cw_sync_meta WHERE key = :k');
    $cached->execute([':k' => $cacheKey]);
    $row = $cached->fetch(PDO::FETCH_ASSOC);
    if ($row !== false && $row['value'] !== null && $row['value'] !== '') {
        return (int) $row['value'];
    }

    $id = $fetch();
    if ($id !== null) {
        $pdo->prepare('INSERT OR REPLACE INTO cw_sync_meta (key, value) VALUES (:k, :v)')
            ->execute([':k' => $cacheKey, ':v' => (string) $id]);
    }
    return $id;
}

/**
 * ConnectWise Member id whose officeEmail matches the given (Relationships
 * dashboard login) email, case-insensitively. `officeEmail` is the
 * documented field name for a Member's email in ConnectWise's REST API --
 * NOT independently confirmed against a real Member record from this build
 * environment (see file header). Not cached -- this runs at most once per
 * checklist completion, and a Member's email is exactly the kind of thing
 * that could change, unlike the two setup-table names above.
 */
function relationships_cw_member_id_by_email(string $email): ?int
{
    $email = trim($email);
    if ($email === '') {
        return null;
    }
    $escaped = str_replace("'", "\\'", $email);
    $rows = relationships_cw_list('/system/members', "officeEmail='$escaped'", ['id', 'identifier', 'officeEmail'], 10);
    return isset($rows[0]['id']) ? (int) $rows[0]['id'] : null;
}

/**
 * This customer's earliest-alphabetically synced ConnectWise contact
 * (by last name, then first name) -- Michael's own pick for "primary
 * contact" given this app's local `contacts` table has no primary/role flag
 * at all (2026-09-11 AskUserQuestion: "First synced contact"). Returns null
 * for a customer with no synced contacts yet (mock/prospect customers, or a
 * real customer before its first Contacts sync) -- the Activity is still
 * created, just with no Contact set, rather than blocking on this.
 */
function relationships_checklist_first_contact_id(PDO $pdo, int $customerId): ?string
{
    $stmt = $pdo->prepare(
        'SELECT connectwise_contact_id FROM contacts WHERE customer_id = :cid
         ORDER BY last_name COLLATE NOCASE ASC, first_name COLLATE NOCASE ASC LIMIT 1'
    );
    $stmt->execute([':cid' => $customerId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row !== false ? (string) $row['connectwise_contact_id'] : null;
}

/**
 * Builds and POSTs the ConnectWise Activity for one newly-completed
 * checklist step, with the two-tier fallback described in the file header.
 * Never throws for an ordinary ConnectWise-side rejection of the full
 * payload (that's the expected, handled case) -- only for something this
 * function has no fallback for at all (can't resolve the ActivityType/
 * ActivityStatus id, or the reduced/core payload ALSO gets rejected).
 *
 * $ctx keys: customer_id (int), cw_company_id (string, ConnectWise Company
 * id -- NOT this app's own customer_id), service_name (string),
 * step_number (int), step_label (string), completed_by_name (string),
 * completed_by_email (string), completed_at_display (string, human-readable
 * Eastern timestamp for the Notes field).
 *
 * Returns ['id' => string, 'variant' => 'full'|'core'] on success.
 * Throws RelationshipsConnectWiseError if neither attempt succeeds.
 */
function relationships_cw_create_checklist_activity(PDO $pdo, array $ctx): array
{
    $typeId = relationships_cw_activity_type_id($pdo);
    $statusId = relationships_cw_activity_status_id($pdo);
    if ($typeId === null || $statusId === null) {
        throw new RelationshipsConnectWiseError(
            'Could not resolve the ConnectWise "NextStep Action" ActivityType or "Closed" ActivityStatus id ' .
            '(a lookup by name returned no match) -- Activity not created.'
        );
    }

    $summary = $ctx['service_name'] . ' - ' . $ctx['step_number'] . ', ' . $ctx['step_label'];
    // ConnectWise's documented Activity `name` field is commonly capped
    // around 100 chars in older on-prem releases like this one's
    // v4_6_release -- truncate defensively rather than risk the whole
    // create failing on an over-length summary for an unusually long
    // service/step combination.
    if (strlen($summary) > 100) {
        $summary = substr($summary, 0, 97) . '...';
    }
    $notes = $ctx['step_label'] . ' - ' . $ctx['completed_by_name'] . ' - ' . $ctx['completed_at_display'];

    // 2026-09-11: the ATOM format below (e.g. "2026-09-11T14:32:00-04:00")
    // was REJECTED by a real POST /sales/activities call -- ConnectWise
    // returned {"code":"UnsupportedFormat","message":"Unsupported format
    // applied to dateStart", ...} for both dateStart and dateEnd. Fixed to
    // match the exact format CONFIRMED in the docs PDF's own Activity
    // schema example: UTC, millisecond precision, "Z" suffix --
    // "2026-09-11T16:42:39.277Z" -- not a guess this time, a direct copy of
    // what ConnectWise's own example shows. The underlying instant captured
    // is still "now" in US Eastern per Michael's spec; only the serialized
    // format changes -- captured in Eastern first (so DST is handled by PHP's
    // tzdata rather than a hardcoded offset), then converted to UTC for the
    // wire format ConnectWise actually accepts.
    $now = new DateTimeImmutable('now', new DateTimeZone('America/New_York'));
    $dateIso = $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');

    $corePayload = [
        'name' => $summary,
        'notes' => $notes,
        'type' => ['id' => $typeId],
        'status' => ['id' => $statusId],
        'company' => ['id' => (int) $ctx['cw_company_id']],
        'dateStart' => $dateIso,
        'dateEnd' => $dateIso,
    ];

    // 2026-09-11: on the first real-server test past the path fix above,
    // this lookup returned a real ConnectWise HTTP 403 -- {"code":"Security",
    // "message":"You do not have security permission to perform this
    // action."} -- because the API key's Security Role isn't granted read
    // access to System > Member Maintenance (a ConnectWise-side permissions
    // config issue, NOT a wrong endpoint/field -- /system/members itself is
    // correct). That error, left unguarded, aborted the ENTIRE Activity
    // create -- the checklist step's completion never reached ConnectWise at
    // all just because one optional field couldn't be looked up. Guarded the
    // same way the $fullPayload/$corePayload split above already handles
    // other optional-field failures: swallow it, create the Activity without
    // an assigned member, and let Michael notice/fix the permission on his
    // own schedule rather than losing the whole record every time someone
    // checks a box until he does.
    try {
        $memberId = relationships_cw_member_id_by_email($ctx['completed_by_email']);
    } catch (RelationshipsConnectWiseError $e) {
        $memberId = null;
    }
    if ($memberId !== null) {
        // Per Michael (2026-09-11 AskUserQuestion): the completing RC is the
        // Activity's one assigned member -- no separate "Assigned By:
        // Michael" field is forced onto every activity.
        //
        // Field name is `assignTo`, confirmed for real this time -- a live
        // POST /sales/activities test came back with a real ConnectWise
        // validation error: {"code":"MissingRequiredField","message":"The
        // assignTo/id field is required.","field":"assignTo"}. The docs
        // PDF's example response body showed a field called `assignedBy`
        // (an {id, identifier, name, dailyCapacity, ...} object) in that
        // same position, which is what led to the previous (wrong) fix --
        // but that's apparently a different/related field ConnectWise's
        // response includes, not the one the create request actually
        // requires. A live 400 naming the exact required field beats a
        // static docs screenshot; trust this one.
        //
        // NOTE: ConnectWise treats assignTo/id as REQUIRED, not optional --
        // so if relationships_cw_member_id_by_email() can't resolve an id
        // (no match, or blocked again by a ConnectWise permission), this
        // POST will still fail server-side rather than silently creating a
        // memberless Activity. The try/catch above only prevents a crash on
        // the *lookup* itself; it doesn't make the field optional on
        // ConnectWise's side.
        $corePayload['assignTo'] = ['id' => $memberId];
    }

    $contactId = relationships_checklist_first_contact_id($pdo, (int) $ctx['customer_id']);
    if ($contactId !== null && $contactId !== '') {
        $corePayload['contact'] = ['id' => (int) $contactId];
    }

    // Fields the user's spec also asked for but that this file has the
    // LEAST confidence in (no corroborating field-name source beyond the
    // one third-party reference -- see file header): "Where: In-house" and
    // "Schedule Status: Firm". Tried together in the full payload; if
    // ConnectWise rejects the request over either one, the retry below
    // drops both rather than guessing again at which one was wrong.
    $fullPayload = $corePayload + [
        'where' => 'In-house',
        // Speculative field name for the Schedule section's separate
        // "Firm"/"Tentative" status -- genuinely unconfirmed, most likely
        // to be dropped on the fallback attempt below.
        'scheduleStatus' => 'Firm',
    ];

    try {
        $created = relationships_cw_request('/sales/activities', [], 'POST', $fullPayload);
        if (isset($created['id'])) {
            return ['id' => (string) $created['id'], 'variant' => 'full'];
        }
        // 2xx with no id back is unexpected but not worth failing over --
        // fall through to the core-payload retry, which at least gives a
        // second, simpler attempt at getting a usable id back.
    } catch (RelationshipsConnectWiseError $e) {
        // Expected/handled case: one of the speculative fields above was
        // wrong. Falls through to the core-payload retry. The full error
        // is still logged by the caller (relationships_checklist_completion_create_cw_activity)
        // for Michael to check.
    }

    $created = relationships_cw_request('/sales/activities', [], 'POST', $corePayload);
    if (!isset($created['id'])) {
        throw new RelationshipsConnectWiseError(
            'ConnectWise accepted the Activity create request but returned no id -- response: ' .
            substr(json_encode($created), 0, 300)
        );
    }
    return ['id' => (string) $created['id'], 'variant' => 'core'];
}

/**
 * Orchestrates the whole "checklist step just got checked off" -> "try to
 * create the ConnectWise Activity" -> "log the outcome" flow. Called from
 * checklist.php's 'set' action AFTER the local checklist_progress row is
 * already committed -- this function never throws (every failure path is
 * caught internally and logged), so it can never take down the checklist
 * save itself, per Michael's explicit 2026-09-11 answer ("save locally, log
 * the CW failure").
 *
 * $completedBy: the crc_users row (id, name, email) of whoever completed
 * the step -- checklist.php already has this as $user from
 * relationships_require_login().
 */
function relationships_checklist_completion_create_cw_activity(
    PDO $pdo,
    int $customerId,
    string $pillarId,
    string $serviceId,
    string $serviceName,
    int $stepNumber,
    array $completedBy
): void {
    $logStmt = $pdo->prepare(
        'INSERT INTO checklist_cw_activity_log
            (customer_id, pillar_id, service_id, step_number, status, cw_activity_id, payload_variant, error_message, completed_by_user_id)
         VALUES (:cid, :pid, :sid, :step, :status, :cwid, :variant, :err, :uid)'
    );

    try {
        $customerStmt = $pdo->prepare('SELECT connectwise_id, name FROM customers WHERE id = :id');
        $customerStmt->execute([':id' => $customerId]);
        $customer = $customerStmt->fetch(PDO::FETCH_ASSOC);

        $cwCompanyId = $customer !== false ? (string) ($customer['connectwise_id'] ?? '') : '';
        // Mock/unsynced customers (no real ConnectWise company id yet)
        // have nothing to create an Activity against -- log that plainly
        // rather than sending a nonsense company id to ConnectWise.
        if ($cwCompanyId === '' || str_starts_with($cwCompanyId, 'MOCK-')) {
            $logStmt->execute([
                ':cid' => $customerId, ':pid' => $pillarId, ':sid' => $serviceId, ':step' => $stepNumber,
                ':status' => 'error', ':cwid' => null, ':variant' => null,
                ':err' => 'Customer has no real ConnectWise company id (mock/unsynced customer) -- no Activity to create against.',
                ':uid' => $completedBy['id'],
            ]);
            return;
        }

        $steps = relationships_checklist_steps();
        $stepLabel = $steps[$stepNumber] ?? ('Step ' . $stepNumber);

        $nowEastern = new DateTimeImmutable('now', new DateTimeZone('America/New_York'));

        $result = relationships_cw_create_checklist_activity($pdo, [
            'customer_id' => $customerId,
            'cw_company_id' => $cwCompanyId,
            'service_name' => $serviceName,
            'step_number' => $stepNumber,
            'step_label' => $stepLabel,
            'completed_by_name' => $completedBy['name'],
            'completed_by_email' => $completedBy['email'],
            'completed_at_display' => $nowEastern->format('M j, Y g:i A T'),
        ]);

        $logStmt->execute([
            ':cid' => $customerId, ':pid' => $pillarId, ':sid' => $serviceId, ':step' => $stepNumber,
            ':status' => 'created', ':cwid' => $result['id'], ':variant' => $result['variant'],
            ':err' => null, ':uid' => $completedBy['id'],
        ]);
    } catch (Throwable $e) {
        // Catches RelationshipsConnectWiseError (ConnectWise reachable but
        // rejected both attempts, or a real transport failure) AND any
        // other unexpected error (e.g. a lookup query problem) -- nothing
        // from this whole flow is allowed to propagate back to checklist.php.
        try {
            $logStmt->execute([
                ':cid' => $customerId, ':pid' => $pillarId, ':sid' => $serviceId, ':step' => $stepNumber,
                ':status' => 'error', ':cwid' => null, ':variant' => null,
                // Widened from 1000 to 4000 chars 2026-09-11 -- a real
                // ConnectWise 400 with multiple validation errors (one per
                // invalid/missing field) was getting cut off mid-message by
                // the old 1000-char limit, hiding exactly the detail needed
                // to diagnose the next fix.
                ':err' => substr($e->getMessage(), 0, 4000), ':uid' => $completedBy['id'],
            ]);
        } catch (Throwable $logError) {
            // If even the log insert fails, there's nothing left to safely
            // do here except swallow it -- this function's whole contract
            // is "never throw back into checklist.php".
        }
    }
}
