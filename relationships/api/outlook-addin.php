<?php
/**
 * relationships/api/outlook-addin.php
 *
 * Backend for the Outlook add-in ("Send to Relationships") -- added
 * 2026-09-28 per Michael's decisions (see
 * claude/relationships-outlook-addin-plan.md and the follow-up chat that
 * settled the five open questions). Every action here is bearer-token
 * authed (relationships_require_login_or_bearer() in _util.php) -- the
 * task pane cannot reuse this site's session cookie, so it signs in once
 * per device via auth/addin-login.php + auth/addin-callback.php and sends
 * that token as `Authorization: Bearer <token>` on every call.
 *
 * This file deliberately does NOT reimplement the to-do engine. Once an
 * email is attached to a customer (create_email_action below), the task
 * pane calls THIS SAME SITE'S existing relationships/api/meetings.php
 * directly (list / add_task / set_task_done / global), with the same
 * bearer token -- that endpoint was given bearer-auth support alongside
 * this file specifically so nothing about the to-do/ConnectWise-Activity/
 * Outgrow-action engine needs to be duplicated. This file only covers the
 * two things meetings.php doesn't already do: resolving a sender email to
 * a customer, and creating the "Email Actions Needed" entry itself.
 *
 * The flow, per Michael (2026-09-28, verbatim):
 *   "When the button is pressed, with the email in view, that email is
 *   assessed for who sent it and the sender's email is used to search in
 *   our Relationships database. If a match, it will just add the email to
 *   that company/contact. If no match, it asks the rep to search and click
 *   on the match. Then, it processes the email to the next step."
 *
 * GET /relationships/api/outlook-addin.php?action=match&email=jane@acme.com
 *   Exact synced-contact-email lookup (case-insensitive), territory-scoped
 *   the same way customers.php's search is -- a match outside the signed-in
 *   rep's allowed territories is treated as no match, not surfaced and then
 *   blocked, so the task pane's UX doesn't need a separate "found but not
 *   yours" state.
 *   -> { ok: true, customer: { id, name }|null }
 *
 * GET /relationships/api/outlook-addin.php?action=search&q=acme
 *   No-match path: lets the rep search and pick, same company-name +
 *   synced-contact-name/email search customers.php's list action already
 *   does (kept as its own small query here rather than calling that
 *   endpoint internally, since the add-in's compact picker only needs
 *   id/name -- see file header).
 *   -> { ok: true, customers: [{ id, name }, ...] }
 *
 * POST /relationships/api/outlook-addin.php?action=create_email_action
 *   { customer_id, subject, sender_email, sender_name, body_snippet,
 *     message_id }
 *   Creates (or, on a repeat click for the SAME email/customer pair,
 *   returns the EXISTING) Email Actions Needed entry -- a customer_meetings
 *   row with source='email', subject as the heading (per Michael: "using
 *   the Subject line as the heading"), and the same ConnectWise Activity
 *   creation create_meeting already does (relationships_cw_create_meeting_activity()).
 *   message_id is Outlook's own item id for the source email -- used ONLY
 *   for the dedupe above (db.php's idx_customer_meetings_email_dedupe), so
 *   double-clicking the add-in button on the same email is a no-op rather
 *   than a duplicate entry. subject is capped at 200 chars (same limit
 *   meetings.php's create_meeting enforces) and body_snippet at ~300 chars
 *   in the notes field (same cap the original plan set for a to-do
 *   description, applied here to keep one consistent size everywhere an
 *   email body gets stored).
 *   -> { ok: true, meeting: {...}, existing: bool }
 *   `existing: true` means this exact (customer_id, message_id) pair
 *   already had an entry and nothing new was created -- the task pane
 *   should treat this identically to a fresh create (open the same entry),
 *   not as an error.
 */

declare(strict_types=1);

require_once __DIR__ . '/_util.php';
require_once __DIR__ . '/territory-access.php';
require_once __DIR__ . '/connectwise-meeting-activity.php';
require_once __DIR__ . '/meetings-shared.php';

$pdo = relationships_db();
$user = relationships_require_login_or_bearer($pdo);
// Session-free territory lookup -- see meetings.php's identical comment.
// Every action below is reachable only via a bearer token in practice (the
// add-in never carries this site's session cookie), but this stays correct
// either way.
$allowedTerritories = relationships_allowed_territories_for_email($pdo, (string) $user['email']);

$action = $_GET['action'] ?? '';

/** Trims to $max chars, appending an ellipsis marker if truncated -- shared by subject/body handling below. */
function relationships_addin_clip(string $text, int $max): string
{
    $text = trim($text);
    if (mb_strlen($text) <= $max) {
        return $text;
    }
    return mb_substr($text, 0, $max - 1) . '…';
}

if ($action === 'match') {
    $email = strtolower(trim((string) ($_GET['email'] ?? '')));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        relationships_respond(400, ['ok' => false, 'error' => 'Missing/invalid email.']);
    }

    $territoryFilter = $allowedTerritories === null
        ? ['sql' => '', 'params' => []]
        : relationships_territory_filter_sql($allowedTerritories, 'c');

    $stmt = $pdo->prepare(
        "SELECT c.id, c.name
         FROM contacts ct
         JOIN customers c ON c.id = ct.customer_id
         WHERE lower(ct.email) = :email {$territoryFilter['sql']}
         ORDER BY ct.synced_at DESC
         LIMIT 1"
    );
    $stmt->execute(array_merge([':email' => $email], $territoryFilter['params']));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    relationships_respond(200, [
        'ok' => true,
        'customer' => $row !== false ? ['id' => (int) $row['id'], 'name' => $row['name']] : null,
    ]);
}

if ($action === 'search') {
    $q = trim((string) ($_GET['q'] ?? ''));
    if ($q === '') {
        relationships_respond(200, ['ok' => true, 'customers' => []]);
    }
    $like = '%' . $q . '%';

    $territoryFilter = $allowedTerritories === null
        ? ['sql' => '', 'params' => []]
        : relationships_territory_filter_sql($allowedTerritories, 'customers');
    $territoryFilterC = $allowedTerritories === null
        ? ['sql' => '', 'params' => []]
        : relationships_territory_filter_sql($allowedTerritories, 'c');

    // Company name match -- same LIKE search customers.php's list action
    // uses, trimmed to just id/name for the add-in's compact picker.
    $nameStmt = $pdo->prepare(
        "SELECT id, name FROM customers WHERE name LIKE :q {$territoryFilter['sql']} ORDER BY name ASC LIMIT 25"
    );
    $nameStmt->execute(array_merge([':q' => $like], $territoryFilter['params']));
    $rows = [];
    foreach ($nameStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $rows[(int) $r['id']] = ['id' => (int) $r['id'], 'name' => $r['name']];
    }

    // Synced-contact name/email match, resolved to the parent company --
    // same as customers.php's list action.
    $contactStmt = $pdo->prepare(
        "SELECT c.id, c.name
         FROM contacts ct
         JOIN customers c ON c.id = ct.customer_id
         WHERE (ct.first_name LIKE :q1 OR ct.last_name LIKE :q2 OR ct.email LIKE :q3
            OR (ct.first_name || ' ' || ct.last_name) LIKE :q4)
            {$territoryFilterC['sql']}
         ORDER BY c.name ASC LIMIT 25"
    );
    $contactStmt->execute(array_merge([':q1' => $like, ':q2' => $like, ':q3' => $like, ':q4' => $like], $territoryFilterC['params']));
    foreach ($contactStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $id = (int) $r['id'];
        if (!isset($rows[$id])) {
            $rows[$id] = ['id' => $id, 'name' => $r['name']];
        }
    }

    $customers = array_values($rows);
    usort($customers, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));
    relationships_respond(200, ['ok' => true, 'customers' => array_slice($customers, 0, 25)]);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    relationships_respond(405, ['ok' => false, 'error' => 'Method not allowed.']);
}

if ($action === 'create_email_action') {
    $data = relationships_read_json_body();
    $customerId = (int) ($data['customer_id'] ?? 0);
    $subject = relationships_addin_clip((string) ($data['subject'] ?? ''), 200);
    $senderEmail = trim((string) ($data['sender_email'] ?? ''));
    $senderName = trim((string) ($data['sender_name'] ?? ''));
    $bodySnippet = relationships_addin_clip((string) ($data['body_snippet'] ?? ''), 300);
    $messageId = trim((string) ($data['message_id'] ?? ''));

    if ($customerId <= 0 || $subject === '' || $messageId === '') {
        relationships_respond(400, ['ok' => false, 'error' => 'Missing/invalid customer_id, subject, or message_id.']);
    }

    $custStmt = $pdo->prepare('SELECT id, connectwise_id, name, territory_name FROM customers WHERE id = :id');
    $custStmt->execute([':id' => $customerId]);
    $customer = $custStmt->fetch(PDO::FETCH_ASSOC);
    if ($customer === false) {
        relationships_respond(404, ['ok' => false, 'error' => 'Customer not found.']);
    }
    relationships_require_territory_scope($allowedTerritories, $customer['territory_name']);

    // Dedupe: the SAME email sent to the SAME customer a second time (a
    // rep double-clicking "Send to Relationships") returns the existing
    // entry instead of creating a duplicate -- see db.php's
    // idx_customer_meetings_email_dedupe unique index.
    $existingStmt = $pdo->prepare(
        'SELECT id, subject, meeting_date, notes, logged_by_name, created_at, cw_push_status, cw_push_error, source, email_sender, email_sender_name
         FROM customer_meetings WHERE customer_id = :cid AND email_message_id = :mid'
    );
    $existingStmt->execute([':cid' => $customerId, ':mid' => $messageId]);
    $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);
    if ($existing !== false) {
        relationships_respond(200, [
            'ok' => true,
            'meeting' => relationships_meeting_row($existing, []),
            'existing' => true,
        ]);
    }

    $notes = $bodySnippet;
    if ($senderEmail !== '') {
        $fromLine = 'From: ' . ($senderName !== '' ? $senderName . ' <' . $senderEmail . '>' : $senderEmail);
        $notes = $fromLine . ($notes !== '' ? "\n\n" . $notes : '');
    }

    // Local save first, unconditionally -- same "save locally, log the
    // ConnectWise outcome" posture as every other write in this
    // integration (see meetings.php's create_meeting, which this mirrors).
    $insert = $pdo->prepare(
        "INSERT INTO customer_meetings
            (customer_id, subject, meeting_date, notes, logged_by_user_id, logged_by_name, source, email_sender, email_sender_name, email_message_id)
         VALUES (:cid, :subj, date('now'), :notes, :uid, :uname, 'email', :sender, :sender_name, :mid)"
    );
    $insert->execute([
        ':cid' => $customerId, ':subj' => $subject, ':notes' => $notes,
        ':uid' => $user['id'], ':uname' => $user['name'],
        ':sender' => $senderEmail !== '' ? $senderEmail : null,
        ':sender_name' => $senderName !== '' ? $senderName : null,
        ':mid' => $messageId,
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
        'SELECT id, subject, meeting_date, notes, logged_by_name, created_at, cw_push_status, cw_push_error, source, email_sender, email_sender_name
         FROM customer_meetings WHERE id = :id'
    );
    $meetingStmt->execute([':id' => $meetingId]);
    relationships_respond(200, [
        'ok' => true,
        'meeting' => relationships_meeting_row($meetingStmt->fetch(PDO::FETCH_ASSOC), []),
        'existing' => false,
    ]);
}

relationships_respond(400, ['ok' => false, 'error' => 'Unknown action.']);
