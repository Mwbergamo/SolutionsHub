<?php
/**
 * mail/send-quote.php
 *
 * POST endpoint used by the "Email Quote to Customer" button on the
 * Solution Summary screen (app.js: Component.sendQuoteByEmail()).
 *
 * Expects a JSON body:
 *   { "to": "customer@example.com", "companyName": "...", "subject": "...",
 *     "html": "...", "website": "" }
 * ("website" is a honeypot field — real browsers never fill it in because
 * app.js always sends it empty and no visible form field maps to it; a
 * populated value means a bot filled in every field it could find.)
 *
 * Responds with JSON: { "ok": true } or { "ok": false, "error": "..." }.
 *
 * Anti-abuse (intentionally lightweight, not bulletproof — see task notes):
 *   - only POST is accepted
 *   - caller must be signed in via the shared Microsoft 365 session (added
 *     2026-09-15 -- see auth/session.php; the root app itself is now
 *     behind that same sign-in, so this was previously reachable only
 *     through a page nothing unauthenticated could load anyway, but
 *     checking it here too means a direct POST to this endpoint can't
 *     bypass sign-in and send mail as CodeBlue)
 *   - Origin/Referer must match mail-config.php's allowed_origins
 *   - honeypot field must be empty
 *   - "to" must look like an email address
 *   - body size is capped
 *
 * Mail is sent via Microsoft Graph (see graph-mailer.php) using an Entra ID
 * app registration's app-only credentials -- no SMTP, no app password.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

function respond(int $httpCode, array $payload): never
{
    http_response_code($httpCode);
    echo json_encode($payload);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(405, ['ok' => false, 'error' => 'Method not allowed.']);
}

require_once __DIR__ . '/../auth/session.php';
if (auth_current_user() === null) {
    respond(401, ['ok' => false, 'error' => 'Not signed in.']);
}

$configPath = __DIR__ . '/mail-config.php';
if (!is_file($configPath)) {
    // Fails soft with a clear message rather than a raw PHP warning/500 —
    // this is the expected state until the site owner copies
    // mail-config.sample.php to mail-config.php and fills it in.
    respond(500, ['ok' => false, 'error' => 'Mail is not configured on this server yet (mail/mail-config.php is missing).']);
}
/** @var array $config */
$config = require $configPath;

// ---- same-origin / minimal anti-abuse check --------------------------------
$allowedOrigins = $config['allowed_origins'] ?? [];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$referer = $_SERVER['HTTP_REFERER'] ?? '';
$refererOrigin = '';
if ($referer !== '') {
    $parts = parse_url($referer);
    if ($parts !== false && isset($parts['scheme'], $parts['host'])) {
        $refererOrigin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }
}
if (!empty($allowedOrigins)) {
    $ok = in_array($origin, $allowedOrigins, true) || in_array($refererOrigin, $allowedOrigins, true);
    if (!$ok) {
        respond(403, ['ok' => false, 'error' => 'Request origin not allowed.']);
    }
}

// ---- parse + validate body --------------------------------------------------
$raw = file_get_contents('php://input', false, null, 0, 6291456); // 6 MB cap: the HTML body plus, for Inside Sales, the inline camera-layout pictures
$data = json_decode((string) $raw, true);
if (!is_array($data)) {
    respond(400, ['ok' => false, 'error' => 'Invalid request body.']);
}

// Honeypot: must be present-and-empty. A real submission from app.js always
// sends "" for this field.
if (!empty($data['website'] ?? '')) {
    // Pretend success so a bot gets no signal that it was caught.
    respond(200, ['ok' => true]);
}

$to = trim((string) ($data['to'] ?? ''));
$companyName = trim((string) ($data['companyName'] ?? ''));
$subject = trim((string) ($data['subject'] ?? 'Your CodeBlue Technology Quote'));
$html = (string) ($data['html'] ?? '');

// Optional inline pictures (the camera-layout photo grid in the Inside Sales email). Each is
// { cid, name, contentType:'image/jpeg', data:<base64> } and is referenced from the HTML as cid:<cid>.
$attachments = [];
$images = $data['images'] ?? [];
if (is_array($images) && $images !== []) {
    if (count($images) > 60) {
        respond(400, ['ok' => false, 'error' => 'Too many photos to email (limit 60).']);
    }
    $totalBytes = 0;
    foreach ($images as $img) {
        if (!is_array($img)) {
            continue;
        }
        $cid = preg_replace('/[^A-Za-z0-9_\-]/', '', (string) ($img['cid'] ?? ''));
        $b64 = (string) ($img['data'] ?? '');
        $bytes = base64_decode($b64, true);
        if ($cid === '' || $bytes === false || $bytes === '' || substr($bytes, 0, 3) !== "\xFF\xD8\xFF") {
            respond(400, ['ok' => false, 'error' => 'One of the photos could not be read.']);
        }
        $totalBytes += strlen($bytes);
        $name = preg_replace('/[^A-Za-z0-9._\- ]/', '', (string) ($img['name'] ?? ($cid . '.jpg'))) ?: ($cid . '.jpg');
        $attachments[] = ['name' => $name, 'contentType' => 'image/jpeg', 'contentBytes' => base64_encode($bytes), 'contentId' => $cid];
    }
    // Graph's sendMail request must stay well under its ~4 MB request limit.
    if ($totalBytes > 2600000) {
        respond(400, ['ok' => false, 'error' => 'The photos are too large to email. Remove some photos and try again.']);
    }
}

if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
    respond(400, ['ok' => false, 'error' => 'A valid customer email address is required.']);
}
if ($html === '') {
    respond(400, ['ok' => false, 'error' => 'Quote body is empty.']);
}
if (strlen($html) > 600000) {
    respond(400, ['ok' => false, 'error' => 'Quote body is too large.']);
}
if ($subject === '') {
    $subject = 'Your CodeBlue Technology Quote';
}
// Header-injection guard: subject travels into a raw header line.
$subject = str_replace(["\r", "\n"], ' ', $subject);

require __DIR__ . '/graph-mailer.php';

try {
    $mailer = new GraphMailer(
        tenantId: (string) $config['tenant_id'],
        clientId: (string) $config['client_id'],
        clientSecret: (string) $config['client_secret'],
        senderUserId: (string) $config['sender'],
    );
    $fromName = (string) ($config['from_name'] ?? 'CodeBlue Technology');
    $bcc = trim((string) ($config['bcc'] ?? ''));

    $mailer->send($to, $subject, $html, $bcc !== '' ? $bcc : null, $fromName, true, $attachments);

    respond(200, ['ok' => true]);
} catch (Throwable $e) {
    // Never leak Graph credentials or internal exception details to the client.
    error_log('[send-quote] ' . $e->getMessage());
    respond(502, ['ok' => false, 'error' => 'Could not send the email right now. Please try again shortly.']);
}
