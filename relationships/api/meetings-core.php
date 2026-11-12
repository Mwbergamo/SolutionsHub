<?php
declare(strict_types=1);

/**
 * relationships/api/meetings-core.php
 *
 * Added 2026-11-12, extracted out of meetings.php for the same reason as
 * documents-core.php (see that file's doc comment): the Outlook add-in
 * endpoint (relationships/api/outlook-addin.php) needs to create a
 * meeting and add a task WITHOUT an HTTP round trip through
 * meetings.php?action=create_meeting/add_task -- and meetings.php itself
 * can't just be require_once'd for that, because it has top-level
 * dispatch code (relationships_require_login(), reading $_GET['action'],
 * and a catch-all `relationships_respond(400, ['error'=>'Unknown
 * action.'])` at the very end) that would run and respond/exit on its
 * own the moment it's loaded. This file defines FUNCTIONS ONLY, no
 * top-level execution; both meetings.php and outlook-addin.php require
 * it.
 *
 * relationships_meetings_create() and relationships_meetings_add_task()
 * are the exact logic meetings.php's create_meeting/add_task actions
 * always ran (local save first, unconditionally; then try the
 * ConnectWise Activity; record the outcome; never block/revert on a
 * ConnectWise failure) -- meetings.php's own actions are now thin
 * wrappers that just read the JSON body and call these. The only new
 * behavior is two optional fields neither action took before (added for
 * the Outlook add-in's Create-a-Meeting/Create-a-To-Do actions -- see
 * claude/relationships-outlook-addin-plan.md):
 *   - create_meeting: meeting_time (a clock time, nothing in this table
 *     has ever carried one before) and source_email_document_id (the
 *     Document row for the email that spawned this meeting, if any).
 *   - add_task: attached_document_id (the Document row for the email a
 *     to-do was created from, if any).
 * Both are optional/nullable and ignored entirely when absent, so every
 * existing caller (the Relationships web app's own meeting/task forms)
 * behaves exactly as before.
 */

require_once __DIR__ . '/_util.php';
require_once __DIR__ . '/territory-access.php';
require_once __DIR__ . '/connectwise-meeting-activity.php';
require_once __DIR__ . '/task-email.php';

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
        // Added 2026-11-12 for the Outlook add-in's Create-a-To-Do action --
        // the Document row for the email this to-do was created from, if
        // any (relationships/api/documents.php). Null for every task
        // created the normal way (the web app's own add_task form has no
        // such concept).
        'attached_document_id' => isset($r['attached_document_id']) ? (int) $r['attached_document_id'] : null,
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
        // Added 2026-11-12 for the Outlook add-in's Create-a-Meeting
        // action -- a clock time alongside the date. Null for every
        // meeting logged the normal way (the web app's own form has
        // never collected one).
        'meeting_time' => $m['meeting_time'] ?? null,
        'notes' => $m['notes'],
        'logged_by_name' => $m['logged_by_name'],
        'created_at' => $m['created_at'],
        // Added 2026-10-21 for the meeting-edit feature -- null/null on
        // every meeting until it's actually been edited once.
        'edited_at' => $m['edited_at'] ?? null,
        'edited_by_name' => $m['edited_by_name'] ?? null,
        'cw_push' => ['status' => $m['cw_push_status'], 'error' => $m['cw_push_error']],
        'cw_update' => ['status' => $m['cw_update_status'] ?? null, 'error' => $m['cw_update_error'] ?? null],
        // Added 2026-11-12 -- the Document row for the email this
        // meeting was created from, if any (add-in Create-a-Meeting).
        'source_email_document_id' => isset($m['source_email_document_id']) ? (int) $m['source_email_document_id'] : null,
        'tasks' => $tasks,
    ];
}

/**
 * Finds this customer's existing "Email To-Dos" placeholder meeting
 * (customers.email_todos_meeting_id), or mints one if there isn't one
 * yet. Used ONLY by the Outlook add-in's Create-a-To-Do action --
 * meeting_tasks.meeting_id is NOT NULL, so a to-do created straight from
 * an email (with no meeting the rep is actually logging) still needs
 * *some* parent row, without inventing a second real "Create a Meeting"
 * every time. The placeholder is deliberately invisible: no ConnectWise
 * Activity is created for it (cw_push_status is written straight to
 * 'skipped', never attempted) -- the rep chose "Create a To-Do," not
 * "Create a Meeting," so nothing should show up in ConnectWise claiming
 * otherwise. The to-dos filed under it still get their own normal
 * ConnectWise Activities exactly as any other to-do does; only the
 * container meeting is silent. See
 * claude/relationships-outlook-addin-plan.md for the full rationale.
 */
function relationships_meetings_email_todos_meeting_id(PDO $pdo, int $customerId, array $user): int
{
    $custStmt = $pdo->prepare('SELECT email_todos_meeting_id FROM customers WHERE id = :id');
    $custStmt->execute([':id' => $customerId]);
    $existingId = $custStmt->fetchColumn();

    if ($existingId !== false && $existingId !== null) {
        $checkStmt = $pdo->prepare('SELECT id FROM customer_meetings WHERE id = :id AND customer_id = :cid');
        $checkStmt->execute([':id' => (int) $existingId, ':cid' => $customerId]);
        if ($checkStmt->fetchColumn() !== false) {
            return (int) $existingId;
        }
        // The row is gone somehow (shouldn't normally happen) -- fall
        // through and mint a fresh one rather than erroring out.
    }

    $insert = $pdo->prepare(
        "INSERT INTO customer_meetings (customer_id, subject, meeting_date, notes, logged_by_user_id, logged_by_name, cw_push_status)
         VALUES (:cid, :subj, :mdate, :notes, :uid, :uname, 'skipped')"
    );
    $insert->execute([
        ':cid' => $customerId,
        ':subj' => 'Email To-Dos',
        ':mdate' => gmdate('Y-m-d'),
        ':notes' => 'Auto-created by the Outlook add-in to hold to-dos created directly from emails. Not a real logged meeting.',
        ':uid' => $user['id'],
        ':uname' => $user['name'],
    ]);
    $meetingId = (int) $pdo->lastInsertId();

    $pdo->prepare('UPDATE customers SET email_todos_meeting_id = :mid WHERE id = :cid')
        ->execute([':mid' => $meetingId, ':cid' => $customerId]);

    return $meetingId;
}

/**
 * Validates an optional "HH:MM" 24-hour clock time. Returns the value
 * unchanged if valid, null if blank/absent, or responds 400 and exits if
 * it's present but malformed.
 */
function relationships_meetings_validate_time(?string $raw): ?string
{
    $raw = trim((string) $raw);
    if ($raw === '') {
        return null;
    }
    if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $raw)) {
        relationships_respond(400, ['ok' => false, 'error' => 'Invalid meeting time -- expected HH:MM (24-hour).']);
    }
    return $raw;
}

/**
 * Validates an optional document id against $customerId -- used for both
 * create_meeting's source_email_document_id and add_task's
 * attached_document_id. Returns the int id if valid, null if
 * blank/absent/zero, or responds 400/404 and exits otherwise. Confirms
 * the row actually belongs to this customer so one customer's add-in
 * session can never attach another customer's document by guessing an
 * id.
 */
function relationships_meetings_validate_document_id(PDO $pdo, int $customerId, mixed $raw): ?int
{
    $id = (int) ($raw ?? 0);
    if ($id <= 0) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT id FROM customer_documents WHERE id = :id AND customer_id = :cid');
    $stmt->execute([':id' => $id, ':cid' => $customerId]);
    if ($stmt->fetchColumn() === false) {
        relationships_respond(404, ['ok' => false, 'error' => 'Attached document not found for this customer.']);
    }
    return $id;
}

/**
 * The exact body of meetings.php's old create_meeting action -- see this
 * file's header. $input is the decoded JSON request body (or an
 * equivalent array built directly by outlook-addin.php); same keys as
 * before (customer_id, subject, meeting_date, notes) plus the two new
 * optional ones (meeting_time, source_email_document_id). Responds (and
 * exits) on any validation/not-found error; on success, RETURNS
 * ['ok' => true, 'meeting' => {...}] for the caller to respond with (or
 * use directly, as outlook-addin.php does).
 */
function relationships_meetings_create(PDO $pdo, array $user, ?array $allowedTerritories, array $input): array
{
    $customerId = (int) ($input['customer_id'] ?? 0);
    $subject = trim((string) ($input['subject'] ?? ''));
    $meetingDate = (string) ($input['meeting_date'] ?? '');
    $notes = trim((string) ($input['notes'] ?? ''));

    if ($customerId <= 0 || $subject === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $meetingDate)) {
        relationships_respond(400, ['ok' => false, 'error' => 'Missing/invalid customer_id, subject, or meeting_date (expected YYYY-MM-DD).']);
    }
    if (mb_strlen($subject) > 200) {
        relationships_respond(400, ['ok' => false, 'error' => 'Subject is too long (200 characters max).']);
    }
    $meetingTime = relationships_meetings_validate_time($input['meeting_time'] ?? null);

    $custStmt = $pdo->prepare('SELECT id, connectwise_id, name, territory_name FROM customers WHERE id = :id');
    $custStmt->execute([':id' => $customerId]);
    $customer = $custStmt->fetch(PDO::FETCH_ASSOC);
    if ($customer === false) {
        relationships_respond(404, ['ok' => false, 'error' => 'Customer not found.']);
    }
    relationships_require_territory_scope($allowedTerritories, $customer['territory_name']);

    $sourceEmailDocumentId = relationships_meetings_validate_document_id($pdo, $customerId, $input['source_email_document_id'] ?? null);

    // Local save first, unconditionally -- per this integration's standing
    // "save locally, log the ConnectWise failure" instruction.
    $insert = $pdo->prepare(
        'INSERT INTO customer_meetings (customer_id, subject, meeting_date, meeting_time, notes, logged_by_user_id, logged_by_name, source_email_document_id)
         VALUES (:cid, :subj, :mdate, :mtime, :notes, :uid, :uname, :srcdoc)'
    );
    $insert->execute([
        ':cid' => $customerId, ':subj' => $subject, ':mdate' => $meetingDate, ':mtime' => $meetingTime, ':notes' => $notes,
        ':uid' => $user['id'], ':uname' => $user['name'], ':srcdoc' => $sourceEmailDocumentId,
    ]);
    $meetingId = (int) $pdo->lastInsertId();

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
            $pdo->prepare('UPDATE customer_meetings SET cw_activity_id = :aid, cw_push_status = :status, cw_push_error = NULL WHERE id = :id')
                ->execute([':aid' => $result['id'], ':status' => 'pushed', ':id' => $meetingId]);
        } catch (Throwable $e) {
            $pdo->prepare('UPDATE customer_meetings SET cw_push_status = :status, cw_push_error = :err WHERE id = :id')
                ->execute([':status' => 'error', ':err' => substr($e->getMessage(), 0, 4000), ':id' => $meetingId]);
        }
    } else {
        $pdo->prepare('UPDATE customer_meetings SET cw_push_status = :status WHERE id = :id')
            ->execute([':status' => 'skipped', ':id' => $meetingId]);
    }

    $meetingStmt = $pdo->prepare(
        'SELECT id, subject, meeting_date, meeting_time, notes, logged_by_name, created_at, cw_push_status, cw_push_error,
                edited_at, edited_by_name, cw_update_status, cw_update_error, source_email_document_id
         FROM customer_meetings WHERE id = :id'
    );
    $meetingStmt->execute([':id' => $meetingId]);
    return ['ok' => true, 'meeting' => relationships_meeting_row($meetingStmt->fetch(PDO::FETCH_ASSOC), [])];
}

/**
 * The exact body of meetings.php's old add_task action -- see this
 * file's header. $input is the decoded JSON request body (or an
 * equivalent array outlook-addin.php builds directly); same keys as
 * before (meeting_id, description, assigned_to_name, due_date) plus the
 * one new optional field (attached_document_id). Responds (and exits) on
 * any validation/not-found error; on success, RETURNS
 * ['ok' => true, 'task' => {...}].
 */
function relationships_meetings_add_task(PDO $pdo, array $user, ?array $allowedTerritories, array $input): array
{
    $meetingId = (int) ($input['meeting_id'] ?? 0);
    $description = trim((string) ($input['description'] ?? ''));
    $assignedToName = trim((string) ($input['assigned_to_name'] ?? ''));
    // Scheduled to-dos (Michael, 2026-09-16) -- OPTIONAL: an empty/missing
    // due_date is valid and just means this to-do never appears on the
    // per-coordinator calendar (confirmed via AskUserQuestion).
    $dueDateRaw = trim((string) ($input['due_date'] ?? ''));
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

    $attachedDocumentId = relationships_meetings_validate_document_id($pdo, $customerId, $input['attached_document_id'] ?? null);

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
        'INSERT INTO meeting_tasks (meeting_id, customer_id, description, assigned_to_user_id, assigned_to_name, created_by_user_id, created_by_name, due_date, attached_document_id)
         VALUES (:mid, :cid, :desc, :auid, :aname, :cuid, :cname, :due, :docid)'
    );
    $insert->execute([
        ':mid' => $meetingId, ':cid' => $customerId, ':desc' => $description,
        ':auid' => $assignee['id'] ?? null, ':aname' => $assignedToName,
        ':cuid' => $user['id'], ':cname' => $user['name'], ':due' => $dueDate, ':docid' => $attachedDocumentId,
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
        'SELECT id, description, assigned_to_name, created_by_name, created_at, due_date, completed_at, completed_by_name,
                cw_push_status, cw_push_error, email_status, email_error, attached_document_id
         FROM meeting_tasks WHERE id = :id'
    );
    $taskStmt->execute([':id' => $taskId]);
    return ['ok' => true, 'task' => relationships_meeting_task_row($taskStmt->fetch(PDO::FETCH_ASSOC))];
}
