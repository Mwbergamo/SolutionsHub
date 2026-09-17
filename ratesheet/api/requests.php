<?php
/**
 * ratesheet/api/requests.php
 *
 * Rep-facing endpoints for the Customer Rate Sheet Sign Up app (added
 * 2026-09-17, per Michael). Both actions require a signed-in rep (shared
 * Microsoft 365 SSO -- see _util.php).
 *
 * GET  /ratesheet/api/requests.php?action=list
 *   -> { ok: true, requests: [...] }
 *   Role-based visibility per Michael: Michael, Trey, Kasie, Courtney,
 *   Claire, Jake and Casey (ratesheet_admin_emails()) see every rate
 *   sheet ever sent; everyone else (Moe, Chester, Walter, or anyone not
 *   on that list) sees only rows whose rep_email matches their own
 *   signed-in email -- NOT rows they personally created, since an admin
 *   can send on a rep's behalf via the Sending Representative dropdown.
 *
 * POST /ratesheet/api/requests.php?action=send
 *   { prospect_email, rep_name, location, account_kind }
 *   Creates the row with a random token, resolves the hourly rate from
 *   location, and emails the prospect a link to the public signup page
 *   (see public.php) pre-carrying that token -- along with the Time &
 *   Materials rate/terms copy Michael gave verbatim (2026-09-17 follow-up
 *   message), customized to show only THIS request's applicable rate
 *   rather than both Richmond/Warsaw options -- see
 *   ratesheet_rate_email_html()'s docblock for that specific call.
 *   -> { ok: true, request: {...} }
 */

declare(strict_types=1);

require_once __DIR__ . '/_util.php';

ratesheet_install_error_handlers();

$pdo = ratesheet_db();
$user = ratesheet_require_login($pdo);

$action = $_GET['action'] ?? '';

const RATESHEET_SENDER = 'Hello@codebluetechnology.com';
const RATESHEET_FROM_NAME = 'CodeBlue Technology';
const RATESHEET_LOGO_URL = 'https://portal.codebluetechnology.com/assets/email/codeblue-logo.png';
// SolutionsHub site root is two levels up from /ratesheet/api/.
const RATESHEET_SITE_BASE = 'https://portal.codebluetechnology.com';

function ratesheet_request_row(array $r, bool $includeCustomerFields): array
{
    $out = [
        'id' => (int) $r['id'],
        'prospect_email' => $r['prospect_email'],
        'rep_name' => $r['rep_name'],
        'rep_email' => $r['rep_email'],
        'location' => $r['location'],
        'account_kind' => $r['account_kind'],
        'hourly_rate' => (float) $r['hourly_rate'],
        'status' => $r['status'],
        'fail_reason' => $r['fail_reason'],
        'invoices_emailed' => $r['invoices_emailed'] === null ? null : (bool) $r['invoices_emailed'],
        'sent_at' => $r['sent_at'],
        'submitted_at' => $r['submitted_at'],
    ];
    if ($includeCustomerFields) {
        $out['first_name'] = $r['first_name'];
        $out['last_name'] = $r['last_name'];
        $out['business_name'] = $r['business_name'];
        $out['customer_email'] = $r['customer_email'];
        $out['payment_method'] = $r['payment_method'];
        $out['cw_company_id'] = $r['cw_company_id'] !== null ? (int) $r['cw_company_id'] : null;
        $out['cw_contact_id'] = $r['cw_contact_id'] !== null ? (int) $r['cw_contact_id'] : null;
    }
    return $out;
}

if ($action === 'list') {
    if (ratesheet_is_admin($user)) {
        $stmt = $pdo->query('SELECT * FROM rate_sheet_requests ORDER BY sent_at DESC');
    } else {
        $stmt = $pdo->prepare('SELECT * FROM rate_sheet_requests WHERE rep_email = :email COLLATE NOCASE ORDER BY sent_at DESC');
        $stmt->execute([':email' => $user['email']]);
    }
    $rows = array_map(fn (array $r) => ratesheet_request_row($r, true), $stmt->fetchAll(PDO::FETCH_ASSOC));
    ratesheet_respond(200, ['ok' => true, 'requests' => $rows]);
}

/**
 * Renders the prospect-facing "here's your rate sheet link" email.
 *
 * Copy (rate structure, service-type minimums, payment terms) is Michael's
 * own text, given verbatim (chat, 2026-09-17), EXCEPT the "Rate:" line:
 * his text listed both options ("Richmond $180 or Northern Neck $173.25")
 * as general reference copy -- for an actual email to an actual prospect
 * at a known Location, showing only THAT prospect's real rate reads much
 * clearer than making them figure out which of two numbers applies to
 * them, so this substitutes the one resolved rate/location instead. Same
 * letterhead conventions (colors, fonts, footer) as register/api/
 * signup-email.php's confirmation email, for brand consistency.
 */
function ratesheet_rate_email_html(string $location, float $rate, string $signupUrl): string
{
    $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    $locationLabel = $location === 'Richmond' ? 'Richmond' : 'Northern Neck (Warsaw)';
    $rateStr = number_format($rate, 2);

    $rateRow = static fn (string $label, string $detail): string =>
        '<div style="padding:10px 0;border-bottom:1px solid #E2E5EA;">' .
        '<div style="font-size:13.5px;font-family:Arial,Helvetica,sans-serif;color:#1B2030;font-weight:700;">' . $e($label) . '</div>' .
        '<div style="margin-top:2px;font-size:12.5px;font-family:Arial,Helvetica,sans-serif;color:#5A6472;">' . $e($detail) . '</div>' .
        '</div>';

    return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">' .
        '<title>Your CodeBlue Technology Rate Sheet</title></head>' .
        '<body style="margin:0;padding:0;background:#F4F5F7;">' .
        '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F4F5F7;padding:24px 0;">' .
        '<tr><td align="center">' .
        '<table role="presentation" width="640" cellpadding="0" cellspacing="0" style="width:640px;max-width:640px;background:#FFFFFF;border-radius:8px;border:1px solid #E2E5EA;">' .

        '<tr><td style="padding:24px 24px 20px 24px;border-bottom:3px solid #182857;">' .
        '<img src="' . $e(RATESHEET_LOGO_URL) . '" width="200" alt="CodeBlue Technology" style="display:block;height:auto;width:200px;border:0;" />' .
        '</td></tr>' .

        '<tr><td style="padding:20px 24px 8px 24px;">' .
        '<div style="font-size:20px;font-family:Arial,Helvetica,sans-serif;font-weight:800;color:#182857;">Thank You For Considering CodeBlue Technology</div>' .
        '<div style="margin-top:8px;font-size:13px;font-family:Arial,Helvetica,sans-serif;color:#5A6472;">' .
        'Thank you for considering CodeBlue Technology for your business IT needs. Below is our rate structure and payment terms for ' . $e($locationLabel) . '.' .
        '</div>' .
        '</td></tr>' .

        '<tr><td style="padding:0;">' .
        '<div style="background:#182857;padding:8px 16px;">' .
        '<span style="color:#FFFFFF;font-size:12px;font-family:Arial,Helvetica,sans-serif;font-weight:700;letter-spacing:.06em;text-transform:uppercase;">Rate Information — Time and Materials</span>' .
        '</div>' .
        '<div style="padding:4px 16px 0 16px;">' .
        '<div style="padding:12px 0;border-bottom:1px solid #E2E5EA;">' .
        '<div style="color:#182857;font-size:22px;font-family:Arial,Helvetica,sans-serif;font-weight:800;">$' . $e($rateStr) . ' <span style="font-size:13px;font-weight:400;color:#5A6472;">per hour — ' . $e($locationLabel) . '</span></div>' .
        '</div>' .
        $rateRow('Onsite Service', '1-hour minimum, 30-minute increments thereafter.') .
        $rateRow('Remote Service', '30-minute minimum, 30-minute increments thereafter.') .
        $rateRow('After-Hour Emergency Support', '2-hour minimum, 1.5x your hourly rate (before 8am and after 5pm).') .
        $rateRow('Holidays', '2-hour minimum, 2x your hourly rate.') .
        $rateRow('Travel', 'Time billed for 1 direction only, for distances equal to or greater than 20 miles.') .
        '</div>' .
        '</td></tr>' .

        '<tr><td style="padding:20px 24px;">' .
        '<div style="font-size:14px;font-family:Arial,Helvetica,sans-serif;font-weight:700;color:#1B2030;margin-bottom:6px;">Payment Terms</div>' .
        '<div style="font-size:12.5px;font-family:Arial,Helvetica,sans-serif;color:#33394A;line-height:1.5;">' .
        'Per-hour work will be invoiced upon completion of the work. Recurring Services will be charged to your active ACH or Credit Card on file, on the date it\'s due. ' .
        'Please complete the accompanying payment form below to prevent delays in scheduling your service request — customers who pay by ACH save 3% on transactions. ' .
        'If you have any questions about our rate structure or would like to learn more about the lower rates that come with our Service Agreements, don\'t hesitate to contact us.' .
        '</div>' .
        '</td></tr>' .

        '<tr><td style="padding:4px 24px 24px 24px;text-align:center;">' .
        '<a href="' . $e($signupUrl) . '" style="display:inline-block;background:#182857;color:#FFFFFF;text-decoration:none;font-family:Arial,Helvetica,sans-serif;font-size:14px;font-weight:700;padding:14px 28px;border-radius:6px;">Complete Your Rate Sheet & Sign Up</a>' .
        '<div style="margin-top:10px;font-size:11.5px;font-family:Arial,Helvetica,sans-serif;color:#8A93A3;">Please indicate your understanding of our rate structure and payment terms by filling, signing, and submitting the form at the link above.</div>' .
        '</td></tr>' .

        '<tr><td style="padding:16px 24px;background:#F4F5F7;border-top:1px solid #E2E5EA;text-align:center;">' .
        '<div style="font-size:11.5px;font-family:Arial,Helvetica,sans-serif;color:#7A8393;">CodeBlue Technology &nbsp;|&nbsp; (804) 521-7660 &nbsp;|&nbsp; Service@codebluetechnology.com</div>' .
        '</td></tr>' .
        '</table>' .
        '</td></tr>' .
        '</table>' .
        '</body></html>';
}

if ($action === 'send') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        ratesheet_respond(405, ['ok' => false, 'error' => 'Method not allowed.']);
    }

    $input = ratesheet_read_json_body();
    $prospectEmail = trim((string) ($input['prospect_email'] ?? ''));
    $repName = trim((string) ($input['rep_name'] ?? ''));
    $location = trim((string) ($input['location'] ?? ''));
    $accountKind = trim((string) ($input['account_kind'] ?? ''));

    if ($prospectEmail === '' || !filter_var($prospectEmail, FILTER_VALIDATE_EMAIL)) {
        ratesheet_respond(400, ['ok' => false, 'error' => 'A valid prospect email address is required.']);
    }
    $roster = ratesheet_sender_roster();
    if (!array_key_exists($repName, $roster)) {
        ratesheet_respond(400, ['ok' => false, 'error' => 'Unrecognized Sending Representative.']);
    }
    if (!in_array($location, ['Warsaw', 'Richmond'], true)) {
        ratesheet_respond(400, ['ok' => false, 'error' => 'Location must be Warsaw or Richmond.']);
    }
    if (!in_array($accountKind, ['Commercial', 'Residential'], true)) {
        ratesheet_respond(400, ['ok' => false, 'error' => 'Kind of Account must be Commercial or Residential.']);
    }

    $repEmail = $roster[$repName];
    $rate = ratesheet_hourly_rate($location);
    $token = bin2hex(random_bytes(24));

    $stmt = $pdo->prepare(
        'INSERT INTO rate_sheet_requests
            (token, created_by_user_id, prospect_email, rep_name, rep_email, location, account_kind, hourly_rate)
         VALUES (:token, :uid, :pemail, :rname, :remail, :loc, :kind, :rate)'
    );
    $stmt->execute([
        ':token' => $token,
        ':uid' => $user['id'],
        ':pemail' => $prospectEmail,
        ':rname' => $repName,
        ':remail' => $repEmail,
        ':loc' => $location,
        ':kind' => $accountKind,
        ':rate' => $rate,
    ]);
    $requestId = (int) $pdo->lastInsertId();

    $signupUrl = RATESHEET_SITE_BASE . '/ratesheet/signup.html?t=' . urlencode($token);

    $configPath = __DIR__ . '/../../mail/mail-config.php';
    if (!is_file($configPath)) {
        ratesheet_respond(500, ['ok' => false, 'error' => 'The rate sheet was saved, but mail is not configured on this server yet (mail/mail-config.php is missing) -- send the link manually for now: ' . $signupUrl]);
    }
    /** @var array $config */
    $config = require $configPath;
    require_once __DIR__ . '/../../mail/graph-mailer.php';

    try {
        $mailer = new GraphMailer(
            tenantId: (string) $config['tenant_id'],
            clientId: (string) $config['client_id'],
            clientSecret: (string) $config['client_secret'],
            senderUserId: RATESHEET_SENDER,
        );
        $mailer->send(
            $prospectEmail,
            'Your CodeBlue Technology Rate Sheet',
            ratesheet_rate_email_html($location, $rate, $signupUrl),
            null,
            RATESHEET_FROM_NAME,
            true
        );
    } catch (Throwable $e) {
        error_log('[ratesheet/requests] send email failed for request ' . $requestId . ': ' . $e->getMessage());
        ratesheet_respond(502, ['ok' => false, 'error' => 'The rate sheet was saved, but the email to the prospect could not be sent: ' . $e->getMessage() . ' — link: ' . $signupUrl]);
    }

    $stmt = $pdo->prepare('SELECT * FROM rate_sheet_requests WHERE id = :id');
    $stmt->execute([':id' => $requestId]);
    ratesheet_respond(200, ['ok' => true, 'request' => ratesheet_request_row($stmt->fetch(PDO::FETCH_ASSOC), true)]);
}

ratesheet_respond(400, ['ok' => false, 'error' => 'Unknown action.']);
