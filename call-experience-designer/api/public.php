<?php
/**
 * call-experience-designer/api/public.php
 *
 * The customer-facing, LOGIN-FREE side of the Call In Experience
 * Designer (added 2026-10-23, per Michael). A rep sends a customer a
 * tokenized link (requests.php's ?action=send); this endpoint is the
 * ONLY thing that link talks to -- same shape as ratesheet/api/
 * public.php's token-gated signup flow, except there's no "customer"
 * account here either: the token itself IS the access control for the
 * whole session, same convention.
 *
 * GET  ?action=context&t=<token>
 *   -> { ok: true, request: { company_name, status, tracks: [ {
 *        track_key, label, date_start, time_start, date_stop, time_stop, has_file, source,
 *        original_filename, size_bytes }, ... (4, fixed order) ] } }
 *   record.html calls this on load (and after submit) to render/restore
 *   state -- recording/uploading/dating is saved server-side as the
 *   customer goes, so a reload mid-session picks back up where they
 *   left off.
 *
 * GET  ?action=track_audio&t=<token>&track=<key>
 *   Streams that track's WAV file inline, for the customer's own
 *   playback of what they just recorded/uploaded (the Play button).
 *   Token-gated, not login-gated -- this is the public counterpart to
 *   requests.php's own ?action=track_audio (which is login-gated, for
 *   the rep-side receipt.html instead). Works before AND after submit,
 *   since a customer revisiting their own link should still be able to
 *   hear back what they sent.
 *
 * POST ?action=upload   { t, track, source }  + multipart file=<wav>
 *   Records or uploads one track (source distinguishes the two only for
 *   display -- the stored file is treated identically either way).
 *   Overwrites whatever was there before for that track (re-recording
 *   replaces, it doesn't add a second file). 405s with a clear "already
 *   submitted" error once status = 'submitted' -- same post-submission
 *   lock convention as ratesheet's signup page.
 *
 * POST ?action=clear   { t, track }
 *   Deletes that track's file from disk and clears its file columns
 *   (stored_filename/original_filename/source/size_bytes/uploaded_at),
 *   leaving date_start/date_stop untouched -- Michael's spec: "Clear
 *   removes the last track recorded and allows the user to record or
 *   upload again," nothing about the dates.
 *
 * POST ?action=save_dates   { t, track, date_start, time_start, date_stop, time_stop }
 *   Saves (or clears, if blank) the optional date/time start/stop
 *   window for one track, independent of whether a file exists yet for
 *   it -- "both fields are not required but available" (Michael).
 *   date_start/date_stop are 'YYYY-MM-DD' strings from the page's
 *   native <input type="date">; time_start/time_stop are 'HH:MM'
 *   (24-hour) strings from <input type="time"> -- added 2026-10-27 per
 *   Michael, so each boundary can carry an hour/minute (AM/PM-displayed)
 *   component, not just a date. Any of the 4 may be null.
 *
 * POST ?action=submit   { t, company_name }
 *   Finalizes the request: stores company_name, flips status to
 *   'submitted', stamps submitted_at/ip_address, and emails
 *   service@codebluetechnology.com (CALL_EXPERIENCE_NOTIFY_TO below) a
 *   notice with a link to review/play/download every track -- that
 *   link points at the AUTHENTICATED rep-side receipt.html (Microsoft
 *   SSO-gated), not a second public token. Security refinement beyond
 *   Michael's literal "email with links to download each track" answer
 *   (AskUserQuestion, 2026-10-23): the recipients are internal CodeBlue
 *   staff who can already sign in, so a login-gated link is safer for
 *   finished customer audio than an eternally-valid public one. Michael
 *   is being told about this plainly, not left to discover it.
 */

declare(strict_types=1);

require_once __DIR__ . '/_util.php';

call_experience_install_error_handlers();

$pdo = call_experience_db();

const CALL_EXPERIENCE_TRACK_MAX_BYTES = 50 * 1024 * 1024; // 50 MB per track
const CALL_EXPERIENCE_SENDER = 'Hello@codebluetechnology.com';
const CALL_EXPERIENCE_FROM_NAME = 'CodeBlue Technology';
const CALL_EXPERIENCE_NOTIFY_TO = 'service@codebluetechnology.com';
const CALL_EXPERIENCE_SITE_BASE = 'https://portal.codebluetechnology.com';

function call_experience_load_request_by_token(PDO $pdo, string $token): array
{
    if ($token === '') {
        call_experience_respond(400, ['ok' => false, 'error' => 'Missing link token.']);
    }
    $stmt = $pdo->prepare('SELECT * FROM call_experience_requests WHERE token = :t');
    $stmt->execute([':t' => $token]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($r === false) {
        call_experience_respond(404, ['ok' => false, 'error' => 'This link is not valid. Please ask your CodeBlue representative for a new one.']);
    }
    return $r;
}

/** Inserts an empty row for (request_id, track_key) if one doesn't exist yet -- every update below then operates on a guaranteed-present row. */
function call_experience_ensure_track_row(PDO $pdo, int $requestId, string $trackKey): void
{
    $pdo->prepare('INSERT INTO call_experience_tracks (request_id, track_key) VALUES (:rid, :tk) ON CONFLICT(request_id, track_key) DO NOTHING')
        ->execute([':rid' => $requestId, ':tk' => $trackKey]);
}

/** Same track-array shape as requests.php's call_experience_track_summary(), minus the fields only a signed-in rep should see. */
function call_experience_public_track_summary(PDO $pdo, int $requestId): array
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
            'time_start' => $row['time_start'] ?? null,
            'date_stop' => $row['date_stop'] ?? null,
            'time_stop' => $row['time_stop'] ?? null,
            'has_file' => $row !== null && $row['stored_filename'] !== null,
            'source' => $row['source'] ?? null,
            'original_filename' => $row['original_filename'] ?? null,
            'size_bytes' => ($row !== null && $row['size_bytes'] !== null) ? (int) $row['size_bytes'] : null,
        ];
    }
    return $out;
}

$action = $_GET['action'] ?? ($_POST['action'] ?? '');

if ($action === 'context') {
    $r = call_experience_load_request_by_token($pdo, (string) ($_GET['t'] ?? ''));
    call_experience_respond(200, ['ok' => true, 'request' => [
        'company_name' => $r['company_name'],
        'status' => $r['status'],
        'tracks' => call_experience_public_track_summary($pdo, (int) $r['id']),
    ]]);
}

if ($action === 'track_audio') {
    $r = call_experience_load_request_by_token($pdo, (string) ($_GET['t'] ?? ''));
    $trackKey = (string) ($_GET['track'] ?? '');
    $defs = call_experience_track_defs();
    if (!array_key_exists($trackKey, $defs)) {
        call_experience_respond(400, ['ok' => false, 'error' => 'Unknown track.']);
    }
    $stmt = $pdo->prepare('SELECT stored_filename FROM call_experience_tracks WHERE request_id = :rid AND track_key = :tk');
    $stmt->execute([':rid' => (int) $r['id'], ':tk' => $trackKey]);
    $t = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($t === false || $t['stored_filename'] === null) {
        call_experience_respond(404, ['ok' => false, 'error' => 'Nothing recorded for that message yet.']);
    }
    $path = call_experience_track_dir((int) $r['id']) . '/' . $t['stored_filename'];
    if (!is_file($path)) {
        call_experience_respond(404, ['ok' => false, 'error' => 'That recording is no longer on the server.']);
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: audio/wav');
    header('Content-Disposition: inline; filename="' . $defs[$trackKey] . '.wav"');
    header('Content-Length: ' . (string) filesize($path));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=60');
    readfile($path);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    call_experience_respond(405, ['ok' => false, 'error' => 'Method not allowed.']);
}

if ($action === 'upload') {
    $r = call_experience_load_request_by_token($pdo, (string) ($_POST['t'] ?? ''));
    if ($r['status'] === 'submitted') {
        call_experience_respond(409, ['ok' => false, 'error' => 'This request has already been submitted -- recordings can no longer be changed.']);
    }
    $trackKey = (string) ($_POST['track'] ?? '');
    $defs = call_experience_track_defs();
    if (!array_key_exists($trackKey, $defs)) {
        call_experience_respond(400, ['ok' => false, 'error' => 'Unknown track.']);
    }
    $source = (string) ($_POST['source'] ?? 'uploaded');
    if (!in_array($source, ['recorded', 'uploaded'], true)) {
        $source = 'uploaded';
    }

    if (!isset($_FILES['file'])) {
        call_experience_respond(400, ['ok' => false, 'error' => 'No audio file received.']);
    }
    $file = $_FILES['file'];
    if ((int) $file['error'] === UPLOAD_ERR_INI_SIZE || (int) $file['error'] === UPLOAD_ERR_FORM_SIZE) {
        call_experience_respond(400, ['ok' => false, 'error' => 'That file is too large for the server to accept.']);
    }
    if ((int) $file['error'] !== UPLOAD_ERR_OK) {
        call_experience_respond(400, ['ok' => false, 'error' => 'Upload failed (error code ' . (int) $file['error'] . '). Please try again.']);
    }
    if ((int) $file['size'] <= 0) {
        call_experience_respond(400, ['ok' => false, 'error' => 'That file is empty.']);
    }
    if ((int) $file['size'] > CALL_EXPERIENCE_TRACK_MAX_BYTES) {
        call_experience_respond(400, ['ok' => false, 'error' => 'That file is larger than the 50 MB limit.']);
    }
    $originalName = trim((string) $file['name']);
    if ($source === 'uploaded' && ($originalName === '' || strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION)) !== 'wav')) {
        call_experience_respond(400, ['ok' => false, 'error' => 'Only .wav files are accepted for upload.']);
    }
    // Cheap sanity check that this is actually a WAV container -- 'RIFF'
    // at byte 0, 'WAVE' at byte 8 -- rather than trusting the extension
    // alone. Same reasoning as risk-scans.php's zip magic-byte check.
    $magic = @file_get_contents($file['tmp_name'], false, null, 0, 12);
    if ($magic === false || substr($magic, 0, 4) !== 'RIFF' || substr($magic, 8, 4) !== 'WAVE') {
        call_experience_respond(400, ['ok' => false, 'error' => 'That file doesn’t look like a valid WAV recording.']);
    }

    $requestId = (int) $r['id'];
    $dir = call_experience_track_dir($requestId);
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        call_experience_respond(500, ['ok' => false, 'error' => 'Could not create storage folder for this request.']);
    }
    // Deterministic per-track filename -- re-recording/re-uploading the
    // same track overwrites it rather than accumulating files, matching
    // "the last track recorded" language in the spec.
    $storedName = $trackKey . '.wav';
    $dest = $dir . '/' . $storedName;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        call_experience_respond(500, ['ok' => false, 'error' => 'Could not save the recording.']);
    }

    call_experience_ensure_track_row($pdo, $requestId, $trackKey);
    $pdo->prepare(
        "UPDATE call_experience_tracks
         SET stored_filename = :stored, original_filename = :orig, source = :source,
             size_bytes = :size, uploaded_at = datetime('now'), updated_at = datetime('now')
         WHERE request_id = :rid AND track_key = :tk"
    )->execute([
        ':stored' => $storedName,
        ':orig' => $originalName !== '' ? $originalName : ($trackKey . '.wav'),
        ':source' => $source,
        ':size' => (int) $file['size'],
        ':rid' => $requestId,
        ':tk' => $trackKey,
    ]);

    call_experience_respond(200, ['ok' => true, 'tracks' => call_experience_public_track_summary($pdo, $requestId)]);
}

if ($action === 'clear') {
    $r = call_experience_load_request_by_token($pdo, (string) ($_POST['t'] ?? ''));
    if ($r['status'] === 'submitted') {
        call_experience_respond(409, ['ok' => false, 'error' => 'This request has already been submitted -- recordings can no longer be changed.']);
    }
    $trackKey = (string) ($_POST['track'] ?? '');
    $defs = call_experience_track_defs();
    if (!array_key_exists($trackKey, $defs)) {
        call_experience_respond(400, ['ok' => false, 'error' => 'Unknown track.']);
    }
    $requestId = (int) $r['id'];

    $stmt = $pdo->prepare('SELECT stored_filename FROM call_experience_tracks WHERE request_id = :rid AND track_key = :tk');
    $stmt->execute([':rid' => $requestId, ':tk' => $trackKey]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($existing !== false && $existing['stored_filename'] !== null) {
        $path = call_experience_track_dir($requestId) . '/' . $existing['stored_filename'];
        if (is_file($path)) {
            @unlink($path);
        }
    }

    call_experience_ensure_track_row($pdo, $requestId, $trackKey);
    $pdo->prepare(
        "UPDATE call_experience_tracks
         SET stored_filename = NULL, original_filename = NULL, source = NULL, size_bytes = NULL, uploaded_at = NULL, updated_at = datetime('now')
         WHERE request_id = :rid AND track_key = :tk"
    )->execute([':rid' => $requestId, ':tk' => $trackKey]);

    call_experience_respond(200, ['ok' => true, 'tracks' => call_experience_public_track_summary($pdo, $requestId)]);
}

if ($action === 'save_dates') {
    $r = call_experience_load_request_by_token($pdo, (string) ($_POST['t'] ?? ''));
    if ($r['status'] === 'submitted') {
        call_experience_respond(409, ['ok' => false, 'error' => 'This request has already been submitted -- dates can no longer be changed.']);
    }
    $trackKey = (string) ($_POST['track'] ?? '');
    $defs = call_experience_track_defs();
    if (!array_key_exists($trackKey, $defs)) {
        call_experience_respond(400, ['ok' => false, 'error' => 'Unknown track.']);
    }

    $dateStart = call_experience_normalize_date($_POST['date_start'] ?? null);
    $timeStart = call_experience_normalize_date($_POST['time_start'] ?? null);
    $dateStop = call_experience_normalize_date($_POST['date_stop'] ?? null);
    $timeStop = call_experience_normalize_date($_POST['time_stop'] ?? null);
    $requestId = (int) $r['id'];

    call_experience_ensure_track_row($pdo, $requestId, $trackKey);
    $pdo->prepare(
        "UPDATE call_experience_tracks
         SET date_start = :ds, time_start = :ts, date_stop = :de, time_stop = :te, updated_at = datetime('now')
         WHERE request_id = :rid AND track_key = :tk"
    )->execute([':ds' => $dateStart, ':ts' => $timeStart, ':de' => $dateStop, ':te' => $timeStop, ':rid' => $requestId, ':tk' => $trackKey]);

    call_experience_respond(200, ['ok' => true, 'tracks' => call_experience_public_track_summary($pdo, $requestId)]);
}

if ($action === 'submit') {
    $r = call_experience_load_request_by_token($pdo, (string) ($_POST['t'] ?? ''));
    if ($r['status'] === 'submitted') {
        call_experience_respond(200, ['ok' => true, 'already_submitted' => true]);
    }
    $companyName = trim((string) ($_POST['company_name'] ?? ''));
    if ($companyName === '') {
        call_experience_respond(400, ['ok' => false, 'error' => 'Company Name is required before submitting.']);
    }

    $requestId = (int) $r['id'];
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $pdo->prepare(
        "UPDATE call_experience_requests
         SET company_name = :company, status = 'submitted', submitted_at = datetime('now'), ip_address = :ip
         WHERE id = :id"
    )->execute([':company' => $companyName, ':ip' => $ip, ':id' => $requestId]);

    $notifyStatus = 'sent';
    try {
        $tracks = call_experience_public_track_summary($pdo, $requestId);
        call_experience_send_submit_notification($requestId, $companyName, (string) $r['rep_name'], (string) $r['rep_email'], (string) $r['customer_email'], $tracks);
    } catch (Throwable $e) {
        $notifyStatus = 'failed';
        error_log('[call-experience-designer/public] submit notification failed for request ' . $requestId . ': ' . $e->getMessage());
    }
    $pdo->prepare('UPDATE call_experience_requests SET notify_email_status = :s WHERE id = :id')
        ->execute([':s' => $notifyStatus, ':id' => $requestId]);

    call_experience_respond(200, ['ok' => true]);
}

call_experience_respond(400, ['ok' => false, 'error' => 'Unknown action.']);

/**
 * '' and missing both normalize to NULL; otherwise pass the value
 * straight through -- used for both the 'YYYY-MM-DD' date fields and the
 * 'HH:MM' (24-hour) time fields added 2026-10-27, since the native
 * <input type="date">/<input type="time"> elements already constrain
 * each one's format.
 */
function call_experience_normalize_date(mixed $value): ?string
{
    $s = trim((string) ($value ?? ''));
    return $s === '' ? null : $s;
}

/**
 * Emails CALL_EXPERIENCE_NOTIFY_TO (service@codebluetechnology.com) once
 * a customer submits, linking to the authenticated rep-side receipt.html
 * -- see this file's header docblock for why that's a login-gated link
 * rather than a second public token.
 */
function call_experience_send_submit_notification(int $requestId, string $companyName, string $repName, string $repEmail, string $customerEmail, array $tracks): void
{
    $configPath = __DIR__ . '/../../mail/mail-config.php';
    if (!is_file($configPath)) {
        throw new RuntimeException('mail/mail-config.php is missing.');
    }
    /** @var array $config */
    $config = require $configPath;
    require_once __DIR__ . '/../../mail/graph-mailer.php';

    $receiptUrl = CALL_EXPERIENCE_SITE_BASE . '/call-experience-designer/receipt.html?id=' . $requestId;
    $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

    $body = '<!doctype html><html><head><meta charset="utf-8"></head>' .
        '<body style="margin:0;padding:0;background:#F4F5F7;font-family:Arial,Helvetica,sans-serif;">' .
        '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F4F5F7;padding:24px 0;">' .
        '<tr><td align="center">' .
        '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:600px;max-width:600px;background:#FFFFFF;border-radius:8px;border:1px solid #E2E5EA;">' .
        '<tr><td style="padding:20px 24px;border-bottom:3px solid #182857;">' .
        '<div style="font-size:18px;font-weight:800;color:#182857;">New Call In Experience Submission</div>' .
        '</td></tr>' .
        '<tr><td style="padding:20px 24px;font-size:13.5px;color:#33394A;line-height:1.6;">' .
        '<strong>Company:</strong> ' . $e($companyName) . '<br>' .
        '<strong>Customer email:</strong> ' . $e($customerEmail) . '<br>' .
        '<strong>Sent by:</strong> ' . $e($repName) . ' (' . $e($repEmail) . ')<br><br>' .
        'All four messages (whichever were recorded or uploaded) are ready to review, play, and download.' .
        '</td></tr>' .
        '<tr><td style="padding:0 24px 20px 24px;">' .
        '<div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#8A93A3;margin-bottom:6px;">Messages &amp; Date/Time Windows</div>' .
        '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:12.5px;color:#33394A;">' .
        implode('', array_map(static function (array $t) use ($e) {
            $fileLabel = $t['has_file'] ? ($t['source'] === 'recorded' ? 'Recorded' : 'Uploaded') : 'Not recorded yet';
            $window = call_experience_format_track_window($t['date_start'], $t['time_start'], $t['date_stop'], $t['time_stop']);
            return '<tr><td style="padding:6px 0;border-top:1px solid #EEF0F3;">' .
                '<strong>' . $e($t['label']) . '</strong> — ' . $e($fileLabel) . '<br>' .
                '<span style="color:#5A6472;">' . $e($window) . '</span>' .
                '</td></tr>';
        }, $tracks)) .
        '</table>' .
        '</td></tr>' .
        '<tr><td style="padding:4px 24px 24px 24px;text-align:center;">' .
        '<a href="' . $e($receiptUrl) . '" style="display:inline-block;background:#182857;color:#FFFFFF;text-decoration:none;font-size:14px;font-weight:700;padding:12px 24px;border-radius:6px;">Review This Submission</a>' .
        '<div style="margin-top:8px;font-size:11px;color:#8A93A3;">Sign in with your CodeBlue Microsoft 365 account to open it.</div>' .
        '</td></tr>' .
        '</table></td></tr></table></body></html>';

    $mailer = new GraphMailer(
        tenantId: (string) $config['tenant_id'],
        clientId: (string) $config['client_id'],
        clientSecret: (string) $config['client_secret'],
        senderUserId: CALL_EXPERIENCE_SENDER,
    );
    $mailer->send(CALL_EXPERIENCE_NOTIFY_TO, 'New Call In Experience Submission — ' . $companyName, $body, null, CALL_EXPERIENCE_FROM_NAME, true);
}

/**
 * Formats one track's optional start/stop window for the submit
 * notification email, e.g. "Oct 23, 2026 at 2:30 PM → Oct 24, 2026 at 8:00 AM"
 * -- added 2026-10-27 per Michael, so the hour/minute (AM/PM) component
 * set on record.html actually carries over to what service@ sees, not
 * just the date. A boundary with a date but no time shows the date
 * alone; a boundary with neither shows nothing for that side.
 */
function call_experience_format_track_window(?string $dateStart, ?string $timeStart, ?string $dateStop, ?string $timeStop): string
{
    $fmt = static function (?string $date, ?string $time): ?string {
        if ($date === null || $date === '') {
            return null;
        }
        $dt = DateTime::createFromFormat('Y-m-d' . ($time ? ' H:i' : ''), $date . ($time ? ' ' . $time : ''));
        if ($dt === false) {
            return $date . ($time ? ' ' . $time : '');
        }
        return $dt->format($time ? 'M j, Y \a\t g:i A' : 'M j, Y');
    };

    $start = $fmt($dateStart, $timeStart);
    $stop = $fmt($dateStop, $timeStop);

    if ($start === null && $stop === null) {
        return 'No date/time window set — always in use';
    }
    return ($start ?? 'Any time') . ' → ' . ($stop ?? 'Until further notice');
}
