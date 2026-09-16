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
 *                     created_at, completed_at, completed_by_name,
 *                     cw_push: {status, error} }, ... ] (oldest first)
 *        }, ... ] (newest meeting first) }
 *
 * POST /relationships/api/meetings.php?action=create_meeting
 *   { customer_id, subject, meeting_date: "YYYY-MM-DD", notes }
 *   -> { ok: true, meeting: {...} }
 *
 * POST /relationships/api/meetings.php?action=add_task
 *   { meeting_id, description, assigned_to_name }
 *   assigned_to_name must exactly match one of relationships_todo_roster().
 *   -> { ok: true, task: {...} }
 *
 * POST /relationships/api/meetings.php?action=set_task_done
 *   { task_id, completed: true|false }
 *   Local-only -- no second ConnectWise push on completion (confirmed via
 *   AskUserQuestion 2026-09-15: the task's Activity fires once, at
 *   creation).
 *   -> { ok: true, task: {...} }
 *
 * GET  /relationships/api/meetings.php?action=global
 *   The master to-do dashboard's data source (Relationships front page,
 *   right column). -> { ok: true, roster: [...],
 *     counts: { "<name>": <open task count>, ... } (every roster name present, 0 if none),
 *     tasks: [ { id, description, assigned_to_name, customer_id, customer_name,
 *                meeting_id, meeting_subject, created_at, completed_at,
 *                completed_by_name }, ... ] (open tasks first, newest first
 *                within each group; completed tasks kept in the same list,
 *                not dropped, so the UI can render their strikethrough --
 *                capped at 300 rows total) }
 */

declare(strict_types=1);

require_once __DIR__ . '/_util.php';
require_once __DIR__ . '/territory-access.php';
require_once __DIR__ . '/connectwise-meeting-activity.php';

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
        'completed_at' => $r['completed_at'],
        'completed_by_name' => $r['completed_by_name'],
        'cw_push' => ['status' => $r['cw_push_status'], 'error' => $r['cw_push_error']],
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
        'cw_push' => ['status' => $m['cw_push_status'], 'error' => $m['cw_push_error']],
        'tasks' => $tasks,
    ];
}

if ($action === 'list') {
    $customerId = (int) ($_GET['customer_id'] ?? 0);
    if ($customerId <= 0) {
        relationships_respond(400, ['ok' => false, 'error' => 'Missing customer_id.']);
    }
    relationships_require_territory_scope($allowedTerritories, relationships_customer_territory($pdo, $customerId));

    $meetingStmt = $pdo->prepare(
        'SELECT id, subject, meeting_date, notes, logged_by_name, created_at, cw_push_status, cw_push_error
         FROM customer_meetings WHERE customer_id = :id ORDER BY id DESC'
    );
    $meetingStmt->execute([':id' => $customerId]);
    $meetingRows = $meetingStmt->fetchAll(PDO::FETCH_ASSOC);

    $taskStmt = $pdo->prepare(
        'SELECT id, meeting_id, description, assigned_to_name, created_by_name, created_at,
                completed_at, completed_by_name, cw_push_status, cw_push_error
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
                t.meeting_id, m.subject AS meeting_subject, t.created_at, t.completed_at, t.completed_by_name
         FROM meeting_tasks t
         JOIN customer_meetings m ON m.id = t.meeting_id
         JOIN customers c ON c.id = t.customer_id
         WHERE 1=1 {$territoryFilter['sql']}
         ORDER BY (t.completed_at IS NULL) DESC, t.created_at DESC
         LIMIT 300"
    );
    $taskStmt->execute($territoryFilter['params']);
    $rows = $taskStmt->fetchAll(PDO::FETCH_ASSOC);

    $tasks = array_map(static function (array $r): array {
        return [
            'id' => (int) $r['id'],
            'description' => $r['description'],
            'assigned_to_name' => $r['assigned_to_name'],
            'customer_id' => (int) $r['customer_id'],
            'customer_name' => $r['customer_name'],
            'meeting_id' => (int) $r['meeting_id'],
            'meeting_subject' => $r['meeting_subject'],
            'created_at' => $r['created_at'],
            'completed_at' => $r['completed_at'],
            'completed_by_name' => $r['completed_by_name'],
        ];
    }, $rows);

    relationships_respond(200, ['ok' => true, 'roster' => $roster, 'counts' => $counts, 'tasks' => $tasks]);
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
        'SELECT id, subject, meeting_date, notes, logged_by_name, created_at, cw_push_status, cw_push_error
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

    $roster = relationships_todo_roster();
    if ($meetingId <= 0 || $description === '' || !in_array($assignedToName, $roster, true)) {
        relationships_respond(400, ['ok' => false, 'error' => 'Missing/invalid meeting_id, description, or assigned_to_name (must be one of the roster names).']);
    }
    if (mb_strlen($description) > 500) {
        relationships_respond(400, ['ok' => false, 'error' => 'Task description is too long (500 characters max).']);
    }

    $meetingStmt = $pdo->prepare(
        'SELECT m.id, m.subject, m.customer_id, c.connectwise_id, c.territory_name
         FROM customer_meetings m JOIN customers c ON c.id = m.customer_id WHERE m.id = :id'
    );
    $meetingStmt->execute([':id' => $meetingId]);
    $meeting = $meetingStmt->fetch(PDO::FETCH_ASSOC);
    if ($meeting === false) {
        relationships_respond(404, ['ok' => false, 'error' => 'Meeting not found.']);
    }
    relationships_require_territory_scope($allowedTerritories, $meeting['territory_name']);
    $customerId = (int) $meeting['customer_id'];

    $assignee = relationships_meetings_user_by_name($pdo, $assignedToName);

    // Local save first, unconditionally.
    $insert = $pdo->prepare(
        'INSERT INTO meeting_tasks (meeting_id, customer_id, description, assigned_to_user_id, assigned_to_name, created_by_user_id, created_by_name)
         VALUES (:mid, :cid, :desc, :auid, :aname, :cuid, :cname)'
    );
    $insert->execute([
        ':mid' => $meetingId, ':cid' => $customerId, ':desc' => $description,
        ':auid' => $assignee['id'] ?? null, ':aname' => $assignedToName,
        ':cuid' => $user['id'], ':cname' => $user['name'],
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
                'assigned_to_email' => $assignee['email'] ?? null,
                'created_by_name' => $user['name'],
                'created_at_display' => $nowEastern->format('M j, Y g:i A T'),
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

    $taskStmt = $pdo->prepare(
        'SELECT id, description, assigned_to_name, created_by_name, created_at, completed_at, completed_by_name, cw_push_status, cw_push_error
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
        'SELECT t.id, c.territory_name
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
    } else {
        $pdo->prepare('UPDATE meeting_tasks SET completed_at = NULL, completed_by_user_id = NULL, completed_by_name = NULL WHERE id = :id')
            ->execute([':id' => $taskId]);
    }

    $taskStmt = $pdo->prepare(
        'SELECT id, description, assigned_to_name, created_by_name, created_at, completed_at, completed_by_name, cw_push_status, cw_push_error
         FROM meeting_tasks WHERE id = :id'
    );
    $taskStmt->execute([':id' => $taskId]);
    relationships_respond(200, ['ok' => true, 'task' => relationships_meeting_task_row($taskStmt->fetch(PDO::FETCH_ASSOC))]);
}

relationships_respond(400, ['ok' => false, 'error' => 'Unknown action.']);
