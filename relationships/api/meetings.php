<?php
/**
 * relationships/api/meetings.php
 *
 * Customer Meeting Capture -- added 2026-09-15 per Michael. Meetings
 * logged against a customer (subject/date/notes, each generating a
 * ConnectWise Activity under that Company), to-do tasks logged under a
 * meeting (each ALSO generating its own ConnectWise Activity, at creation
 * -- see connectwise-meeting-activity.php), and the aggregation this
 * app's global to-do dashboard reads (per-rep open-task counts + the full
 * task list, for the Relationships front page's new right-hand panel).
 *
 * 2026-09-17 UPDATE, per Michael: a to-do's ConnectWise Activity now has a
 * real two-phase lifecycle instead of being created already-Closed --
 * OPEN at add_task, and only CLOSED when set_task_done actually marks it
 * done (relationships_cw_close_activity(), connectwise-activity-create.php).
 * A scheduled to-do's Activity is also dated to its due_date (not "now"),
 * marked Tentative if that date is still in the future -- see
 * connectwise-meeting-activity.php's relationships_cw_create_task_activity().
 *
 * Same "save locally first, unconditionally; then try ConnectWise; record
 * the outcome; never let a ConnectWise failure block or revert the local
 * save" posture as every other write in this integration (see
 * connectwise-activity-create.php's file header for the original
 * standing instruction).
 *
 * GET  /relationships/api/meetings.php?action=list&customer_id=1
 *   -> { ok: true, roster: [...7 names...], meetings: [
 *        { id, subject, meeting_date, notes, logged_by_name, created_at,
 *          cw_push: {status, error},
 *          tasks: [ { id, description, assigned_to_name, created_by_name,
 *                     created_at, due_date, completed_at, completed_by_name,
 *                     cw_push: {status, error} }, ... ] (oldest first)
 *        }, ... ] (newest meeting first) }
 *
 * POST /relationships/api/meetings.php?action=create_meeting
 *   { customer_id, subject, meeting_date: "YYYY-MM-DD", notes }
 *   -> { ok: true, meeting: {...} }
 *
 * POST /relationships/api/meetings.php?action=add_task
 *   { meeting_id, description, assigned_to_name, due_date: "YYYY-MM-DD"|null }
 *   assigned_to_name must exactly match one of relationships_todo_roster().
 *   due_date is OPTIONAL (added 2026-09-16, per Michael -- AskUserQuestion
 *   confirmed a to-do doesn't require one; a blank due_date just never
 *   shows on the new per-coordinator calendar).
 *   -> { ok: true, task: {...} }
 *
 * POST /relationships/api/meetings.php?action=set_task_done
 *   { task_id, completed: true|false }
 *   Saves the local completion unconditionally; when completed=true AND
 *   the task has a real pushed ConnectWise Activity, also PATCHes that
 *   SAME Activity to Closed (2026-09-17 -- see this file's header). Never
 *   creates a second Activity, and an unset (completed=false) never
 *   re-opens the Activity.
 *   -> { ok: true, task: {...}, formstack_url: "https://..."|null }
 *   formstack_url (added 2026-09-17, per Michael -- "every To-Do created
 *   and completed [should] create an entry in" CBT's Outgrow/Formstack
 *   activity-tracking form) is set ONLY when completed=true (per Michael's
 *   choice -- one form entry per to-do, filed at completion, not at
 *   creation too) -- see relationships_formstack_todo_url() below for why
 *   this is a pre-filled URL rather than a real backend submission: the
 *   form has a reCAPTCHA, and CBT has no Formstack API access, so app.js
 *   opens this URL in a new tab for the rep to review and click Submit
 *   themselves rather than silently posting on their behalf.
 *   Null when completed=false (un-checking a to-do never re-files it).
 *   Also currently null on the empty edge case where a customer has more
 *   than one synced ConnectWise contact -- see the same function -- but
 *   the URL still opens with the other fields filled in that case, just
 *   not Client/Prospect Contact.
 *
 *
 * GET  /relationships/api/meetings.php?action=global
 *   The master to-do dashboard's data source (Relationships front page,
 *   right column). -> { ok: true, roster: [...],
 *     counts: { "<name>": <open task count>, ... } (every roster name present, 0 if none),
 *     tasks: [ { id, description, assigned_to_name, customer_id, customer_name,
 *                meeting_id, meeting_subject, created_at, due_date, completed_at,
 *                completed_by_name }, ... ] (open tasks first, newest first
 *                within each group; completed tasks kept in the same list,
 *                not dropped, so the UI can render their strikethrough --
 *                capped at 300 rows total),
 *     risk_scan_alerts: [ { id, customer_id, customer_name, original_filename,
 *                uploaded_by_name, uploaded_at }, ... ] (added 2026-09-23,
 *                per Michael -- open (reviewed_at IS NULL) AND still
 *                UNASSIGNED (assigned_to_user_id IS NULL) risk_scans rows,
 *                see risk-scans.php's file header for why these are their
 *                own table rather than meeting_tasks rows: a real to-do
 *                needs a meeting + one of the 7 fixed roster names, so
 *                there's no way to represent a genuinely UNASSIGNED to-do
 *                there. Newest upload first, capped at 100. Rendered in
 *                the same Global To-Do Checklist panel as `tasks` above,
 *                but not folded into it or the roster counts.),
 *     risk_scan_assigned: [ { id, customer_id, customer_name,
 *                original_filename, uploaded_by_name, uploaded_at,
 *                assigned_to_name, assigned_at }, ... ] (added 2026-09-24,
 *                per Michael: "reps [need] the ability to assign the
 *                tasks to themselves. This will take it out of the
 *                general list and put it in their list... still show in
 *                the global list under the general list, but show who
 *                it's assigned to." Open (reviewed_at IS NULL) AND
 *                assigned (assigned_to_user_id IS NOT NULL) -- everything
 *                risk_scan_alerts excludes once claimed. Newest
 *                assignment first, capped at 100. See risk-scans.php's
 *                'assign'/'unassign' actions.) }
 *
 * GET  /relationships/api/meetings.php?action=rep_todos&assigned_to_name=Claire+Hayden
 *   Added 2026-09-16 per Michael: "coordinators [click] on their names in
 *   the global view... take them to a view of a list of their to-do's...
 *   scheduled to-dos [show] on a calendar." assigned_to_name must exactly
 *   match one of relationships_todo_roster(). -> { ok: true, roster: [...],
 *     rep_name: "...",
 *     open_tasks: [ { id, description, assigned_to_name, customer_id,
 *                customer_name, meeting_id, meeting_subject, created_at,
 *                due_date, completed_at: null, completed_by_name: null },
 *                ... ] (every open task, scheduled or not; due_date first
 *                by date, undated ones last),
 *     recent_completed_tasks: [ same shape, completed_at set ] (the 10
 *                most recently completed, newest first -- per Michael,
 *                AskUserQuestion: "show the last 10 finished to-do's only
 *                before they start disappearing"),
 *     risk_scans: [ { id, customer_id, customer_name, original_filename,
 *                uploaded_by_name, uploaded_at, assigned_at }, ... ]
 *                (added 2026-09-24 -- risk-scan alerts THIS name has
 *                claimed via risk-scans.php's 'assign' action, still
 *                open (not yet reviewed). Matched by name, not validated
 *                against the roster the way meeting_tasks assignment is
 *                -- a claim isn't restricted to the 7 fixed names, see
 *                db.php's migration comment, so this is included
 *                regardless of whether it happens to line up with one.
 *                Newest claim first.) }
 */

declare(strict_types=1);

require_once __DIR__ . '/_util.php';
require_once __DIR__ . '/territory-access.php';
require_once __DIR__ . '/prospecting-core.php';
require_once __DIR__ . '/connectwise-meeting-activity.php';
require_once __DIR__ . '/task-email.php';

$pdo = relationships_db();
$user = relationships_require_login($pdo);
$allowedTerritories = relationships_allowed_territories($pdo);

$action = $_GET['action'] ?? '';

/**
 * This customer's connectwise_id, or null for a mock/unsynced customer --
 * same "MOCK-%" convention every other file in this integration uses
 * (activity.php, outgrow.php, checklist.php's Activity trigger).
 */
function relationships_meetings_cw_id(PDO $pdo, int $customerId): ?string
{
    $stmt = $pdo->prepare('SELECT connectwise_id FROM customers WHERE id = :id');
    $stmt->execute([':id' => $customerId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $cwId = $row['connectwise_id'] ?? null;
    return ($cwId === null || $cwId === '' || str_starts_with((string) $cwId, 'MOCK-')) ? null : (string) $cwId;
}

/** crc_users (id, email) for a name, matched case-insensitively -- null if nobody has registered under that name yet. */
function relationships_meetings_user_by_name(PDO $pdo, string $name): ?array
{
    $stmt = $pdo->prepare('SELECT id, email FROM crc_users WHERE LOWER(name) = LOWER(:name) LIMIT 1');
    $stmt->execute([':name' => $name]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row !== false ? ['id' => (int) $row['id'], 'email' => (string) $row['email']] : null;
}

function relationships_meeting_task_row(array $r): array
{
    return [
        'id' => (int) $r['id'],
        'description' => $r['description'],
        'assigned_to_name' => $r['assigned_to_name'],
        'created_by_name' => $r['created_by_name'],
        'created_at' => $r['created_at'],
        'due_date' => $r['due_date'] ?? null,
        'completed_at' => $r['completed_at'],
        'completed_by_name' => $r['completed_by_name'],
        'cw_push' => ['status' => $r['cw_push_status'], 'error' => $r['cw_push_error']],
        // Added 2026-09-17 -- the separate close-on-done attempt (see
        // set_task_done below and relationships_cw_close_activity()),
        // same shape/convention as cw_push above.
        'cw_close' => ['status' => $r['cw_close_status'] ?? null, 'error' => $r['cw_close_error'] ?? null],
        'email' => ['status' => $r['email_status'] ?? null, 'error' => $r['email_error'] ?? null],
    ];
}

function relationships_general_todo_row(array $r): array
{
    return [
        'id' => (int) $r['id'],
        'description' => $r['description'],
        'assigned_to_name' => $r['assigned_to_name'],
        'created_by_name' => $r['created_by_name'],
        'created_at' => $r['created_at'],
        'due_date' => $r['due_date'] ?? null,
        'completed_at' => $r['completed_at'],
        'completed_by_name' => $r['completed_by_name'],
        'cw_push' => ['status' => $r['cw_push_status'], 'error' => $r['cw_push_error']],
        'cw_close' => ['status' => $r['cw_close_status'] ?? null, 'error' => $r['cw_close_error'] ?? null],
    ];
}

function relationships_meeting_row(array $m, array $tasks): array
{
    return [
        'id' => (int) $m['id'],
        'subject' => $m['subject'],
        'meeting_date' => $m['meeting_date'],
        'notes' => $m['notes'],
        'logged_by_name' => $m['logged_by_name'],
        'created_at' => $m['created_at'],
        // Added 2026-10-21 for the meeting-edit feature -- null/null on
        // every meeting until it's actually been edited once.
        'edited_at' => $m['edited_at'] ?? null,
        'edited_by_name' => $m['edited_by_name'] ?? null,
        'cw_push' => ['status' => $m['cw_push_status'], 'error' => $m['cw_push_error']],
        'cw_update' => ['status' => $m['cw_update_status'] ?? null, 'error' => $m['cw_update_error'] ?? null],
        'tasks' => $tasks,
    ];
}

/**
 * Shared row shape for the cross-customer task views ('global' and
 * 'rep_todos' below) -- id, description, who/what customer/meeting it's
 * against, created_at, due_date, and completion info. Added 2026-09-16
 * alongside due_date/rep_todos so both endpoints stay in sync.
 */
function relationships_cross_customer_task_row(array $r): array
{
    return [
        'id' => (int) $r['id'],
        'description' => $r['description'],
        'assigned_to_name' => $r['assigned_to_name'],
        'customer_id' => (int) $r['customer_id'],
        'customer_name' => $r['customer_name'],
        'meeting_id' => (int) $r['meeting_id'],
        'meeting_subject' => $r['meeting_subject'],
        'created_at' => $r['created_at'],
        'due_date' => $r['due_date'] ?? null,
        'completed_at' => $r['completed_at'],
        'completed_by_name' => $r['completed_by_name'],
    ];
}

/**
 * Builds a pre-filled link to CBT's Outgrow/Formstack call-activity form
 * (added 2026-09-17, per Michael) for a just-completed to-do. NOT a real
 * backend submission -- the form carries an invisible reCAPTCHA (confirmed
 * by inspecting the live form: a hidden g-recaptcha-response field plus a
 * google.com/recaptcha iframe), and CBT has no Formstack API access, so
 * there is no way to file this from the server without either defeating
 * that reCAPTCHA (not something this app will do) or a real API key. Per
 * Michael's explicit choice, the rep instead gets this URL opened in a new
 * tab, already filled in from what this app already knows, and clicks
 * Submit themselves -- same "assist, don't impersonate" posture as every
 * other place this app touches an outside system on a rep's behalf.
 *
 * Field IDs below (field190744403 etc.) were read directly off the live
 * form on 2026-09-17 -- Formstack has no semantic field-name API, only
 * these per-field numeric ids, confirmed stable for prefill via a test
 * query-string load of the form (every field populated correctly).
 * Confirmed fixed answers, per Michael's mapping in his request:
 *   - Client/Prospect Type -- always "Current Customer" (the form's own
 *     option text; Michael said "Current Client", closest real option).
 *   - Proactive Call -- always "0".
 *   - Call Type -- always "Pre Quote or Proposal F/U" (the form's own
 *     option text; Michael said "Pre Quote Proposal F/U").
 *   - Pivot to Sales or Next Conversation -- always "1".
 * Every other field on the form (Success of the Week, DYK, rDYK, % of
 * Business, Internal/External Referral Request, Hand-Written Note, the
 * four Sales Growth Amount fields) is left blank -- none of them are
 * required, and Michael's mapping didn't mention them.
 *
 * $contactName is null when this customer has zero or MORE THAN ONE
 * synced ConnectWise contact (per Michael's explicit choice, via
 * AskUserQuestion, over guessing which one) -- Client/Prospect Contact is
 * then left blank on the pre-filled form rather than guessed.
 */
function relationships_formstack_todo_url(array $user, string $customerName, ?string $contactName, string $taskDescription): string
{
    $fields = [
        'field190744403' => (string) $user['email'],
        'field190744404' => (string) $user['name'],
        'field190744405' => 'Current Customer',
        'field190744406' => $customerName,
        'field190744407' => $contactName ?? '',
        'field190744408' => $taskDescription,
        'field190744411' => '0',
        'field190744412' => 'Pre Quote or Proposal F/U',
        'field190744415' => '1',
    ];
    return 'https://outgrow.formstack.com/forms/oa_brittany_toler_code_blue?' . http_build_query($fields);
}

/**
 * The customer's synced ConnectWise contact name, ONLY when there's
 * exactly one on file (see relationships_formstack_todo_url()'s docblock
 * for why more/fewer than one resolves to null instead of guessing).
 */
function relationships_single_contact_name(PDO $pdo, int $customerId): ?string
{
    $stmt = $pdo->prepare('SELECT first_name, last_name FROM contacts WHERE customer_id = :cid');
    $stmt->execute([':cid' => $customerId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) !== 1) {
        return null;
    }
    $name = trim($rows[0]['first_name'] . ' ' . $rows[0]['last_name']);
    return $name !== '' ? $name : null;
}

if ($action === 'list') {
    $customerId = (int) ($_GET['customer_id'] ?? 0);
    if ($customerId <= 0) {
        relationships_respond(400, ['ok' => false, 'error' => 'Missing customer_id.']);
    }
    relationships_require_territory_scope($allowedTerritories, relationships_customer_territory($pdo, $customerId));

    $meetingStmt = $pdo->prepare(
        'SELECT id, subject, meeting_date, notes, logged_by_name, created_at, cw_push_status, cw_push_error,
                edited_at, edited_by_name, cw_update_status, cw_update_error
         FROM customer_meetings WHERE customer_id = :id ORDER BY id DESC'
    );
    $meetingStmt->execute([':id' => $customerId]);
    $meetingRows = $meetingStmt->fetchAll(PDO::FETCH_ASSOC);

    $taskStmt = $pdo->prepare(
        'SELECT id, meeting_id, description, assigned_to_name, created_by_name, created_at, due_date,
                completed_at, completed_by_name, cw_push_status, cw_push_error, cw_close_status, cw_close_error,
                email_status, email_error
         FROM meeting_tasks WHERE customer_id = :id ORDER BY id ASC'
    );
    $taskStmt->execute([':id' => $customerId]);
    $tasksByMeeting = [];
    foreach ($taskStmt->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $tasksByMeeting[(int) $t['meeting_id']][] = relationships_meeting_task_row($t);
    }

    $meetings = array_map(
        static fn (array $m): array => relationships_meeting_row($m, $tasksByMeeting[(int) $m['id']] ?? []),
        $meetingRows
    );

    relationships_respond(200, ['ok' => true, 'roster' => relationships_todo_roster(), 'meetings' => $meetings]);
}

// ---------------------------------------------------------------- todo_summary / todo_list
// General (customer-level, not meeting-tied) to-dos -- added 2026-10-15 per Michael's To-Do
// count card on each customer portal page (next to Service Tickets YTD). Separate table
// (general_todos, db.php) from meeting_tasks, merged only for DISPLAY here -- a customer's
// to-do drill-down shows both, tagged by source, but each keeps its own lifecycle (meeting
// to-dos: create + complete only; general to-dos: create, reassign, remove, complete).
if ($action === 'todo_summary') {
    // The card's badge color, computed server-side so app.js doesn't duplicate this logic:
    // white (0,0) = no open to-dos at all; green = open to-dos exist, none overdue (an UNDATED
    // to-do counts as not-yet-due -- AskUserQuestion 2026-10-15); red = at least one open,
    // dated to-do whose due_date has already passed.
    $customerId = (int) ($_GET['customer_id'] ?? 0);
    if ($customerId <= 0) {
        relationships_respond(400, ['ok' => false, 'error' => 'Missing customer_id.']);
    }
    relationships_require_territory_scope($allowedTerritories, relationships_customer_territory($pdo, $customerId));

    $openGeneralStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM general_todos WHERE customer_id = :id AND removed_at IS NULL AND completed_at IS NULL'
    );
    $openGeneralStmt->execute([':id' => $customerId]);
    $openGeneral = (int) $openGeneralStmt->fetchColumn();

    $openMeetingStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM meeting_tasks WHERE customer_id = :id AND completed_at IS NULL'
    );
    $openMeetingStmt->execute([':id' => $customerId]);
    $openMeeting = (int) $openMeetingStmt->fetchColumn();

    $today = (new DateTimeImmutable('today', new DateTimeZone('America/New_York')))->format('Y-m-d');
    $overdueGeneralStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM general_todos WHERE customer_id = :id AND removed_at IS NULL AND completed_at IS NULL AND due_date IS NOT NULL AND due_date < :today"
    );
    $overdueGeneralStmt->execute([':id' => $customerId, ':today' => $today]);
    $overdueMeetingStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM meeting_tasks WHERE customer_id = :id AND completed_at IS NULL AND due_date IS NOT NULL AND due_date < :today"
    );
    $overdueMeetingStmt->execute([':id' => $customerId, ':today' => $today]);

    relationships_respond(200, [
        'ok' => true,
        'open_count' => $openGeneral + $openMeeting,
        'overdue_count' => (int) $overdueGeneralStmt->fetchColumn() + (int) $overdueMeetingStmt->fetchColumn(),
    ]);
}

if ($action === 'todo_list') {
    // The drill-down's data source: every open to-do (both sources) plus the 10 most recently
    // completed (same "don't accumulate forever" cap rep_todos already uses below), merged and
    // sorted due-date-first (undated last), oldest-created first within the same date.
    $customerId = (int) ($_GET['customer_id'] ?? 0);
    if ($customerId <= 0) {
        relationships_respond(400, ['ok' => false, 'error' => 'Missing customer_id.']);
    }
    relationships_require_territory_scope($allowedTerritories, relationships_customer_territory($pdo, $customerId));

    $generalStmt = $pdo->prepare(
        'SELECT id, description, assigned_to_name, created_by_name, created_at, due_date, completed_at, completed_by_name,
                cw_push_status, cw_push_error, cw_close_status, cw_close_error
         FROM general_todos WHERE customer_id = :id AND removed_at IS NULL
         ORDER BY (completed_at IS NOT NULL) ASC, (due_date IS NULL) ASC, due_date ASC, created_at ASC'
    );
    $generalStmt->execute([':id' => $customerId]);
    $general = array_map(static function (array $r): array {
        $row = relationships_general_todo_row($r);
        $row['source'] = 'general';
        $row['meeting_subject'] = null;
        return $row;
    }, $generalStmt->fetchAll(PDO::FETCH_ASSOC));

    $meetingStmt = $pdo->prepare(
        'SELECT t.id, t.description, t.assigned_to_name, t.created_by_name, t.created_at, t.due_date,
                t.completed_at, t.completed_by_name, t.cw_push_status, t.cw_push_error, t.cw_close_status, t.cw_close_error,
                m.subject AS meeting_subject
         FROM meeting_tasks t JOIN customer_meetings m ON m.id = t.meeting_id
         WHERE t.customer_id = :id
         ORDER BY (t.completed_at IS NOT NULL) ASC, (t.due_date IS NULL) ASC, t.due_date ASC, t.created_at ASC'
    );
    $meetingStmt->execute([':id' => $customerId]);
    $meeting = array_map(static function (array $r): array {
        $row = relationships_meeting_task_row($r);
        $row['source'] = 'meeting';
        $row['meeting_subject'] = $r['meeting_subject'];
        return $row;
    }, $meetingStmt->fetchAll(PDO::FETCH_ASSOC));

    $open = array_values(array_filter(array_merge($general, $meeting), static fn (array $r): bool => $r['completed_at'] === null));
    $done = array_values(array_filter(array_merge($general, $meeting), static fn (array $r): bool => $r['completed_at'] !== null));
    usort($open, static fn (array $a, array $b): int =>
        ($a['due_date'] === null ? 1 : 0) <=> ($b['due_date'] === null ? 1 : 0)
            ?: strcmp((string) $a['due_date'], (string) $b['due_date'])
            ?: strcmp((string) $a['created_at'], (string) $b['created_at'])
    );
    usort($done, static fn (array $a, array $b): int => strcmp((string) $b['completed_at'], (string) $a['completed_at']));
    $done = array_slice($done, 0, 10);

    relationships_respond(200, ['ok' => true, 'roster' => relationships_todo_roster(), 'open_todos' => $open, 'recent_completed_todos' => $done]);
}

if ($action === 'global') {
    // Rep-based territory filtering (see territory-access.php) -- this is
    // a genuine cross-customer view (the Global To-Do Checklist), so per
    // Michael's "everywhere" decision a restricted rep's counts and task
    // list here only ever reflect tasks against their own customers.
    $territoryFilter = $allowedTerritories === null
        ? ['sql' => '', 'params' => []]
        : relationships_territory_filter_sql($allowedTerritories, 'c');

    $roster = relationships_todo_roster();
    $counts = array_fill_keys($roster, 0);
    $countStmt = $pdo->prepare(
        "SELECT t.assigned_to_name, COUNT(*) AS n
         FROM meeting_tasks t
         JOIN customers c ON c.id = t.customer_id
         WHERE t.completed_at IS NULL {$territoryFilter['sql']}
         GROUP BY t.assigned_to_name"
    );
    $countStmt->execute($territoryFilter['params']);
    foreach ($countStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        // Only tally names that are still on the current roster -- a task
        // assigned before a roster edit (there isn't one today, but this
        // keeps the summary from silently including a stray name) would
        // otherwise inflate a count with no matching header.
        if (array_key_exists($r['assigned_to_name'], $counts)) {
            $counts[$r['assigned_to_name']] = (int) $r['n'];
        }
    }

    $taskStmt = $pdo->prepare(
        "SELECT t.id, t.description, t.assigned_to_name, t.customer_id, c.name AS customer_name,
                t.meeting_id, m.subject AS meeting_subject, t.created_at, t.due_date, t.completed_at, t.completed_by_name
         FROM meeting_tasks t
         JOIN customer_meetings m ON m.id = t.meeting_id
         JOIN customers c ON c.id = t.customer_id
         WHERE 1=1 {$territoryFilter['sql']}
         ORDER BY (t.completed_at IS NULL) DESC, t.created_at DESC
         LIMIT 300"
    );
    $taskStmt->execute($territoryFilter['params']);
    $rows = $taskStmt->fetchAll(PDO::FETCH_ASSOC);

    $tasks = array_map('relationships_cross_customer_task_row', $rows);

    // Risk-scan upload alerts -- added 2026-09-23, see risk-scans.php's
    // file header and the docblock above. Same territory filter as the
    // task query above (a restricted rep only ever sees alerts for their
    // own customers), joined on customers the same way. Split on
    // assigned_to_name (not assigned_to_user_id) as of 2026-09-29 -- once
    // assignment could name any of the 7 roster members rather than
    // always the signed-in caller, assigned_to_user_id can legitimately
    // be NULL for an assigned scan (that roster member just hasn't
    // registered a Relationships login yet), which would have wrongly
    // sent it back into the unassigned pool below.
    $riskScanStmt = $pdo->prepare(
        "SELECT r.id, r.customer_id, c.name AS customer_name, r.original_filename, r.uploaded_by_name, r.uploaded_at
         FROM risk_scans r
         JOIN customers c ON c.id = r.customer_id
         WHERE r.reviewed_at IS NULL AND r.assigned_to_name IS NULL {$territoryFilter['sql']}
         ORDER BY r.uploaded_at DESC
         LIMIT 100"
    );
    $riskScanStmt->execute($territoryFilter['params']);
    $riskScanAlerts = array_map(static function (array $r): array {
        return [
            'id' => (int) $r['id'],
            'customer_id' => (int) $r['customer_id'],
            'customer_name' => $r['customer_name'],
            'original_filename' => $r['original_filename'],
            'uploaded_by_name' => $r['uploaded_by_name'],
            'uploaded_at' => $r['uploaded_at'],
        ];
    }, $riskScanStmt->fetchAll(PDO::FETCH_ASSOC));

    // Claimed but not yet reviewed -- added 2026-09-24, see the docblock
    // above. Same shape as risk_scan_alerts plus who claimed it.
    $riskScanAssignedStmt = $pdo->prepare(
        "SELECT r.id, r.customer_id, c.name AS customer_name, r.original_filename, r.uploaded_by_name, r.uploaded_at, r.assigned_to_name, r.assigned_at
         FROM risk_scans r
         JOIN customers c ON c.id = r.customer_id
         WHERE r.reviewed_at IS NULL AND r.assigned_to_name IS NOT NULL {$territoryFilter['sql']}
         ORDER BY r.assigned_at DESC
         LIMIT 100"
    );
    $riskScanAssignedStmt->execute($territoryFilter['params']);
    $riskScanAssigned = array_map(static function (array $r): array {
        return [
            'id' => (int) $r['id'],
            'customer_id' => (int) $r['customer_id'],
            'customer_name' => $r['customer_name'],
            'original_filename' => $r['original_filename'],
            'uploaded_by_name' => $r['uploaded_by_name'],
            'uploaded_at' => $r['uploaded_at'],
            'assigned_to_name' => $r['assigned_to_name'],
            'assigned_at' => $r['assigned_at'],
        ];
    }, $riskScanAssignedStmt->fetchAll(PDO::FETCH_ASSOC));

    // Prospects nearing (or past) their 90-day deadline -- warn only, per
    // Michael (2026-09-23): nothing changes in ConnectWise automatically;
    // the Sales Manager decides. Same territory filter as above; only
    // still-active claims on companies that are still prospects.
    $prospectStmt = $pdo->prepare(
        "SELECT pc.customer_id, c.name AS customer_name, pc.claimed_by_name, pc.deadline_at
         FROM prospect_claims pc
         JOIN customers c ON c.id = pc.customer_id
         WHERE pc.status = 'active' AND c.is_prospect_only = 1 {$territoryFilter['sql']}
         ORDER BY pc.deadline_at ASC
         LIMIT 100"
    );
    $prospectStmt->execute($territoryFilter['params']);
    $prospectAlerts = [];
    foreach ($prospectStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $daysLeft = relationships_prospect_days_left((string) $r['deadline_at']);
        if ($daysLeft <= 14) {
            $prospectAlerts[] = [
                'customer_id' => (int) $r['customer_id'],
                'customer_name' => $r['customer_name'],
                'claimed_by_name' => $r['claimed_by_name'],
                'deadline_at' => $r['deadline_at'],
                'days_left' => $daysLeft,
            ];
        }
    }

    relationships_respond(200, ['ok' => true, 'roster' => $roster, 'counts' => $counts, 'tasks' => $tasks, 'risk_scan_alerts' => $riskScanAlerts, 'risk_scan_assigned' => $riskScanAssigned, 'prospect_alerts' => $prospectAlerts]);
}

// ---------------------------------------------------------------- todo_overview
// Front-page "To-Do List" gauge drill-down -- added 2026-10-16, per Michael's
// request for a global card with three clickable numbers: Overdue (red), Due
// Today (yellow), and Future (green). The gauge's three counts themselves are
// computed in dashboard.php's 'overview' action (same request that builds the
// rest of the front page); this is the list BEHIND whichever number a rep
// clicks, fetched on demand. Unlike the 'global' action above (meeting_tasks
// only, grouped by rep), this merges BOTH to-do sources -- general_todos (the
// per-customer "To-Do List" card, 2026-10-15) and meeting_tasks -- same as
// todo_summary/todo_list already do per customer, just across every customer
// at once. An undated to-do is not-yet-due (same rule as the per-customer
// badge, AskUserQuestion 2026-10-15), so it falls into the 'future' bucket.
if ($action === 'todo_overview') {
    $bucket = (string) ($_GET['bucket'] ?? '');
    if (!in_array($bucket, ['overdue', 'due_today', 'future'], true)) {
        relationships_respond(400, ['ok' => false, 'error' => 'bucket must be overdue, due_today, or future.']);
    }
    $territoryFilter = $allowedTerritories === null
        ? ['sql' => '', 'params' => []]
        : relationships_territory_filter_sql($allowedTerritories, 'c');
    $today = (new DateTimeImmutable('today', new DateTimeZone('America/New_York')))->format('Y-m-d');
    $bucketClause = [
        'overdue' => 'AND t.due_date IS NOT NULL AND t.due_date < :today',
        'due_today' => 'AND t.due_date = :today',
        'future' => 'AND (t.due_date IS NULL OR t.due_date > :today)',
    ][$bucket];

    $generalStmt = $pdo->prepare(
        "SELECT t.id, t.description, t.assigned_to_name, t.customer_id, c.name AS customer_name, t.created_at, t.due_date
         FROM general_todos t
         JOIN customers c ON c.id = t.customer_id
         WHERE t.completed_at IS NULL AND t.removed_at IS NULL {$bucketClause} {$territoryFilter['sql']}
         ORDER BY (t.due_date IS NULL) ASC, t.due_date ASC, t.created_at ASC
         LIMIT 400"
    );
    $generalStmt->execute(array_merge([':today' => $today], $territoryFilter['params']));
    $todoOverviewGeneral = array_map(static function (array $r): array {
        return [
            'source' => 'general',
            'id' => (int) $r['id'],
            'description' => $r['description'],
            'assigned_to_name' => $r['assigned_to_name'],
            'customer_id' => (int) $r['customer_id'],
            'customer_name' => $r['customer_name'],
            'meeting_id' => null,
            'meeting_subject' => null,
            'created_at' => $r['created_at'],
            'due_date' => $r['due_date'] ?? null,
        ];
    }, $generalStmt->fetchAll(PDO::FETCH_ASSOC));

    $meetingStmt = $pdo->prepare(
        "SELECT t.id, t.description, t.assigned_to_name, t.customer_id, c.name AS customer_name,
                t.meeting_id, m.subject AS meeting_subject, t.created_at, t.due_date
         FROM meeting_tasks t
         JOIN customer_meetings m ON m.id = t.meeting_id
         JOIN customers c ON c.id = t.customer_id
         WHERE t.completed_at IS NULL {$bucketClause} {$territoryFilter['sql']}
         ORDER BY (t.due_date IS NULL) ASC, t.due_date ASC, t.created_at ASC
         LIMIT 400"
    );
    $meetingStmt->execute(array_merge([':today' => $today], $territoryFilter['params']));
    $todoOverviewMeeting = array_map(static function (array $r): array {
        return [
            'source' => 'meeting',
            'id' => (int) $r['id'],
            'description' => $r['description'],
            'assigned_to_name' => $r['assigned_to_name'],
            'customer_id' => (int) $r['customer_id'],
            'customer_name' => $r['customer_name'],
            'meeting_id' => (int) $r['meeting_id'],
            'meeting_subject' => $r['meeting_subject'],
            'created_at' => $r['created_at'],
            'due_date' => $r['due_date'] ?? null,
        ];
    }, $meetingStmt->fetchAll(PDO::FETCH_ASSOC));

    $todoOverviewItems = array_merge($todoOverviewGeneral, $todoOverviewMeeting);
    usort($todoOverviewItems, static fn (array $a, array $b): int =>
        ($a['due_date'] === null ? 1 : 0) <=> ($b['due_date'] === null ? 1 : 0)
            ?: strcmp((string) $a['due_date'], (string) $b['due_date'])
            ?: strcmp((string) $a['created_at'], (string) $b['created_at'])
    );
    $todoOverviewItems = array_slice($todoOverviewItems, 0, 500);

    relationships_respond(200, ['ok' => true, 'bucket' => $bucket, 'items' => $todoOverviewItems]);
}

if ($action === 'rep_todos') {
    // Per-coordinator to-do list + calendar (Michael, 2026-09-16): "click
    // on their names in the global view... take them to a view of a list
    // of their to-do's... scheduled to-dos [show] on a calendar." Same
    // territory scoping as 'global' above -- a restricted rep only ever
    // sees tasks against their own customers, whoever's name was clicked.
    $repName = trim((string) ($_GET['assigned_to_name'] ?? ''));
    $roster = relationships_todo_roster();
    if (!in_array($repName, $roster, true)) {
        relationships_respond(400, ['ok' => false, 'error' => 'Unknown coordinator name.']);
    }

    $territoryFilter = $allowedTerritories === null
        ? ['sql' => '', 'params' => []]
        : relationships_territory_filter_sql($allowedTerritories, 'c');

    // Every open task, scheduled or not -- due_date first (nulls last),
    // then oldest-created first within the same date so a rep works
    // through their backlog in a stable order.
    $openStmt = $pdo->prepare(
        "SELECT t.id, t.description, t.assigned_to_name, t.customer_id, c.name AS customer_name,
                t.meeting_id, m.subject AS meeting_subject, t.created_at, t.due_date, t.completed_at, t.completed_by_name
         FROM meeting_tasks t
         JOIN customer_meetings m ON m.id = t.meeting_id
         JOIN customers c ON c.id = t.customer_id
         WHERE t.assigned_to_name = :name AND t.completed_at IS NULL {$territoryFilter['sql']}
         ORDER BY (t.due_date IS NULL) ASC, t.due_date ASC, t.created_at ASC"
    );
    $openStmt->execute([':name' => $repName] + $territoryFilter['params']);
    $openTasks = array_map('relationships_cross_customer_task_row', $openStmt->fetchAll(PDO::FETCH_ASSOC));

    // The 10 most recently completed -- per Michael (AskUserQuestion,
    // 2026-09-16): "show the last 10 finished to-do's only before they
    // start disappearing," so the calendar/list don't accumulate every
    // completed task forever.
    $doneStmt = $pdo->prepare(
        "SELECT t.id, t.description, t.assigned_to_name, t.customer_id, c.name AS customer_name,
                t.meeting_id, m.subject AS meeting_subject, t.created_at, t.due_date, t.completed_at, t.completed_by_name
         FROM meeting_tasks t
         JOIN customer_meetings m ON m.id = t.meeting_id
         JOIN customers c ON c.id = t.customer_id
         WHERE t.assigned_to_name = :name AND t.completed_at IS NOT NULL {$territoryFilter['sql']}
         ORDER BY t.completed_at DESC
         LIMIT 10"
    );
    $doneStmt->execute([':name' => $repName] + $territoryFilter['params']);
    $recentCompleted = array_map('relationships_cross_customer_task_row', $doneStmt->fetchAll(PDO::FETCH_ASSOC));

    // This rep's claimed-but-not-reviewed risk scans -- added 2026-09-24,
    // see the docblock above. Matched by assigned_to_name, same territory
    // filter as everything else here.
    $repRiskScanStmt = $pdo->prepare(
        "SELECT r.id, r.customer_id, c.name AS customer_name, r.original_filename, r.uploaded_by_name, r.uploaded_at, r.assigned_at
         FROM risk_scans r
         JOIN customers c ON c.id = r.customer_id
         WHERE r.assigned_to_name = :name AND r.reviewed_at IS NULL {$territoryFilter['sql']}
         ORDER BY r.assigned_at DESC"
    );
    $repRiskScanStmt->execute([':name' => $repName] + $territoryFilter['params']);
    $repRiskScans = array_map(static function (array $r): array {
        return [
            'id' => (int) $r['id'],
            'customer_id' => (int) $r['customer_id'],
            'customer_name' => $r['customer_name'],
            'original_filename' => $r['original_filename'],
            'uploaded_by_name' => $r['uploaded_by_name'],
            'uploaded_at' => $r['uploaded_at'],
            'assigned_at' => $r['assigned_at'],
        ];
    }, $repRiskScanStmt->fetchAll(PDO::FETCH_ASSOC));

    relationships_respond(200, [
        'ok' => true,
        'roster' => $roster,
        'rep_name' => $repName,
        'open_tasks' => $openTasks,
        'recent_completed_tasks' => $recentCompleted,
        'risk_scans' => $repRiskScans,
    ]);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    relationships_respond(405, ['ok' => false, 'error' => 'Method not allowed.']);
}

if ($action === 'create_meeting') {
    $data = relationships_read_json_body();
    $customerId = (int) ($data['customer_id'] ?? 0);
    $subject = trim((string) ($data['subject'] ?? ''));
    $meetingDate = (string) ($data['meeting_date'] ?? '');
    $notes = trim((string) ($data['notes'] ?? ''));

    if ($customerId <= 0 || $subject === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $meetingDate)) {
        relationships_respond(400, ['ok' => false, 'error' => 'Missing/invalid customer_id, subject, or meeting_date (expected YYYY-MM-DD).']);
    }
    if (mb_strlen($subject) > 200) {
        relationships_respond(400, ['ok' => false, 'error' => 'Subject is too long (200 characters max).']);
    }

    $custStmt = $pdo->prepare('SELECT id, connectwise_id, name, territory_name FROM customers WHERE id = :id');
    $custStmt->execute([':id' => $customerId]);
    $customer = $custStmt->fetch(PDO::FETCH_ASSOC);
    if ($customer === false) {
        relationships_respond(404, ['ok' => false, 'error' => 'Customer not found.']);
    }
    relationships_require_territory_scope($allowedTerritories, $customer['territory_name']);

    // Local save first, unconditionally -- per this integration's standing
    // "save locally, log the ConnectWise failure" instruction.
    $insert = $pdo->prepare(
        'INSERT INTO customer_meetings (customer_id, subject, meeting_date, notes, logged_by_user_id, logged_by_name)
         VALUES (:cid, :subj, :mdate, :notes, :uid, :uname)'
    );
    $insert->execute([
        ':cid' => $customerId, ':subj' => $subject, ':mdate' => $meetingDate, ':notes' => $notes,
        ':uid' => $user['id'], ':uname' => $user['name'],
    ]);
    $meetingId = (int) $pdo->lastInsertId();

    $cwPush = ['status' => 'skipped', 'error' => null];
    $cwCompanyId = relationships_meetings_cw_id($pdo, $customerId);
    if ($cwCompanyId !== null) {
        try {
            $nowEastern = new DateTimeImmutable('now', new DateTimeZone('America/New_York'));
            $result = relationships_cw_create_meeting_activity($pdo, [
                'customer_id' => $customerId,
                'cw_company_id' => $cwCompanyId,
                'subject' => $subject,
                'notes' => $notes,
                'logged_by_name' => $user['name'],
                'logged_by_email' => $user['email'],
                'logged_at_display' => $nowEastern->format('M j, Y g:i A T'),
            ]);
            $cwPush = ['status' => 'pushed', 'error' => null];
            $pdo->prepare('UPDATE customer_meetings SET cw_activity_id = :aid, cw_push_status = :status, cw_push_error = NULL WHERE id = :id')
                ->execute([':aid' => $result['id'], ':status' => 'pushed', ':id' => $meetingId]);
        } catch (Throwable $e) {
            $cwPush = ['status' => 'error', 'error' => substr($e->getMessage(), 0, 4000)];
            $pdo->prepare('UPDATE customer_meetings SET cw_push_status = :status, cw_push_error = :err WHERE id = :id')
                ->execute([':status' => 'error', ':err' => $cwPush['error'], ':id' => $meetingId]);
        }
    } else {
        $pdo->prepare('UPDATE customer_meetings SET cw_push_status = :status WHERE id = :id')
            ->execute([':status' => 'skipped', ':id' => $meetingId]);
    }

    $meetingStmt = $pdo->prepare(
        'SELECT id, subject, meeting_date, notes, logged_by_name, created_at, cw_push_status, cw_push_error,
                edited_at, edited_by_name, cw_update_status, cw_update_error
         FROM customer_meetings WHERE id = :id'
    );
    $meetingStmt->execute([':id' => $meetingId]);
    $meeting = relationships_meeting_row($meetingStmt->fetch(PDO::FETCH_ASSOC), []);
    relationships_respond(200, ['ok' => true, 'meeting' => $meeting]);
}

// Added 2026-10-21 per Michael: "the ability to edit an existing meeting
// after it's been saved." Same shape as create_meeting (validation,
// territory scope, "save locally first") plus, per Michael's explicit
// choice via AskUserQuestion, a best-effort PATCH of the linked
// ConnectWise Activity's name/notes when one exists -- see
// relationships_cw_update_meeting_activity() in
// connectwise-meeting-activity.php. Only subject/meeting_date/notes are
// editable -- logged_by_name/created_at stay exactly as they were at
// creation (who logged it and when is a historical fact, not something
// an edit should rewrite); edited_at/edited_by_name record the most
// recent edit, not a full revision history (same "value + who/when" shape
// the pillar vendor notes feature already uses).
if ($action === 'update_meeting') {
    $data = relationships_read_json_body();
    $meetingId = (int) ($data['meeting_id'] ?? 0);
    $customerId = (int) ($data['customer_id'] ?? 0);
    $subject = trim((string) ($data['subject'] ?? ''));
    $meetingDate = (string) ($data['meeting_date'] ?? '');
    $notes = trim((string) ($data['notes'] ?? ''));

    if ($meetingId <= 0 || $customerId <= 0 || $subject === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $meetingDate)) {
        relationships_respond(400, ['ok' => false, 'error' => 'Missing/invalid meeting_id, customer_id, subject, or meeting_date (expected YYYY-MM-DD).']);
    }
    if (mb_strlen($subject) > 200) {
        relationships_respond(400, ['ok' => false, 'error' => 'Subject is too long (200 characters max).']);
    }

    $custStmt = $pdo->prepare('SELECT id, connectwise_id, name, territory_name FROM customers WHERE id = :id');
    $custStmt->execute([':id' => $customerId]);
    $customer = $custStmt->fetch(PDO::FETCH_ASSOC);
    if ($customer === false) {
        relationships_respond(404, ['ok' => false, 'error' => 'Customer not found.']);
    }
    relationships_require_territory_scope($allowedTerritories, $customer['territory_name']);

    // Scoped to customer_id too, not just id -- a meeting_id alone being
    // valid isn't enough; it has to actually belong to the customer this
    // request's territory check just cleared.
    $existingStmt = $pdo->prepare('SELECT id, cw_activity_id, cw_push_status FROM customer_meetings WHERE id = :id AND customer_id = :cid');
    $existingStmt->execute([':id' => $meetingId, ':cid' => $customerId]);
    $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);
    if ($existing === false) {
        relationships_respond(404, ['ok' => false, 'error' => 'Meeting not found.']);
    }

    // Local save first, unconditionally -- same "save locally, log the
    // ConnectWise failure" instruction as every other write here.
    $pdo->prepare(
        'UPDATE customer_meetings SET subject = :subj, meeting_date = :mdate, notes = :notes,
            edited_at = :eat, edited_by_name = :ename WHERE id = :id'
    )->execute([
        ':subj' => $subject, ':mdate' => $meetingDate, ':notes' => $notes,
        ':eat' => gmdate('c'), ':ename' => $user['name'], ':id' => $meetingId,
    ]);

    $cwUpdate = ['status' => 'skipped', 'error' => null];
    $activityId = $existing['cw_activity_id'];
    // Only attempt a ConnectWise update when a real Activity exists from
    // creation (cw_push_status === 'pushed') -- 'skipped'/'error' at
    // creation means there's nothing in ConnectWise to PATCH.
    if ($existing['cw_push_status'] === 'pushed' && is_string($activityId) && $activityId !== '') {
        try {
            $nowEastern = new DateTimeImmutable('now', new DateTimeZone('America/New_York'));
            relationships_cw_update_meeting_activity($activityId, [
                'subject' => $subject,
                'notes' => $notes,
                'edited_by_name' => $user['name'],
                'edited_at_display' => $nowEastern->format('M j, Y g:i A T'),
            ]);
            $cwUpdate = ['status' => 'pushed', 'error' => null];
        } catch (Throwable $e) {
            $cwUpdate = ['status' => 'error', 'error' => substr($e->getMessage(), 0, 4000)];
        }
        $pdo->prepare('UPDATE customer_meetings SET cw_update_status = :status, cw_update_error = :err WHERE id = :id')
            ->execute([':status' => $cwUpdate['status'], ':err' => $cwUpdate['error'], ':id' => $meetingId]);
    } else {
        $pdo->prepare('UPDATE customer_meetings SET cw_update_status = :status, cw_update_error = NULL WHERE id = :id')
            ->execute([':status' => 'skipped', ':id' => $meetingId]);
    }

    $meetingStmt = $pdo->prepare(
        'SELECT id, subject, meeting_date, notes, logged_by_name, created_at, cw_push_status, cw_push_error,
                edited_at, edited_by_name, cw_update_status, cw_update_error
         FROM customer_meetings WHERE id = :id'
    );
    $meetingStmt->execute([':id' => $meetingId]);
    $meeting = relationships_meeting_row($meetingStmt->fetch(PDO::FETCH_ASSOC), []);
    relationships_respond(200, ['ok' => true, 'meeting' => $meeting]);
}

if ($action === 'add_task') {
    $data = relationships_read_json_body();
    $meetingId = (int) ($data['meeting_id'] ?? 0);
    $description = trim((string) ($data['description'] ?? ''));
    $assignedToName = trim((string) ($data['assigned_to_name'] ?? ''));
    // Scheduled to-dos (Michael, 2026-09-16) -- OPTIONAL: an empty/missing
    // due_date is valid and just means this to-do never appears on the
    // new per-coordinator calendar (confirmed via AskUserQuestion).
    $dueDateRaw = trim((string) ($data['due_date'] ?? ''));
    $dueDate = null;
    if ($dueDateRaw !== '') {
        // DateTimeImmutable::createFromFormat() silently ROLLS OVER an
        // out-of-range date (e.g. "2026-13-40" parses as 2027-02-09
        // instead of failing) rather than returning false, so the regex
        // + createFromFormat()!==false check alone isn't enough --
        // re-format the parsed result and require it to match the input
        // exactly, catching the roll-over case too.
        $parsedDueDate = DateTimeImmutable::createFromFormat('Y-m-d', $dueDateRaw);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDateRaw) || $parsedDueDate === false || $parsedDueDate->format('Y-m-d') !== $dueDateRaw) {
            relationships_respond(400, ['ok' => false, 'error' => 'Invalid due date -- expected YYYY-MM-DD.']);
        }
        $dueDate = $dueDateRaw;
    }

    $roster = relationships_todo_roster();
    if ($meetingId <= 0 || $description === '' || !in_array($assignedToName, $roster, true)) {
        relationships_respond(400, ['ok' => false, 'error' => 'Missing/invalid meeting_id, description, or assigned_to_name (must be one of the roster names).']);
    }
    if (mb_strlen($description) > 500) {
        relationships_respond(400, ['ok' => false, 'error' => 'Task description is too long (500 characters max).']);
    }

    $meetingStmt = $pdo->prepare(
        'SELECT m.id, m.subject, m.customer_id, c.name AS customer_name, c.connectwise_id, c.territory_name
         FROM customer_meetings m JOIN customers c ON c.id = m.customer_id WHERE m.id = :id'
    );
    $meetingStmt->execute([':id' => $meetingId]);
    $meeting = $meetingStmt->fetch(PDO::FETCH_ASSOC);
    if ($meeting === false) {
        relationships_respond(404, ['ok' => false, 'error' => 'Meeting not found.']);
    }
    relationships_require_territory_scope($allowedTerritories, $meeting['territory_name']);
    $customerId = (int) $meeting['customer_id'];

    // crc_users lookup -- LOCAL bookkeeping only (assigned_to_user_id
    // below). Deliberately NOT used for the ConnectWise push anymore (see
    // relationships_todo_roster_cw_email() in catalog.php, 2026-09-16 bug
    // fix): it depends on that roster member having registered a
    // Relationships login, which isn't guaranteed, and a null email there
    // used to sink the whole ConnectWise Activity create (assignTo/id is
    // REQUIRED on this ConnectWise instance).
    $assignee = relationships_meetings_user_by_name($pdo, $assignedToName);

    // Local save first, unconditionally.
    $insert = $pdo->prepare(
        'INSERT INTO meeting_tasks (meeting_id, customer_id, description, assigned_to_user_id, assigned_to_name, created_by_user_id, created_by_name, due_date)
         VALUES (:mid, :cid, :desc, :auid, :aname, :cuid, :cname, :due)'
    );
    $insert->execute([
        ':mid' => $meetingId, ':cid' => $customerId, ':desc' => $description,
        ':auid' => $assignee['id'] ?? null, ':aname' => $assignedToName,
        ':cuid' => $user['id'], ':cname' => $user['name'], ':due' => $dueDate,
    ]);
    $taskId = (int) $pdo->lastInsertId();

    $cwCompanyId = relationships_meetings_cw_id($pdo, $customerId);
    if ($cwCompanyId !== null) {
        try {
            $nowEastern = new DateTimeImmutable('now', new DateTimeZone('America/New_York'));
            $result = relationships_cw_create_task_activity($pdo, [
                'customer_id' => $customerId,
                'cw_company_id' => $cwCompanyId,
                'description' => $description,
                'meeting_subject' => $meeting['subject'],
                'assigned_to_name' => $assignedToName,
                // The fixed roster's real ConnectWise office email
                // (Michael, chat, 2026-09-16) -- NOT $assignee['email'],
                // so this always resolves regardless of Relationships
                // login status. See relationships_todo_roster_cw_email()'s
                // docblock in catalog.php for the full bug-fix story.
                'assigned_to_email' => relationships_todo_roster_cw_email($assignedToName),
                'created_by_name' => $user['name'],
                'created_at_display' => $nowEastern->format('M j, Y g:i A T'),
                'due_date' => $dueDate,
            ]);
            $pdo->prepare('UPDATE meeting_tasks SET cw_activity_id = :aid, cw_push_status = :status, cw_push_error = NULL WHERE id = :id')
                ->execute([':aid' => $result['id'], ':status' => 'pushed', ':id' => $taskId]);
        } catch (Throwable $e) {
            $pdo->prepare('UPDATE meeting_tasks SET cw_push_status = :status, cw_push_error = :err WHERE id = :id')
                ->execute([':status' => 'error', ':err' => substr($e->getMessage(), 0, 4000), ':id' => $taskId]);
        }
    } else {
        $pdo->prepare('UPDATE meeting_tasks SET cw_push_status = :status WHERE id = :id')
            ->execute([':status' => 'skipped', ':id' => $taskId]);
    }

    // "We have a next step!" notification email (Michael, 2026-09-16) --
    // independent of the ConnectWise push above: attempted regardless of
    // whether the ConnectWise Activity succeeded, never blocks or reverts
    // the already-committed local task save, and its outcome is logged
    // the same way (email_status/email_error) rather than silently lost.
    // See task-email.php's file header for the recipient-resolution
    // rationale (the same roster->real-email map as the ConnectWise fix
    // above, not a crc_users login lookup).
    $taskEmail = relationships_todo_roster_cw_email($assignedToName);
    if ($taskEmail !== null) {
        try {
            $nowEastern = new DateTimeImmutable('now', new DateTimeZone('America/New_York'));
            relationships_send_task_email([
                'to_email' => $taskEmail,
                'created_by_name' => $user['name'],
                'assigned_to_name' => $assignedToName,
                'customer_name' => (string) $meeting['customer_name'],
                'todo_text' => $description,
                'created_at_display' => $nowEastern->format('M j, Y g:i A T'),
            ]);
            $pdo->prepare('UPDATE meeting_tasks SET email_status = :status, email_error = NULL WHERE id = :id')
                ->execute([':status' => 'sent', ':id' => $taskId]);
        } catch (Throwable $e) {
            error_log('[meetings:add_task email] ' . $e->getMessage());
            $pdo->prepare('UPDATE meeting_tasks SET email_status = :status, email_error = :err WHERE id = :id')
                ->execute([':status' => 'failed', ':err' => substr($e->getMessage(), 0, 4000), ':id' => $taskId]);
        }
    } else {
        // Shouldn't happen -- every roster name has a mapped email -- but
        // degrade the same way as an unresolved ConnectWise assignee
        // rather than assume this can never occur.
        $pdo->prepare('UPDATE meeting_tasks SET email_status = :status, email_error = :err WHERE id = :id')
            ->execute([':status' => 'skipped', ':err' => 'No ConnectWise/work email on file for "' . $assignedToName . '".', ':id' => $taskId]);
    }

    $taskStmt = $pdo->prepare(
        'SELECT id, description, assigned_to_name, created_by_name, created_at, due_date, completed_at, completed_by_name, cw_push_status, cw_push_error, email_status, email_error
         FROM meeting_tasks WHERE id = :id'
    );
    $taskStmt->execute([':id' => $taskId]);
    relationships_respond(200, ['ok' => true, 'task' => relationships_meeting_task_row($taskStmt->fetch(PDO::FETCH_ASSOC))]);
}

if ($action === 'set_task_done') {
    $data = relationships_read_json_body();
    $taskId = (int) ($data['task_id'] ?? 0);
    $completed = !empty($data['completed']);

    $taskStmt = $pdo->prepare(
        'SELECT t.id, t.customer_id, t.description, t.cw_activity_id, t.cw_push_status,
                c.name AS customer_name, c.territory_name
         FROM meeting_tasks t JOIN customers c ON c.id = t.customer_id
         WHERE t.id = :id'
    );
    $taskStmt->execute([':id' => $taskId]);
    $taskRow = $taskStmt->fetch(PDO::FETCH_ASSOC);
    if ($taskRow === false) {
        relationships_respond(404, ['ok' => false, 'error' => 'Task not found.']);
    }
    relationships_require_territory_scope($allowedTerritories, $taskRow['territory_name']);

    if ($completed) {
        $pdo->prepare("UPDATE meeting_tasks SET completed_at = datetime('now'), completed_by_user_id = :uid, completed_by_name = :uname WHERE id = :id")
            ->execute([':uid' => $user['id'], ':uname' => $user['name'], ':id' => $taskId]);

        // Close the SAME ConnectWise Activity created at add_task time
        // (added 2026-09-17, per Michael -- see
        // relationships_cw_close_activity() and this file's set_task_done
        // docblock above), rather than the old behavior of the Activity
        // already being Closed the moment it was created regardless of
        // whether the to-do was actually done. Only attempted when that
        // Activity really exists (cw_push_status === 'pushed' -- a
        // 'skipped'/'error' create left nothing to close). Never blocks or
        // reverts the local completion above -- same "save locally, log
        // the ConnectWise failure" standing instruction as every other
        // write in this integration.
        $cwActivityId = $taskRow['cw_activity_id'];
        if ($cwActivityId !== null && $cwActivityId !== '' && $taskRow['cw_push_status'] === 'pushed') {
            try {
                relationships_cw_close_activity($pdo, (string) $cwActivityId);
                $pdo->prepare('UPDATE meeting_tasks SET cw_close_status = :status, cw_close_error = NULL WHERE id = :id')
                    ->execute([':status' => 'closed', ':id' => $taskId]);
            } catch (Throwable $e) {
                $pdo->prepare('UPDATE meeting_tasks SET cw_close_status = :status, cw_close_error = :err WHERE id = :id')
                    ->execute([':status' => 'error', ':err' => substr($e->getMessage(), 0, 4000), ':id' => $taskId]);
            }
        }
    } else {
        $pdo->prepare('UPDATE meeting_tasks SET completed_at = NULL, completed_by_user_id = NULL, completed_by_name = NULL WHERE id = :id')
            ->execute([':id' => $taskId]);
    }

    // Outgrow/Formstack call-activity form link (added 2026-09-17, per
    // Michael) -- only on the completion path, never on an un-check, and
    // built from data already in hand above (no extra ConnectWise round-
    // trip; the contact lookup is a local synced-contacts table read).
    $formstackUrl = null;
    if ($completed) {
        $contactName = relationships_single_contact_name($pdo, (int) $taskRow['customer_id']);
        $formstackUrl = relationships_formstack_todo_url($user, (string) $taskRow['customer_name'], $contactName, (string) $taskRow['description']);
    }

    $taskStmt = $pdo->prepare(
        'SELECT id, description, assigned_to_name, created_by_name, created_at, due_date, completed_at, completed_by_name,
                cw_push_status, cw_push_error, cw_close_status, cw_close_error, email_status, email_error
         FROM meeting_tasks WHERE id = :id'
    );
    $taskStmt->execute([':id' => $taskId]);
    relationships_respond(200, [
        'ok' => true,
        'task' => relationships_meeting_task_row($taskStmt->fetch(PDO::FETCH_ASSOC)),
        'formstack_url' => $formstackUrl,
    ]);
}

// ---------------------------------------------------------------- add_general_todo
// Added 2026-10-15, per Michael's customer-portal To-Do card. Same create workflow as
// meeting_tasks' add_task (local save first, unconditionally; then try the SAME ConnectWise
// Activity create relationships_cw_create_task_activity() uses, just without a parent meeting --
// relationships_cw_create_general_todo_activity() in connectwise-meeting-activity.php) -- no
// notification email (meeting to-dos' "We have a next step!" email was a meeting-specific ask,
// not requested here).
if ($action === 'add_general_todo') {
    $data = relationships_read_json_body();
    $customerId = (int) ($data['customer_id'] ?? 0);
    $description = trim((string) ($data['description'] ?? ''));
    $assignedToName = trim((string) ($data['assigned_to_name'] ?? ''));
    $dueDateRaw = trim((string) ($data['due_date'] ?? ''));
    $dueDate = null;
    if ($dueDateRaw !== '') {
        $parsedDueDate = DateTimeImmutable::createFromFormat('Y-m-d', $dueDateRaw);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDateRaw) || $parsedDueDate === false || $parsedDueDate->format('Y-m-d') !== $dueDateRaw) {
            relationships_respond(400, ['ok' => false, 'error' => 'Invalid due date -- expected YYYY-MM-DD.']);
        }
        $dueDate = $dueDateRaw;
    }

    $roster = relationships_todo_roster();
    if ($customerId <= 0 || $description === '' || !in_array($assignedToName, $roster, true)) {
        relationships_respond(400, ['ok' => false, 'error' => 'Missing/invalid customer_id, description, or assigned_to_name (must be one of the roster names).']);
    }
    if (mb_strlen($description) > 500) {
        relationships_respond(400, ['ok' => false, 'error' => 'To-do description is too long (500 characters max).']);
    }

    $custStmt = $pdo->prepare('SELECT id, name, territory_name FROM customers WHERE id = :id');
    $custStmt->execute([':id' => $customerId]);
    $customer = $custStmt->fetch(PDO::FETCH_ASSOC);
    if ($customer === false) {
        relationships_respond(404, ['ok' => false, 'error' => 'Customer not found.']);
    }
    relationships_require_territory_scope($allowedTerritories, $customer['territory_name']);

    $assignee = relationships_meetings_user_by_name($pdo, $assignedToName);

    $insert = $pdo->prepare(
        'INSERT INTO general_todos (customer_id, description, assigned_to_user_id, assigned_to_name, created_by_user_id, created_by_name, due_date)
         VALUES (:cid, :desc, :auid, :aname, :cuid, :cname, :due)'
    );
    $insert->execute([
        ':cid' => $customerId, ':desc' => $description,
        ':auid' => $assignee['id'] ?? null, ':aname' => $assignedToName,
        ':cuid' => $user['id'], ':cname' => $user['name'], ':due' => $dueDate,
    ]);
    $todoId = (int) $pdo->lastInsertId();

    $cwCompanyId = relationships_meetings_cw_id($pdo, $customerId);
    if ($cwCompanyId !== null) {
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
                'due_date' => $dueDate,
            ]);
            $pdo->prepare('UPDATE general_todos SET cw_activity_id = :aid, cw_push_status = :status, cw_push_error = NULL WHERE id = :id')
                ->execute([':aid' => $result['id'], ':status' => 'pushed', ':id' => $todoId]);
        } catch (Throwable $e) {
            $pdo->prepare('UPDATE general_todos SET cw_push_status = :status, cw_push_error = :err WHERE id = :id')
                ->execute([':status' => 'error', ':err' => substr($e->getMessage(), 0, 4000), ':id' => $todoId]);
        }
    } else {
        $pdo->prepare('UPDATE general_todos SET cw_push_status = :status WHERE id = :id')
            ->execute([':status' => 'skipped', ':id' => $todoId]);
    }

    $todoStmt = $pdo->prepare(
        'SELECT id, description, assigned_to_name, created_by_name, created_at, due_date, completed_at, completed_by_name, cw_push_status, cw_push_error
         FROM general_todos WHERE id = :id'
    );
    $todoStmt->execute([':id' => $todoId]);
    $row = relationships_general_todo_row($todoStmt->fetch(PDO::FETCH_ASSOC));
    $row['source'] = 'general';
    $row['meeting_subject'] = null;
    relationships_respond(200, ['ok' => true, 'todo' => $row]);
}

// ---------------------------------------------------------------- reassign_general_todo
// Local-only -- per Michael's own scoping ("the same workflow as meeting to-do's where the
// create and close activities in CWM"), reassignment never touches the ConnectWise Activity
// already created (if any); it stays assigned to whoever it was first pushed to. Meeting to-dos
// are NOT reassignable (never were) -- this only ever targets general_todos.
if ($action === 'reassign_general_todo') {
    $data = relationships_read_json_body();
    $todoId = (int) ($data['todo_id'] ?? 0);
    $assignedToName = trim((string) ($data['assigned_to_name'] ?? ''));
    $roster = relationships_todo_roster();
    if ($todoId <= 0 || !in_array($assignedToName, $roster, true)) {
        relationships_respond(400, ['ok' => false, 'error' => 'Missing/invalid todo_id or assigned_to_name.']);
    }

    $todoStmt = $pdo->prepare(
        'SELECT g.id, c.territory_name FROM general_todos g JOIN customers c ON c.id = g.customer_id
         WHERE g.id = :id AND g.removed_at IS NULL'
    );
    $todoStmt->execute([':id' => $todoId]);
    $todoRow = $todoStmt->fetch(PDO::FETCH_ASSOC);
    if ($todoRow === false) {
        relationships_respond(404, ['ok' => false, 'error' => 'To-do not found.']);
    }
    relationships_require_territory_scope($allowedTerritories, $todoRow['territory_name']);

    $assignee = relationships_meetings_user_by_name($pdo, $assignedToName);
    $pdo->prepare('UPDATE general_todos SET assigned_to_name = :aname, assigned_to_user_id = :auid WHERE id = :id')
        ->execute([':aname' => $assignedToName, ':auid' => $assignee['id'] ?? null, ':id' => $todoId]);

    $outStmt = $pdo->prepare(
        'SELECT id, description, assigned_to_name, created_by_name, created_at, due_date, completed_at, completed_by_name, cw_push_status, cw_push_error, cw_close_status, cw_close_error
         FROM general_todos WHERE id = :id'
    );
    $outStmt->execute([':id' => $todoId]);
    $row = relationships_general_todo_row($outStmt->fetch(PDO::FETCH_ASSOC));
    $row['source'] = 'general';
    relationships_respond(200, ['ok' => true, 'todo' => $row]);
}

// ---------------------------------------------------------------- remove_general_todo
// Soft-delete (AskUserQuestion, 2026-10-15) -- the row stays for history, just excluded from
// every list/summary query above. If it already created a live ConnectWise Activity
// (cw_push_status === 'pushed') and isn't already completed/closed, that Activity is closed too
// (same relationships_cw_close_activity() call set_general_todo_done/set_task_done use) so
// nothing is left sitting open in ConnectWise just because it was removed here. Meeting to-dos
// have no remove action -- this only ever targets general_todos.
if ($action === 'remove_general_todo') {
    $data = relationships_read_json_body();
    $todoId = (int) ($data['todo_id'] ?? 0);
    if ($todoId <= 0) {
        relationships_respond(400, ['ok' => false, 'error' => 'Missing todo_id.']);
    }

    $todoStmt = $pdo->prepare(
        'SELECT g.id, g.cw_activity_id, g.cw_push_status, g.completed_at, c.territory_name
         FROM general_todos g JOIN customers c ON c.id = g.customer_id
         WHERE g.id = :id AND g.removed_at IS NULL'
    );
    $todoStmt->execute([':id' => $todoId]);
    $todoRow = $todoStmt->fetch(PDO::FETCH_ASSOC);
    if ($todoRow === false) {
        relationships_respond(404, ['ok' => false, 'error' => 'To-do not found (or already removed).']);
    }
    relationships_require_territory_scope($allowedTerritories, $todoRow['territory_name']);

    $pdo->prepare("UPDATE general_todos SET removed_at = datetime('now'), removed_by_user_id = :uid, removed_by_name = :uname WHERE id = :id")
        ->execute([':uid' => $user['id'], ':uname' => $user['name'], ':id' => $todoId]);

    $cwActivityId = $todoRow['cw_activity_id'];
    if ($todoRow['completed_at'] === null && $cwActivityId !== null && $cwActivityId !== '' && $todoRow['cw_push_status'] === 'pushed') {
        try {
            relationships_cw_close_activity($pdo, (string) $cwActivityId);
            $pdo->prepare('UPDATE general_todos SET cw_close_status = :status, cw_close_error = NULL WHERE id = :id')
                ->execute([':status' => 'closed', ':id' => $todoId]);
        } catch (Throwable $e) {
            $pdo->prepare('UPDATE general_todos SET cw_close_status = :status, cw_close_error = :err WHERE id = :id')
                ->execute([':status' => 'error', ':err' => substr($e->getMessage(), 0, 4000), ':id' => $todoId]);
        }
    }

    relationships_respond(200, ['ok' => true]);
}

// ---------------------------------------------------------------- set_general_todo_done
// Same completion workflow as meeting_tasks' set_task_done (local save unconditionally, then
// close the SAME ConnectWise Activity created at add_general_todo time, never reverted on a
// ConnectWise failure). This only ever targets general_todos -- a meeting-sourced row in the
// merged drill-down calls the EXISTING set_task_done action instead (same table the meeting menu
// reads), which is exactly why checking it off there closes it in the meeting menu too: there is
// only one row.
if ($action === 'set_general_todo_done') {
    $data = relationships_read_json_body();
    $todoId = (int) ($data['todo_id'] ?? 0);
    $completed = !empty($data['completed']);

    $todoStmt = $pdo->prepare(
        'SELECT g.id, g.cw_activity_id, g.cw_push_status, c.territory_name
         FROM general_todos g JOIN customers c ON c.id = g.customer_id
         WHERE g.id = :id AND g.removed_at IS NULL'
    );
    $todoStmt->execute([':id' => $todoId]);
    $todoRow = $todoStmt->fetch(PDO::FETCH_ASSOC);
    if ($todoRow === false) {
        relationships_respond(404, ['ok' => false, 'error' => 'To-do not found.']);
    }
    relationships_require_territory_scope($allowedTerritories, $todoRow['territory_name']);

    if ($completed) {
        $pdo->prepare("UPDATE general_todos SET completed_at = datetime('now'), completed_by_user_id = :uid, completed_by_name = :uname WHERE id = :id")
            ->execute([':uid' => $user['id'], ':uname' => $user['name'], ':id' => $todoId]);

        $cwActivityId = $todoRow['cw_activity_id'];
        if ($cwActivityId !== null && $cwActivityId !== '' && $todoRow['cw_push_status'] === 'pushed') {
            try {
                relationships_cw_close_activity($pdo, (string) $cwActivityId);
                $pdo->prepare('UPDATE general_todos SET cw_close_status = :status, cw_close_error = NULL WHERE id = :id')
                    ->execute([':status' => 'closed', ':id' => $todoId]);
            } catch (Throwable $e) {
                $pdo->prepare('UPDATE general_todos SET cw_close_status = :status, cw_close_error = :err WHERE id = :id')
                    ->execute([':status' => 'error', ':err' => substr($e->getMessage(), 0, 4000), ':id' => $todoId]);
            }
        }
    } else {
        $pdo->prepare('UPDATE general_todos SET completed_at = NULL, completed_by_user_id = NULL, completed_by_name = NULL WHERE id = :id')
            ->execute([':id' => $todoId]);
    }

    $outStmt = $pdo->prepare(
        'SELECT id, description, assigned_to_name, created_by_name, created_at, due_date, completed_at, completed_by_name, cw_push_status, cw_push_error, cw_close_status, cw_close_error
         FROM general_todos WHERE id = :id'
    );
    $outStmt->execute([':id' => $todoId]);
    $row = relationships_general_todo_row($outStmt->fetch(PDO::FETCH_ASSOC));
    $row['source'] = 'general';
    relationships_respond(200, ['ok' => true, 'todo' => $row]);
}

relationships_respond(400, ['ok' => false, 'error' => 'Unknown action.']);
