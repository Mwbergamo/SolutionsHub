<?php
declare(strict_types=1);

/**
 * relationships/api/outlook-addin.php
 *
 * Backend for the "Relationships Hub" Outlook add-in (outlook-addin/,
 * added 2026-11-12 per Michael -- see claude/relationships-outlook-addin-plan.md
 * for the full product design). A rep reading an email in Outlook can,
 * from the add-in's task pane:
 *   - match/search for the customer that email belongs to,
 *   - file the email's real attachments as Documents under that customer
 *     ("File as Document(s)" -- rep picks which attachments),
 *   - create a Meeting (with a date+time, optional notes pulled from the
 *     email body, and the email itself auto-filed as a Document), or
 *   - create a To-Do (a quick "Create a to-do?" confirm + a date, with
 *     the email auto-filed as a Document and attached to that to-do).
 *
 * AUTH: unlike every other relationships/api/*.php endpoint, this one
 * accepts an add-in bearer token in addition to the normal shared session
 * cookie -- see relationships_require_login_or_bearer() in _util.php for
 * why (the add-in's task pane runs in Outlook's own sandboxed webview,
 * which doesn't reliably see this site's session cookie). Territory
 * scoping is therefore computed directly from $user's email
 * (relationships_allowed_territories_for_email()), NOT
 * relationships_allowed_territories($pdo) -- that second form reads
 * $_SESSION itself and would silently come back "unrestricted" for a
 * bearer-authed request with no session, which would be a real privilege
 * escalation for any rep who IS supposed to be territory-restricted.
 *
 * GET  ?action=me
 *   -> { ok: true, user: { id, name, email } }
 *
 * GET  ?action=roster
 *   -> { ok: true, roster: [...7 fixed assignee names...] } -- same list
 *      as relationships_todo_roster() (catalog.php), for the "Assigned
 *      to" dropdown on Create a To-Do.
 *
 * GET  ?action=match&email=someone@customer.com
 *   Exact (case-insensitive) match against contacts.email, territory-
 *   scoped. A restricted rep never even learns a matching customer
 *   exists outside their territories -- the territory filter is applied
 *   IN the match query itself, not as a later visibility check.
 *   -> { ok: true, customer: { id, name } | null }
 *
 * GET  ?action=search&q=some+text
 *   Customer-name / contact-name / contact-email substring search (for
 *   when ?action=match finds nothing), same territory scoping. Up to 20
 *   results.
 *   -> { ok: true, customers: [ { id, name }, ... ] }
 *
 * POST ?action=create_meeting
 *   { customer_id, subject?, meeting_date: "YYYY-MM-DD", meeting_time?: "HH:MM",
 *     notes?, email_subject?, email_sender_name?, email_sender_email?,
 *     email_received_at?, email_body_text? }
 *   Files the email as a Document (relationships_outlook_addin_file_email_snapshot()
 *   below), then calls relationships_meetings_create() from
 *   meetings-core.php with source_email_document_id set to it. subject
 *   falls back to email_subject when blank -- the rep can always retype
 *   it in the task pane first.
 *   -> { ok: true, meeting: {...} } (same shape as meetings.php's own
 *      create_meeting)
 *
 * POST ?action=create_todo
 *   { customer_id, description?, assigned_to_name, due_date?,
 *     email_subject?, email_sender_name?, email_sender_email?,
 *     email_received_at?, email_body_text? }
 *   Files the email as a Document, finds-or-creates this customer's
 *   invisible "Email To-Dos" placeholder meeting
 *   (relationships_meetings_email_todos_meeting_id() in
 *   meetings-core.php), then calls relationships_meetings_add_task() with
 *   attached_document_id set to it. description falls back to an
 *   auto-built "Follow up: <subject> (from <sender>)" when blank -- per
 *   Michael, the rep can edit this before saving; it's a starting point,
 *   not a forced value.
 *   -> { ok: true, task: {...} } (same shape as meetings.php's own
 *      add_task)
 *
 * POST ?action=file_documents
 *   multipart/form-data: customer_id, category?, files[] (one or more --
 *   the rep's picked attachments from the email)
 *   Every file is validated (extension/size/magic-byte, same rules as
 *   documents.php's own upload) BEFORE any of them is saved, so a bad
 *   file later in the batch can't leave earlier ones already filed while
 *   the request still errors out.
 *   -> { ok: true, documents: [ {...}, ... ] } (same row shape as
 *      documents.php's own upload)
 *
 * POST ?action=outgrow
 *   { email_body_text }
 *   "OutGrow!" (added 2026-11-13 per Michael) -- reads the OPEN EMAIL'S
 *   text the exact same way relationships/api/outgrow-ticket.php reads a
 *   pasted ConnectWise "OutGrow Action" ticket (same parser, same Last
 *   OutGrow Touch update, same pre-filled Formstack link): this just
 *   substitutes the email body for the paste box. Independent of
 *   customer_id/the matched-customer flow above -- the company comes
 *   straight out of the parsed text's "Company:" line, same as the web
 *   app's standalone "Ticket -> OutGrow" page. Uses $allowedTerritories
 *   (not relationships_allowed_territories($pdo)) for the Last OutGrow
 *   Touch write's territory check, for the same bearer-auth reason as
 *   everywhere else in this file.
 *   -> { ok: true, values: {...}, warnings: [...], formstack_url: "...",
 *        touch: {...} } (same shape as outgrow-ticket.php) or
 *      { ok: false, error: "..." } (text didn't look like a ticket)
 */

require_once __DIR__ . '/_util.php';
require_once __DIR__ . '/territory-access.php';
require_once __DIR__ . '/documents-core.php';
require_once __DIR__ . '/meetings-core.php';
require_once __DIR__ . '/catalog.php';
require_once __DIR__ . '/connectwise-outgrow.php';
require_once __DIR__ . '/outgrow-ticket-parse.php';
require_once __DIR__ . '/outgrow-ticket-touch.php';

$pdo = relationships_db();
$user = relationships_require_login_or_bearer($pdo);
$allowedTerritories = relationships_allowed_territories_for_email($pdo, (string) $user['email']);

$action = $_GET['action'] ?? '';

/**
 * Builds a small plain-text snapshot of the email behind a Create-a-
 * Meeting/Create-a-To-Do action (From/Date/Subject header lines + the
 * body text) and files it as a Document under $customerId through the
 * exact same pipeline documents.php's own upload action uses (see
 * documents-core.php) -- this is NOT a true .eml MIME file, just enough
 * of a plain-text record to open later and see what the email said.
 * Returns the new Document's id, or null if there was nothing worth
 * filing (no subject AND no body text -- shouldn't normally happen, since
 * every real email has at least a subject, but this is called on every
 * Create-a-Meeting/Create-a-To-Do regardless, so it degrades rather than
 * erroring out of the whole action over a missing snapshot).
 */
function relationships_outlook_addin_file_email_snapshot(
    PDO $pdo,
    int $customerId,
    array $user,
    string $emailSubject,
    string $emailSenderName,
    string $emailSenderEmail,
    string $emailReceivedAt,
    string $emailBodyText,
    string $category
): ?int {
    $emailSubject = trim($emailSubject);
    $emailBodyText = trim($emailBodyText);
    if ($emailSubject === '' && $emailBodyText === '') {
        return null;
    }

    $fromLine = trim($emailSenderName . ($emailSenderEmail !== '' ? ' <' . $emailSenderEmail . '>' : ''));
    $lines = [];
    $lines[] = 'From: ' . ($fromLine !== '' ? $fromLine : '(unknown sender)');
    if (trim($emailReceivedAt) !== '') {
        $lines[] = 'Date: ' . trim($emailReceivedAt);
    }
    $lines[] = 'Subject: ' . ($emailSubject !== '' ? $emailSubject : '(no subject)');
    $lines[] = '';
    $lines[] = $emailBodyText !== '' ? $emailBodyText : '(no body text)';
    $snapshot = implode("\n", $lines) . "\n";

    $tmpPath = tempnam(sys_get_temp_dir(), 'addin_email_');
    if ($tmpPath === false || file_put_contents($tmpPath, $snapshot) === false) {
        relationships_respond(500, ['ok' => false, 'error' => 'Could not prepare the email snapshot to file as a Document.']);
    }

    // A short, filesystem-safe name derived from the subject -- this is
    // only ever shown as the Document's "original filename," never
    // parsed back, so it just needs to be legible and valid.
    $safeSubject = (string) preg_replace('/[^A-Za-z0-9 _-]/', '', $emailSubject);
    $safeSubject = trim($safeSubject);
    if ($safeSubject === '') {
        $safeSubject = 'email';
    }
    $originalName = mb_substr($safeSubject, 0, 80) . '.txt';

    $ext = relationships_document_validate_file($originalName, strlen($snapshot), $tmpPath);
    $storedName = relationships_document_place_file($customerId, $ext, $tmpPath, false);
    $doc = relationships_document_insert_and_push(
        $pdo, $customerId, $originalName, $storedName, strlen($snapshot), $category, (int) $user['id'], $user['name']
    );

    return $doc['id'];
}

if ($action === 'me') {
    relationships_respond(200, ['ok' => true, 'user' => $user]);
}

if ($action === 'roster') {
    // The same fixed 7-name assignee roster the web app's own to-do
    // pickers use (relationships_todo_roster(), catalog.php) -- the
    // task pane's "Assigned to" dropdown on Create a To-Do was calling
    // this action already, but it was never implemented here, so the
    // dropdown always came back empty and a to-do could never be saved.
    relationships_respond(200, ['ok' => true, 'roster' => relationships_todo_roster()]);
}

if ($action === 'match') {
    $email = trim((string) ($_GET['email'] ?? ''));
    if ($email === '' || !str_contains($email, '@')) {
        relationships_respond(400, ['ok' => false, 'error' => 'Missing/invalid email.']);
    }

    $sql = 'SELECT c.id, c.name
            FROM contacts ct
            JOIN customers c ON c.id = ct.customer_id
            WHERE LOWER(ct.email) = LOWER(:email)';
    $params = [':email' => $email];
    if ($allowedTerritories !== null) {
        // Applied directly in the match query, not as a later visibility
        // check -- a restricted rep should never even learn that a
        // matching customer exists outside their assigned territories.
        $filter = relationships_territory_filter_sql($allowedTerritories, 'c');
        $sql .= ' ' . $filter['sql'];
        $params = array_merge($params, $filter['params']);
    }
    $sql .= ' LIMIT 1';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    relationships_respond(200, [
        'ok' => true,
        'customer' => $row !== false ? ['id' => (int) $row['id'], 'name' => $row['name']] : null,
    ]);
}

if ($action === 'search') {
    $q = trim((string) ($_GET['q'] ?? ''));
    if (mb_strlen($q) < 2) {
        relationships_respond(200, ['ok' => true, 'customers' => []]);
    }
    $like = '%' . $q . '%';

    $sql = 'SELECT DISTINCT c.id, c.name
            FROM customers c
            LEFT JOIN contacts ct ON ct.customer_id = c.id
            WHERE (c.name LIKE :q1 OR ct.first_name LIKE :q2 OR ct.last_name LIKE :q3 OR ct.email LIKE :q4)';
    $params = [':q1' => $like, ':q2' => $like, ':q3' => $like, ':q4' => $like];
    if ($allowedTerritories !== null) {
        $filter = relationships_territory_filter_sql($allowedTerritories, 'c');
        $sql .= ' ' . $filter['sql'];
        $params = array_merge($params, $filter['params']);
    }
    $sql .= ' ORDER BY c.name ASC LIMIT 20';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $customers = array_map(
        static fn (array $r): array => ['id' => (int) $r['id'], 'name' => $r['name']],
        $stmt->fetchAll(PDO::FETCH_ASSOC)
    );
    relationships_respond(200, ['ok' => true, 'customers' => $customers]);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    relationships_respond(405, ['ok' => false, 'error' => 'Method not allowed.']);
}

if ($action === 'create_meeting') {
    $data = relationships_read_json_body();
    $customerId = (int) ($data['customer_id'] ?? 0);
    if ($customerId <= 0) {
        relationships_respond(400, ['ok' => false, 'error' => 'Missing/invalid customer_id.']);
    }
    // Scope-check up front -- the email snapshot below files a Document
    // against this customer BEFORE relationships_meetings_create() gets
    // a chance to run its own (identical) check.
    relationships_documents_require_customer_in_scope($pdo, $allowedTerritories, $customerId);

    $emailSubject = (string) ($data['email_subject'] ?? '');
    $emailSenderName = (string) ($data['email_sender_name'] ?? '');
    $emailSenderEmail = (string) ($data['email_sender_email'] ?? '');
    $emailReceivedAt = (string) ($data['email_received_at'] ?? '');
    $emailBodyText = (string) ($data['email_body_text'] ?? '');

    $sourceEmailDocumentId = relationships_outlook_addin_file_email_snapshot(
        $pdo, $customerId, $user, $emailSubject, $emailSenderName, $emailSenderEmail, $emailReceivedAt, $emailBodyText, 'Meeting Notes'
    );

    $subject = trim((string) ($data['subject'] ?? ''));
    if ($subject === '') {
        $subject = trim($emailSubject);
    }

    $result = relationships_meetings_create($pdo, $user, $allowedTerritories, [
        'customer_id' => $customerId,
        'subject' => $subject,
        'meeting_date' => $data['meeting_date'] ?? '',
        'meeting_time' => $data['meeting_time'] ?? null,
        'notes' => $data['notes'] ?? '',
        'source_email_document_id' => $sourceEmailDocumentId,
    ]);
    relationships_respond(200, $result);
}

if ($action === 'create_todo') {
    $data = relationships_read_json_body();
    $customerId = (int) ($data['customer_id'] ?? 0);
    if ($customerId <= 0) {
        relationships_respond(400, ['ok' => false, 'error' => 'Missing/invalid customer_id.']);
    }
    relationships_documents_require_customer_in_scope($pdo, $allowedTerritories, $customerId);

    $emailSubject = (string) ($data['email_subject'] ?? '');
    $emailSenderName = (string) ($data['email_sender_name'] ?? '');
    $emailSenderEmail = (string) ($data['email_sender_email'] ?? '');
    $emailReceivedAt = (string) ($data['email_received_at'] ?? '');
    $emailBodyText = (string) ($data['email_body_text'] ?? '');

    $attachedDocumentId = relationships_outlook_addin_file_email_snapshot(
        $pdo, $customerId, $user, $emailSubject, $emailSenderName, $emailSenderEmail, $emailReceivedAt, $emailBodyText, 'General'
    );

    $meetingId = relationships_meetings_email_todos_meeting_id($pdo, $customerId, $user);

    $description = trim((string) ($data['description'] ?? ''));
    if ($description === '') {
        // Auto-built starting point, not a forced value -- per Michael,
        // the rep can edit this in the task pane before saving.
        $trimmedSubject = trim($emailSubject);
        $description = 'Follow up';
        if ($trimmedSubject !== '') {
            $description .= ' on: ' . $trimmedSubject;
        }
        if (trim($emailSenderName) !== '') {
            $description .= ' (from ' . trim($emailSenderName) . ')';
        }
    }

    $result = relationships_meetings_add_task($pdo, $user, $allowedTerritories, [
        'meeting_id' => $meetingId,
        'description' => $description,
        'assigned_to_name' => (string) ($data['assigned_to_name'] ?? ''),
        'due_date' => $data['due_date'] ?? null,
        'attached_document_id' => $attachedDocumentId,
    ]);
    relationships_respond(200, $result);
}

if ($action === 'file_documents') {
    $customerId = (int) ($_POST['customer_id'] ?? 0);
    if ($customerId <= 0) {
        relationships_respond(400, ['ok' => false, 'error' => 'Missing/invalid customer_id.']);
    }
    relationships_documents_require_customer_in_scope($pdo, $allowedTerritories, $customerId);

    $category = trim((string) ($_POST['category'] ?? 'General'));
    if (!in_array($category, relationships_document_categories(), true)) {
        $category = 'General';
    }

    // The task pane sends one or more picked attachments as files[] --
    // same validation documents.php's own single-file upload uses,
    // looped. PHP collects a files[] multi-upload as parallel arrays
    // under $_FILES['files']['name'][i], ['size'][i], etc.
    $files = $_FILES['files'] ?? null;
    if (!is_array($files) || !isset($files['name']) || !is_array($files['name']) || count($files['name']) === 0) {
        relationships_respond(400, ['ok' => false, 'error' => 'No files received.']);
    }

    $count = count($files['name']);

    // Pass 1: validate EVERY file (extension/size/magic-byte) before
    // placing/saving any of them -- a bad file later in the batch must
    // not leave earlier ones already filed while the request as a whole
    // still errors out.
    $exts = [];
    for ($i = 0; $i < $count; $i++) {
        if ((int) $files['error'][$i] === UPLOAD_ERR_INI_SIZE || (int) $files['error'][$i] === UPLOAD_ERR_FORM_SIZE) {
            relationships_respond(400, ['ok' => false, 'error' => '"' . $files['name'][$i] . '" is too large for the server to accept.']);
        }
        if ((int) $files['error'][$i] !== UPLOAD_ERR_OK) {
            relationships_respond(400, ['ok' => false, 'error' => 'Upload failed for "' . $files['name'][$i] . '" (error code ' . (int) $files['error'][$i] . ').']);
        }
        $originalName = trim(basename(str_replace('\\', '/', (string) $files['name'][$i])));
        $exts[$i] = relationships_document_validate_file($originalName, (int) $files['size'][$i], $files['tmp_name'][$i]);
    }

    // Pass 2: now actually place + save each one.
    $documents = [];
    for ($i = 0; $i < $count; $i++) {
        $originalName = trim(basename(str_replace('\\', '/', (string) $files['name'][$i])));
        $storedName = relationships_document_place_file($customerId, $exts[$i], $files['tmp_name'][$i], true);
        $documents[] = relationships_document_insert_and_push(
            $pdo, $customerId, $originalName, $storedName, (int) $files['size'][$i], $category, (int) $user['id'], $user['name']
        );
    }

    relationships_respond(200, ['ok' => true, 'documents' => $documents]);
}

if ($action === 'outgrow') {
    $data = relationships_read_json_body();
    $text = trim((string) ($data['email_body_text'] ?? ''));
    if ($text === '') {
        relationships_respond(400, ['ok' => false, 'error' => 'No email text to read.']);
    }

    $parsed = relationships_outgrow_parse_ticket($text);
    if (!$parsed['ok']) {
        relationships_respond(422, ['ok' => false, 'error' => $parsed['error']]);
    }

    try {
        $touch = relationships_outgrow_apply_touch(
            $pdo,
            $user,
            $allowedTerritories,
            (string) $parsed['values']['company'],
            $parsed['values']['note_date'],
            static function (string $cwCompanyId, string $dateYmd): void {
                relationships_cw_outgrow_write($cwCompanyId, $dateYmd);
            }
        );
    } catch (Throwable $e) {
        error_log('[outlook-addin/outgrow] touch update failed: ' . $e->getMessage());
        $touch = ['status' => 'error', 'message' => 'Couldn’t update Last OutGrow Touch (see server log). The OutGrow form link below still works.'];
    }

    relationships_respond(200, [
        'ok' => true,
        'values' => $parsed['values'],
        'warnings' => $parsed['warnings'],
        'formstack_url' => relationships_outgrow_form_url($parsed['values']),
        'touch' => $touch,
    ]);
}

relationships_respond(400, ['ok' => false, 'error' => 'Unknown action.']);
