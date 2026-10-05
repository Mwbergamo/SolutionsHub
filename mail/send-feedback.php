<?php
/**
 * mail/send-feedback.php
 *
 * POST endpoint behind the "Help / Suggestions" button in the SolutionsHub
 * header (app.js: Component.sendHelpMessage()). Emails the message -- plus any
 * attached screenshots -- to the feedback inbox (Mbergamo@codebluetechnology.com
 * unless mail-config.php sets 'feedback_to').
 *
 * Expects a JSON body:
 *   { "type": "suggestion|issue|help|other", "message": "...",
 *     "replyEmail": "", "attachments": [{ "name", "type", "data": base64 }],
 *     "context": { "url", "view", "viewport", "userAgent" }, "website": "" }
 *
 * Responds with JSON: { "ok": true } or { "ok": false, "error": "..." }.
 *
 * The recipient is fixed server-side (never taken from the request), so this
 * cannot be used to send mail to arbitrary addresses. Same lightweight
 * anti-abuse as send-quote.php: POST only, signed-in session required,
 * Origin/Referer check, honeypot, size caps -- plus strict attachment checks
 * (image types only, verified by content, count and size limited).
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
$user = auth_current_user();
if ($user === null) {
    respond(401, ['ok' => false, 'error' => 'Not signed in.']);
}

$configPath = __DIR__ . '/mail-config.php';
if (!is_file($configPath)) {
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
$raw = file_get_contents('php://input', false, null, 0, 7340032); // 7 MB cap (base64 screenshots)
$data = json_decode((string) $raw, true);
if (!is_array($data)) {
    respond(400, ['ok' => false, 'error' => 'Invalid request body (it may be too large -- try fewer or smaller screenshots).']);
}

if (!empty($data['website'] ?? '')) {
    respond(200, ['ok' => true]); // honeypot tripped: pretend success
}

$message = trim((string) ($data['message'] ?? ''));
if ($message === '') {
    respond(400, ['ok' => false, 'error' => 'Please type a message before sending.']);
}
if (mb_strlen($message) > 10000) {
    respond(400, ['ok' => false, 'error' => 'Message is too long (10,000 character limit).']);
}

$typeLabels = [
    'suggestion' => 'Feature Suggestion',
    'issue' => 'Issue / Error',
    'help' => 'Help Request',
    'other' => 'Other',
];
$typeKey = (string) ($data['type'] ?? 'other');
$typeLabel = $typeLabels[$typeKey] ?? $typeLabels['other'];

$replyTo = trim((string) ($data['replyEmail'] ?? ''));
if ($replyTo !== '' && !filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
    respond(400, ['ok' => false, 'error' => 'That reply-to email address does not look valid.']);
}
if ($replyTo === '' && filter_var($user['email'], FILTER_VALIDATE_EMAIL)) {
    $replyTo = $user['email'];
}

// ---- attachments (screenshots) ----------------------------------------------
$allowedMime = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp'];
$attachments = [];
$totalBytes = 0;
$rawAttachments = $data['attachments'] ?? [];
if (!is_array($rawAttachments)) {
    $rawAttachments = [];
}
if (count($rawAttachments) > 5) {
    respond(400, ['ok' => false, 'error' => 'You can attach up to 5 screenshots.']);
}
foreach ($rawAttachments as $i => $att) {
    if (!is_array($att)) {
        continue;
    }
    $bytes = base64_decode((string) ($att['data'] ?? ''), true);
    if ($bytes === false || $bytes === '') {
        respond(400, ['ok' => false, 'error' => 'One of the attachments could not be read.']);
    }
    if (strlen($bytes) > 3145728) { // 3 MB each (Graph's inline fileAttachment limit is ~3 MB)
        respond(400, ['ok' => false, 'error' => 'A screenshot is too large (3 MB limit each).']);
    }
    $totalBytes += strlen($bytes);
    // Trust the file's actual content, not the client-declared type.
    $info = @getimagesizefromstring($bytes);
    $mime = is_array($info) ? ($info['mime'] ?? '') : '';
    if (!isset($allowedMime[$mime])) {
        respond(400, ['ok' => false, 'error' => 'Only PNG, JPG, GIF or WebP images can be attached.']);
    }
    $base = pathinfo((string) ($att['name'] ?? ''), PATHINFO_FILENAME);
    $base = preg_replace('/[^A-Za-z0-9._ -]+/', '_', $base) ?? '';
    $base = trim($base, " ._-");
    if ($base === '') {
        $base = 'screenshot-' . ($i + 1);
    }
    $attachments[] = [
        'name' => substr($base, 0, 80) . '.' . $allowedMime[$mime],
        'contentType' => $mime,
        'contentBytes' => base64_encode($bytes),
    ];
}
if ($totalBytes > 4718592) { // 4.5 MB total
    respond(400, ['ok' => false, 'error' => 'Screenshots are too large together -- attach fewer or smaller images.']);
}

// ---- build the email --------------------------------------------------------
$esc = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$ctx = is_array($data['context'] ?? null) ? $data['context'] : [];
$ctxLine = static function (string $label, mixed $val) use ($esc): string {
    $val = trim((string) $val);
    if ($val === '') {
        return '';
    }
    $val = mb_substr(str_replace(["\r", "\n"], ' ', $val), 0, 400);
    return '<tr><td style="padding:2px 12px 2px 0;color:#5A6472;font-size:12px;font-family:Arial,Helvetica,sans-serif;white-space:nowrap;">' . $esc($label)
        . '</td><td style="padding:2px 0;color:#33394A;font-size:12px;font-family:Arial,Helvetica,sans-serif;">' . $esc($val) . '</td></tr>';
};

$fromName = trim((string) ($user['name'] ?? ''));
$fromLabel = $fromName !== '' ? $fromName . ' <' . $user['email'] . '>' : (string) $user['email'];

$html = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:640px;">'
    . '<div style="background:#182857;color:#ffffff;padding:14px 18px;font-size:16px;font-weight:bold;">SolutionsHub &mdash; ' . $esc($typeLabel) . '</div>'
    . '<div style="border:1px solid #D9DCE3;border-top:none;padding:18px;">'
    . '<table cellpadding="0" cellspacing="0" style="margin-bottom:14px;">'
    . $ctxLine('From', $fromLabel)
    . $ctxLine('Reply-To', $replyTo)
    . $ctxLine('Attachments', $attachments ? (string) count($attachments) . ' screenshot(s)' : 'none')
    . '</table>'
    . '<div style="font-size:14px;line-height:1.55;color:#1F2430;white-space:pre-wrap;">' . $esc($message) . '</div>'
    . '<hr style="border:none;border-top:1px solid #E6E8EE;margin:18px 0 10px;">'
    . '<div style="font-size:11px;font-weight:bold;color:#5A6472;text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px;">Page context</div>'
    . '<table cellpadding="0" cellspacing="0">'
    . $ctxLine('Page', $ctx['url'] ?? '')
    . $ctxLine('Screen', $ctx['view'] ?? '')
    . $ctxLine('Viewport', $ctx['viewport'] ?? '')
    . $ctxLine('Browser', $ctx['userAgent'] ?? '')
    . $ctxLine('Sent', gmdate('Y-m-d H:i') . ' UTC')
    . '</table></div></div>';

$firstLine = trim((string) strtok($message, "\r\n"));
$subject = '[SolutionsHub] ' . $typeLabel . ' - ' . mb_substr($firstLine, 0, 60) . (mb_strlen($firstLine) > 60 ? '...' : '');
$subject = str_replace(["\r", "\n"], ' ', $subject); // header-injection guard

require __DIR__ . '/graph-mailer.php';

try {
    $mailer = new GraphMailer(
        tenantId: (string) $config['tenant_id'],
        clientId: (string) $config['client_id'],
        clientSecret: (string) $config['client_secret'],
        senderUserId: (string) $config['sender'],
    );
    $fromDisplay = (string) ($config['from_name'] ?? 'Solutions Hub');
    $to = trim((string) ($config['feedback_to'] ?? '')) ?: 'Mbergamo@codebluetechnology.com';

    $mailer->send($to, $subject, $html, null, $fromDisplay, true, $attachments, $replyTo !== '' ? $replyTo : null);

    respond(200, ['ok' => true]);
} catch (Throwable $e) {
    error_log('[send-feedback] ' . $e->getMessage());
    respond(502, ['ok' => false, 'error' => 'Could not send your message right now. Please try again shortly.']);
}
