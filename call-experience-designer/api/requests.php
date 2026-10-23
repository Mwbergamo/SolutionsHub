<?php
/**
 * call-experience-designer/api/requests.php
 *
 * Rep-facing endpoints for the Call In Experience Designer app (added
 * 2026-10-23, per Michael). Every action requires a signed-in rep
 * (shared Microsoft 365 SSO -- see _util.php). Unlike ratesheet/register/
 * relationships, there is no admin/roster visibility split here --
 * AskUserQuestion, 2026-10-23, confirmed every signed-in rep sees every
 * request on the dashboard, and anyone signed in can send one.
 *
 * GET  /call-experience-designer/api/requests.php?action=list
 *   -> { ok: true, requests: [ { id, token, customer_email,
 *        company_name, status, rep_name, rep_email,
 *        notify_email_status, sent_at, submitted_at, tracks_recorded,
 *        tracks_total }, ... ] }
 *
 * GET  /call-experience-designer/api/requests.php?action=detail&id=1
 *   -> { ok: true, request: { ...same fields..., tracks: [ { track_key,
 *        label, date_start, date_stop, has_file, source,
 *        original_filename, size_bytes, uploaded_at }, ... (4, fixed
 *        order -- see call_experience_track_defs()) ] } }
 *   Used by receipt.html -- the authenticated page a rep opens (from the
 *   dashboard, or from the service@ notification email) to review and
 *   play/download what a customer recorded or uploaded.
 *
 * GET  /call-experience-designer/api/requests.php?action=track_audio
 *        &id=1&track=main_greeting[&download=1]
 *   Streams that track's WAV file -- inline (for receipt.html's <audio>
 *   player) unless download=1 (a real file download). This is the
 *   rep-side, LOGIN-GATED counterpart to public.php's own
 *   ?action=track_audio, which is token-gated instead (a customer mid-
 *   session has no SolutionsHub account to log in with). The service@
 *   notification email links here (via receipt.html), not to a second
 *   public token, per the security refinement Michael was told about
 *   2026-10-23: service@ is internal CodeBlue staff who can sign in, so
 *   an authenticated link is safer than an eternally-valid public one
 *   for a finished recording.
 *
 * POST /call-experience-designer/api/requests.php?action=send
 *   { customer_email }
 *   Creates the row with a random token (sending rep = whoever is
 *   signed in -- there is no Sending Representative picker here, unlike
 *   ratesheet, since this app has no admin-on-behalf-of workflow) and
 *   emails the customer a link to the public recording page (see
 *   public.php) carrying that token.
 *   -> { ok: true, request: {...} }
 */

declare(strict_types=1);

require_once __DIR__ . '/_util.php';

call_experience_install_error_handlers();

$pdo = call_experience_db();
$user = call_experience_require_login($pdo);

$action = $_GET['action'] ?? '';

const CALL_EXPERIENCE_SENDER = 'Hello@codebluetechnology.com';
const CALL_EXPERIENCE_FROM_NAME = 'CodeBlue Technology';
const CALL_EXPERIENCE_LOGO_URL = 'https://portal.codebluetechnology.com/assets/email/codeblue-logo.png';
// SolutionsHub site root is two levels up from /call-experience-designer/api/.
const CALL_EXPERIENCE_SITE_BASE = 'https://portal.codebluetechnology.com';

/**
 * The 4 fixed tracks for one request, in call_experience_track_defs()
 * order, merged with whatever call_experience_tracks rows already exist
 * (a track with no row yet -- never recorded/uploaded/dated -- renders
 * as all-null/false). Shared by ?action=list (counts only) and
 * ?action=detail (full array) below.
 *
 * @return list<array{track_key:string,label:string,date_start:?string,date_stop:?string,has_file:bool,source:?string,original_filename:?string,size_bytes:?int,uploaded_at:?string}>
 */
function call_experience_track_summary(PDO $pdo, int $requestId): array
{
    $stmt = $pdo->prepare('SELECT * FROM call_experience_tracks WHERE request_id = :rid');
    $stmt->execute([':rid' => $requestId]);
    $byKey = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $byKey[$row['track_key']] = $row;
    }

    $out = [];
    foreach (call_experience_track_defs() as $key => $label) {
        $row = $byKey[$key] ?? null;
        $out[] = [
            'track_key' => $key,
            'label' => $label,
            'date_start' => $row['date_start'] ?? null,
            'date_stop' => $row['date_stop'] ?? null,
            'has_file' => $row !== null && $row['stored_filename'] !== null,
            'source' => $row['source'] ?? null,
            'original_filename' => $row['original_filename'] ?? null,
            'size_bytes' => ($row !== null && $row['size_bytes'] !== null) ? (int) $row['size_bytes'] : null,
            'uploaded_at' => $row['uploaded_at'] ?? null,
        ];
    }
    return $out;
}

function call_experience_request_row(array $r): array
{
    return [
        'id' => (int) $r['id'],
        'token' => $r['token'],
        'customer_email' => $r['customer_email'],
        'company_name' => $r['company_name'],
        'status' => $r['status'],
        'rep_name' => $r['rep_name'],
        'rep_email' => $r['rep_email'],
        'notify_email_status' => $r['notify_email_status'],
        'sent_at' => $r['sent_at'],
        'submitted_at' => $r['submitted_at'],
    ];
}

if ($action === 'list') {
    $stmt = $pdo->query('SELECT * FROM call_experience_requests ORDER BY sent_at DESC');
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $row = call_experience_request_row($r);
        $tracks = call_experience_track_summary($pdo, (int) $r['id']);
        $row['tracks_recorded'] = count(array_filter($tracks, static fn (array $t) => $t['has_file']));
        $row['tracks_total'] = count($tracks);
        $out[] = $row;
    }
    call_experience_respond(200, ['ok' => true, 'requests' => $out]);
}

if ($action === 'detail') {
    $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    if ($id <= 0) {
        call_experience_respond(400, ['ok' => false, 'error' => 'Missing id.']);
    }
    $stmt = $pdo->prepare('SELECT * FROM call_experience_requests WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($r === false) {
        call_experience_respond(404, ['ok' => false, 'error' => 'Request not found.']);
    }
    $row = call_experience_request_row($r);
    $row['tracks'] = call_experience_track_summary($pdo, $id);
    call_experience_respond(200, ['ok' => true, 'request' => $row]);
}

if ($action === 'track_audio') {
    $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    $trackKey = (string) ($_GET['track'] ?? '');
    $defs = call_experience_track_defs();
    if ($id <= 0 || !array_key_exists($trackKey, $defs)) {
        call_experience_respond(400, ['ok' => false, 'error' => 'Missing/invalid id or track.']);
    }
    $stmt = $pdo->prepare('SELECT * FROM call_experience_tracks WHERE request_id = :rid AND track_key = :tk');
    $stmt->execute([':rid' => $id, ':tk' => $trackKey]);
    $t = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($t === false || $t['stored_filename'] === null) {
        call_experience_respond(404, ['ok' => false, 'error' => 'No recording for that track yet.']);
    }
    $path = call_experience_track_dir($id) . '/' . $t['stored_filename'];
    if (!is_file($path)) {
        call_experience_respond(404, ['ok' => false, 'error' => 'That file is no longer on the server.']);
    }

    $download = ($_GET['download'] ?? '') === '1';
    $filename = str_replace(['"', "\r", "\n"], '', $defs[$trackKey]) . '.wav';
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: audio/wav');
    header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . $filename . '"');
    header('Content-Length: ' . (string) filesize($path));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=60');
    readfile($path);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    call_experience_respond(405, ['ok' => false, 'error' => 'Method not allowed.']);
}

if ($action === 'send') {
    $input = call_experience_read_json_body();
    $customerEmail = trim((string) ($input['customer_email'] ?? ''));
    if ($customerEmail === '' || !filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
        call_experience_respond(400, ['ok' => false, 'error' => 'A valid customer email address is required.']);
    }

    $token = bin2hex(random_bytes(24));
    $stmt = $pdo->prepare(
        'INSERT INTO call_experience_requests (token, created_by_user_id, rep_name, rep_email, customer_email)
         VALUES (:token, :uid, :rname, :remail, :cemail)'
    );
    $stmt->execute([
        ':token' => $token,
        ':uid' => $user['id'],
        ':rname' => $user['name'],
        ':remail' => $user['email'],
        ':cemail' => $customerEmail,
    ]);
    $requestId = (int) $pdo->lastInsertId();

    $recordUrl = CALL_EXPERIENCE_SITE_BASE . '/call-experience-designer/record.html?t=' . urlencode($token);

    $configPath = __DIR__ . '/../../mail/mail-config.php';
    if (!is_file($configPath)) {
        call_experience_respond(500, ['ok' => false, 'error' => 'The request was saved, but mail is not configured on this server yet (mail/mail-config.php is missing) -- send the link manually for now: ' . $recordUrl]);
    }
    /** @var array $config */
    $config = require $configPath;
    require_once __DIR__ . '/../../mail/graph-mailer.php';

    try {
        $mailer = new GraphMailer(
            tenantId: (string) $config['tenant_id'],
            clientId: (string) $config['client_id'],
            clientSecret: (string) $config['client_secret'],
            senderUserId: CALL_EXPERIENCE_SENDER,
        );
        $mailer->send(
            $customerEmail,
            'Record Your Phone System Greetings — CodeBlue Technology',
            call_experience_invite_email_html($recordUrl),
            null,
            CALL_EXPERIENCE_FROM_NAME,
            true
        );
    } catch (Throwable $e) {
        error_log('[call-experience-designer/requests] send email failed for request ' . $requestId . ': ' . $e->getMessage());
        call_experience_respond(502, ['ok' => false, 'error' => 'The request was saved, but the email to the customer could not be sent: ' . $e->getMessage() . ' — link: ' . $recordUrl]);
    }

    $stmt = $pdo->prepare('SELECT * FROM call_experience_requests WHERE id = :id');
    $stmt->execute([':id' => $requestId]);
    $row = call_experience_request_row($stmt->fetch(PDO::FETCH_ASSOC));
    $row['tracks'] = call_experience_track_summary($pdo, $requestId);
    call_experience_respond(200, ['ok' => true, 'request' => $row]);
}

call_experience_respond(400, ['ok' => false, 'error' => 'Unknown action.']);

/**
 * Renders the customer-facing "please record your greetings" email.
 * Same letterhead conventions (logo, colors, fonts, footer) as
 * ratesheet_rate_email_html() in ratesheet/api/requests.php, for brand
 * consistency across every SolutionsHub app that emails a public link.
 */
function call_experience_invite_email_html(string $recordUrl): string
{
    $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

    return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">' .
        '<title>Record Your Phone System Greetings</title></head>' .
        '<body style="margin:0;padding:0;background:#F4F5F7;">' .
        '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F4F5F7;padding:24px 0;">' .
        '<tr><td align="center">' .
        '<table role="presentation" width="640" cellpadding="0" cellspacing="0" style="width:640px;max-width:640px;background:#FFFFFF;border-radius:8px;border:1px solid #E2E5EA;">' .

        '<tr><td style="padding:24px 24px 20px 24px;border-bottom:3px solid #182857;">' .
        '<img src="' . $e(CALL_EXPERIENCE_LOGO_URL) . '" width="200" alt="CodeBlue Technology" style="display:block;height:auto;width:200px;border:0;" />' .
        '</td></tr>' .

        '<tr><td style="padding:20px 24px 8px 24px;">' .
        '<div style="font-size:20px;font-family:Arial,Helvetica,sans-serif;font-weight:800;color:#182857;">Let\'s Set Up Your Phone System Greetings</div>' .
        '<div style="margin-top:8px;font-size:13px;font-family:Arial,Helvetica,sans-serif;color:#5A6472;line-height:1.5;">' .
        'To finish configuring your phone system, we need four short recordings: your Main System Greeting, Phone Tree Menu, General Holiday Message, and Emergency Message. ' .
        'Use the secure page below to record each one right from your browser, or upload your own audio file instead. You can also set an optional start and stop date for any message that should only play during a specific window.' .
        '</div>' .
        '</td></tr>' .

        '<tr><td style="padding:4px 24px 28px 24px;text-align:center;">' .
        '<a href="' . $e($recordUrl) . '" style="display:inline-block;background:#182857;color:#FFFFFF;text-decoration:none;font-family:Arial,Helvetica,sans-serif;font-size:14px;font-weight:700;padding:14px 28px;border-radius:6px;">Record Your Greetings</a>' .
        '<div style="margin-top:10px;font-size:11.5px;font-family:Arial,Helvetica,sans-serif;color:#8A93A3;">This link is unique to your business — please don\'t forward it.</div>' .
        '</td></tr>' .

        '<tr><td style="padding:16px 24px;background:#F4F5F7;border-top:1px solid #E2E5EA;text-align:center;">' .
        '<div style="font-size:11.5px;font-family:Arial,Helvetica,sans-serif;color:#7A8393;">CodeBlue Technology &nbsp;|&nbsp; (804) 521-7660 &nbsp;|&nbsp; Service@codebluetechnology.com</div>' .
        '</td></tr>' .
        '</table>' .
        '</td></tr>' .
        '</table>' .
        '</body></html>';
}
