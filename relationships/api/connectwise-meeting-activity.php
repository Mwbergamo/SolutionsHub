<?php
/**
 * relationships/api/connectwise-meeting-activity.php
 *
 * Customer Meeting Capture's ConnectWise side -- added 2026-09-15 per
 * Michael. Creates a ConnectWise Activity for (a) a newly-logged meeting
 * and (b) a newly-added meeting to-do task, both "following the same
 * format we use for the Check-list items" (Michael's own words) -- meaning
 * both call relationships_cw_create_activity() in
 * connectwise-activity-create.php, the exact same engine (type/status
 * lookup, UTC-second-precision date format, first-synced-contact lookup,
 * full-payload-then-core-payload fallback) already proved out for
 * checklist-step completions. This file only builds the
 * summary/notes/assignee that engine needs for these two new cases, and
 * the "save locally first, log the ConnectWise outcome, never let a
 * ConnectWise failure block or revert the local save" orchestration --
 * same standing instruction as every other write this integration makes.
 *
 * ONE DELIBERATE DIFFERENCE FROM THE CHECKLIST PATTERN: a checklist Activity
 * is assigned to whoever completed the step. A meeting Activity is assigned
 * the same way (to whoever logged the meeting). But a TASK's Activity is
 * assigned to the TASK'S ASSIGNEE -- one of the 7 fixed roster names, per
 * Michael's task-assignment request -- not to whoever typed the task into
 * the meeting. If that assignee hasn't registered a Relationships login yet
 * (self-service, auth.php's 'register' action), there's no email to look
 * up a ConnectWise Member by, so the Activity is created memberless --
 * same graceful degradation the engine already applies to a failed lookup.
 *
 * Neither function here writes cw_activity_id/cw_push_status/cw_push_error
 * itself -- that's meetings.php's job (it already has the row open from the
 * local INSERT). These two functions only ever return a result array or
 * throw RelationshipsConnectWiseError; meetings.php's try/catch turns that
 * into the row update.
 */

declare(strict_types=1);

require_once __DIR__ . '/connectwise-activity-create.php';

/**
 * Builds and POSTs the ConnectWise Activity for a newly-logged meeting.
 *
 * $ctx keys: customer_id (int), cw_company_id (string), subject (string),
 * notes (string, the meeting notes as typed -- carried verbatim into the
 * Activity's Notes field per Michael: "We need the notes to carry over to
 * the activity"), logged_by_name (string), logged_by_email (string),
 * logged_at_display (string, human-readable Eastern timestamp).
 *
 * Returns ['id' => string, 'variant' => 'full'|'core'] on success. Throws
 * RelationshipsConnectWiseError if neither attempt succeeds.
 */
function relationships_cw_create_meeting_activity(PDO $pdo, array $ctx): array
{
    $notes = trim((string) $ctx['notes']);
    $notes = ($notes !== '' ? $notes . "\n\n" : '') .
        'Logged by ' . $ctx['logged_by_name'] . ' - ' . $ctx['logged_at_display'];

    return relationships_cw_create_activity($pdo, [
        'customer_id' => $ctx['customer_id'],
        'cw_company_id' => $ctx['cw_company_id'],
        'summary' => (string) $ctx['subject'],
        'notes' => $notes,
        'assign_to_email' => $ctx['logged_by_email'],
    ]);
}

/**
 * Builds and POSTs the ConnectWise Activity for a newly-added meeting
 * to-do task -- fires at TASK CREATION (confirmed via AskUserQuestion
 * 2026-09-15).
 *
 * 2026-09-17 UPDATE, per Michael: unlike every other Activity this
 * integration creates, a task's Activity is now created OPEN, not Closed
 * -- it gets closed separately, for real, when the to-do is actually
 * marked done (meetings.php's set_task_done calls
 * relationships_cw_close_activity() on this same Activity id). Previously
 * it was created already-Closed at this same creation moment, regardless
 * of whether the to-do was actually done yet.
 *
 * Also per Michael: a to-do with a due_date gets that same date set on the
 * Activity's schedule (date-only, i.e. midnight Eastern that day) instead
 * of "now" -- and if that date is still in the future, scheduleStatus is
 * 'Tentative' rather than the usual 'Firm', since it's a projected date,
 * not a certain one.
 *
 * $ctx keys: customer_id (int), cw_company_id (string), description
 * (string), meeting_subject (string, for context in the Notes field),
 * assigned_to_name (string), assigned_to_email (string|null -- null when
 * that roster member has no Relationships login yet), created_by_name
 * (string), created_at_display (string, human-readable Eastern timestamp),
 * due_date (string|null, "YYYY-MM-DD" -- the to-do's scheduled date, same
 * as meeting_tasks.due_date).
 *
 * Returns ['id' => string, 'variant' => 'full'|'core'] on success. Throws
 * RelationshipsConnectWiseError if neither attempt succeeds.
 */
function relationships_cw_create_task_activity(PDO $pdo, array $ctx): array
{
    $notes = (string) $ctx['description'] . "\n\n" .
        'Assigned to: ' . $ctx['assigned_to_name'] . "\n" .
        'From meeting: ' . $ctx['meeting_subject'] . "\n" .
        'Added by ' . $ctx['created_by_name'] . ' - ' . $ctx['created_at_display'];

    $activityCtx = [
        'customer_id' => $ctx['customer_id'],
        'cw_company_id' => $ctx['cw_company_id'],
        'summary' => (string) $ctx['description'],
        'notes' => $notes,
        // Per the task-assignment feature's whole point: the Activity's
        // assigned member is the TASK's assignee, not the CRC adding the
        // task. null (no login yet for that roster member) just means a
        // memberless Activity, same as any other failed member lookup.
        'assign_to_email' => $ctx['assigned_to_email'],
        'status' => 'open',
    ];

    $dueDate = $ctx['due_date'] ?? null;
    if ($dueDate !== null && $dueDate !== '') {
        $eastern = new DateTimeZone('America/New_York');
        // '!' resets every field this format doesn't specify to the Unix
        // epoch defaults (00:00:00) -- i.e. midnight Eastern on that date,
        // the "date only" this file's docblock above describes.
        $activityDate = DateTimeImmutable::createFromFormat('!Y-m-d', $dueDate, $eastern);
        if ($activityDate !== false) {
            $activityCtx['date'] = $activityDate;
            $today = new DateTimeImmutable('today', $eastern);
            if ($activityDate > $today) {
                $activityCtx['schedule_status'] = 'Tentative';
            }
        }
    }

    return relationships_cw_create_activity($pdo, $activityCtx);
}
