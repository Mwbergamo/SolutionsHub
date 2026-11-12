<?php
declare(strict_types=1);

/**
 * marketing/api/send.php
 *
 * "Search for a company, select a contact's email, attach a file within
 * the marketing app and send it via email" (Michael, 2026-11-12), plus
 * "I want to know which files have been sent to each company already
 * with a date/rep/time with the file name that was sent."
 *
 * Company search and contact/email lookup are cross-app reads of
 * Relationships' own data -- relationships/api/db.php's `customers`
 * table for the name search, and the exact same live-ConnectWise contact
 * list Account Contacts already built (relationships/api/
 * contacts-admin-core.php's relationships_contacts_admin_list_for_customer()),
 * so a contact's email here is never a second, driftable copy of what
 * Relationships itself shows. Deliberately UNRESTRICTED by Relationships'
 * territory filtering (passes $allowedTerritories = null, that
 * function's own documented "see everything" value) -- marketing
 * collateral isn't sensitive account data the way a customer's service
 * history is, and any rep should be able to send any company a brochure
 * regardless of whose assigned territory it falls in.
 *
 * Email itself goes through the same shared CodeBlue mailbox Quote/
 * Feedback emails already use (mail/graph-mailer.php, app-only Graph API
 * credentials) -- not literally sent from the rep's own Outlook inbox.
 * Per Michael's "Reply-To the sending rep" answer (2026-11-12), Reply-To
 * is set to the signed-in rep's own email so a reply from the contact
 * reaches them directly.
 *
 * GET  ?action=company_search&q=...
 *   -> { ok, companies: [{id, name}] }
 *
 * GET  ?action=company_contacts&customer_id=N
 *   -> { ok, customer: {id,name}, contacts: [{id,first_name,last_name,
 *        inactive,email,phone,type_name}, ...] } -- same shape Account
 *      Contacts uses; a contact with no email on file still appears
 *      (frontend disables picking it) rather than being silently
 *      dropped, so "this contact has no email yet" is visible, not
 *      confusing.
 *
 * POST ?action=send   { document_id, customer_id, customer_name,
 *   contact_id?, contact_name, contact_email, subject?, note? }
 *   -> { ok: true } -- emails the document as an attachment, then logs
 *      the send. Fails with a clear error (not a 500) if the file is
 *      over the ~3 MB email-attachment cap (see documents-core.php) --
 *      the file is still there to download/share another way.
 *
 * GET  ?action=history&customer_id=N
 *   -> { ok, customer_name, sends: [{id, document_filename, contact_name,
 *        contact_email, sent_by_name, sent_at, subject}, ...] }
 *      (newest first) -- "which files have been sent to each company
 *      already."
 */

require_once __DIR__ . '/_util.php';
require_once __DIR__ . '/documents-core.php';
require_once __DIR__ . '/../../relationships/api/db.php';
require_once __DIR__ . '/../../relationships/api/contacts-admin-core.php';

$pdo = marketing_db();
$user = marketing_require_login();
$relPdo = relationships_db();

$action = $_GET['action'] ?? '';

if ($action === 'company_search') {
    $q = trim((string) ($_GET['q'] ?? ''));
    if ($q === '') {
        marketing_respond(200, ['ok' => true, 'companies' => []]);
    }
    $stmt = $relPdo->prepare(
        "SELECT id, name FROM customers WHERE COALESCE(is_duplicate_hidden, 0) = 0 AND name LIKE :q ORDER BY name ASC LIMIT 25"
    );
    $stmt->execute([':q' => '%' . $q . '%']);
    $companies = array_map(static function (array $r): array {
        return ['id' => (int) $r['id'], 'name' => $r['name']];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    marketing_respond(200, ['ok' => true, 'companies' => $companies]);
}

if ($action === 'company_contacts') {
    $customerId = (int) ($_GET['customer_id'] ?? 0);
    if ($customerId <= 0) {
        marketing_respond(400, ['ok' => false, 'error' => 'Missing customer_id.']);
    }
    // Unrestricted on purpose -- see this file's header.
    $result = relationships_contacts_admin_list_for_customer($relPdo, null, $customerId);
    marketing_respond(200, ['ok' => true] + $result);
}

if ($action === 'send') {
    $body = marketing_read_json_body();
    $documentId = (int) ($body['document_id'] ?? 0);
    $customerId = (int) ($body['customer_id'] ?? 0);
    $customerName = trim((string) ($body['customer_name'] ?? ''));
    $contactId = isset($body['contact_id']) ? (int) $body['contact_id'] : null;
    $contactName = trim((string) ($body['contact_name'] ?? ''));
    $contactEmail = trim((string) ($body['contact_email'] ?? ''));
    $subject = trim((string) ($body['subject'] ?? ''));
    $note = trim((string) ($body['note'] ?? ''));

    if ($documentId <= 0 || $customerId <= 0 || $contactEmail === '') {
        marketing_respond(400, ['ok' => false, 'error' => 'A file, a company, and a contact email are required.']);
    }
    if (!filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
        marketing_respond(400, ['ok' => false, 'error' => 'That doesn\'t look like a valid email address.']);
    }

    $stmt = $pdo->prepare('SELECT * FROM documents WHERE id = :id AND deleted_at IS NULL');
    $stmt->execute([':id' => $documentId]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($doc === false) {
        marketing_respond(404, ['ok' => false, 'error' => 'Document not found.']);
    }
    $path = marketing_document_path($doc);
    if (!is_file($path)) {
        marketing_respond(404, ['ok' => false, 'error' => 'File is missing on disk.']);
    }
    $sizeBytes = (int) $doc['size_bytes'];
    if ($sizeBytes > MARKETING_EMAIL_ATTACHMENT_MAX_BYTES) {
        $capMb = (int) (MARKETING_EMAIL_ATTACHMENT_MAX_BYTES / (1024 * 1024));
        marketing_respond(400, ['ok' => false, 'error' => "That file is too large to email (over {$capMb} MB). Download it and share it another way."]);
    }

    $configPath = __DIR__ . '/../../mail/mail-config.php';
    if (!is_file($configPath)) {
        marketing_respond(500, ['ok' => false, 'error' => 'Mail is not configured on this server yet (mail/mail-config.php is missing).']);
    }
    /** @var array $config */
    $config = require $configPath;

    if ($subject === '') {
        $subject = $doc['original_filename'] . ' — from CodeBlue Technology';
    }
    $subject = str_replace(["\r", "\n"], ' ', $subject);

    $greeting = $contactName !== '' ? 'Hi ' . $contactName . ',' : 'Hello,';
    $noteHtml = $note !== '' ? '<p>' . nl2br(htmlspecialchars($note, ENT_QUOTES)) . '</p>' : '';
    $html = '<p>' . htmlspecialchars($greeting, ENT_QUOTES) . '</p>'
        . $noteHtml
        . '<p>Please find <strong>' . htmlspecialchars($doc['original_filename'], ENT_QUOTES) . '</strong> attached.</p>'
        . '<p>Thanks,<br>' . htmlspecialchars($user['name'], ENT_QUOTES) . '<br>CodeBlue Technology</p>';

    $mime = marketing_document_types()[$doc['ext']] ?? 'application/octet-stream';
    $bytes = file_get_contents($path);
    if ($bytes === false) {
        marketing_respond(500, ['ok' => false, 'error' => 'Could not read the file to attach it.']);
    }
    $attachments = [[
        'name' => $doc['original_filename'],
        'contentType' => $mime,
        'contentBytes' => base64_encode($bytes),
    ]];

    require __DIR__ . '/../../mail/graph-mailer.php';
    try {
        $mailer = new GraphMailer(
            tenantId: (string) $config['tenant_id'],
            clientId: (string) $config['client_id'],
            clientSecret: (string) $config['client_secret'],
            senderUserId: (string) $config['sender'],
        );
        $fromName = (string) ($config['from_name'] ?? 'CodeBlue Technology');
        $mailer->send(
            toEmail: $contactEmail,
            subject: $subject,
            body: $html,
            bccEmail: null,
            fromDisplayName: $fromName,
            isHtml: true,
            attachments: $attachments,
            replyToEmail: $user['email'] !== '' ? $user['email'] : null,
        );
    } catch (Throwable $e) {
        error_log('[marketing/send] ' . $e->getMessage());
        marketing_respond(502, ['ok' => false, 'error' => 'Could not send the email right now. Please try again shortly.']);
    }

    $pdo->prepare(
        'INSERT INTO send_log (document_id, document_filename, customer_id, customer_name, contact_id, contact_name, contact_email, sent_by_name, sent_by_email, subject, note, sent_at)
         VALUES (:did, :dfn, :cid, :cname, :coid, :coname, :coemail, :sbn, :sbe, :subj, :note, :t)'
    )->execute([
        ':did' => $documentId,
        ':dfn' => $doc['original_filename'],
        ':cid' => $customerId,
        ':cname' => $customerName,
        ':coid' => $contactId,
        ':coname' => $contactName,
        ':coemail' => $contactEmail,
        ':sbn' => $user['name'],
        ':sbe' => $user['email'],
        ':subj' => $subject,
        ':note' => $note,
        ':t' => gmdate('Y-m-d\TH:i:s\Z'),
    ]);

    marketing_respond(200, ['ok' => true]);
}

if ($action === 'history') {
    $customerId = (int) ($_GET['customer_id'] ?? 0);
    if ($customerId <= 0) {
        marketing_respond(400, ['ok' => false, 'error' => 'Missing customer_id.']);
    }
    $stmt = $pdo->prepare('SELECT * FROM send_log WHERE customer_id = :cid ORDER BY sent_at DESC, id DESC LIMIT 200');
    $stmt->execute([':cid' => $customerId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $customerName = $rows !== [] ? $rows[0]['customer_name'] : '';
    $sends = array_map(static function (array $r): array {
        return [
            'id' => (int) $r['id'],
            'document_filename' => $r['document_filename'],
            'contact_name' => $r['contact_name'],
            'contact_email' => $r['contact_email'],
            'sent_by_name' => $r['sent_by_name'],
            'sent_at' => $r['sent_at'],
            'subject' => $r['subject'],
        ];
    }, $rows);
    marketing_respond(200, ['ok' => true, 'customer_name' => $customerName, 'sends' => $sends]);
}

marketing_respond(400, ['ok' => false, 'error' => 'Unknown action.']);
