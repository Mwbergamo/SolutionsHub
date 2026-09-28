<?php
/**
 * relationships/api/meetings-shared.php
 *
 * Split out of meetings.php on 2026-09-28 so outlook-addin.php (the
 * Outlook add-in's "Email Actions Needed" entry point) can reuse these two
 * functions without `require`-ing meetings.php itself -- meetings.php has
 * top-level action-dispatch code (reads $_GET['action'], responds, exits)
 * that must only ever run once per request, as the endpoint actually hit.
 * Nothing here has any side effect on include -- safe for both files to
 * require.
 */

declare(strict_types=1);

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
        // source/email_sender/email_sender_name -- added 2026-09-28 for the
        // Outlook add-in's "Email Actions Needed" section (see db.php's
        // migration). 'manual' for every meeting logged the normal way;
        // 'email' for one created by "Send to Relationships". The frontend
        // groups by this field rather than the backend maintaining two
        // separate lists -- same underlying rows, same tasks/cw_push shape
        // either way.
        'source' => $m['source'] ?? 'manual',
        'email_sender' => $m['email_sender'] ?? null,
        'email_sender_name' => $m['email_sender_name'] ?? null,
        'tasks' => $tasks,
    ];
}
