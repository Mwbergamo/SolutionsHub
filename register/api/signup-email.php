<?php
/**
 * register/api/signup-email.php
 *
 * Sends the "Thank you for your business" Terms & Conditions confirmation
 * email for the New Customer Sign Up screen (added 2026-09-14, front-screen
 * redesign). Per Michael: walk-in customers sign CBT's rate/terms form on
 * an iPad at the register -- that signed form is the real legal record --
 * and this email is the customer's own copy of what they agreed to, with
 * CodeBlue Technology letterhead.
 *
 * Content (rate structure, service-type minimums, payment terms) is taken
 * directly from the customer-provided "Thank you for your business.docx"
 * reference document, minus its blank fill-in-the-blank/signature fields
 * (those are the iPad form only -- this email is a confirmation, not
 * another form to fill out).
 *
 * Reuses the SAME shared Microsoft Graph mail module already live for the
 * Solutions Hub quote tool (mail/graph-mailer.php + mail/mail-config.php,
 * one level up from relationships/register's own api/ folders -- see that
 * folder's own docblock/.gitignore). Only the sender mailbox differs: per
 * Michael (2026-09-14 AskUserQuestion), this sends as
 * Hello@codebluetechnology.com / "CodeBlue Technology", NOT the quote
 * tool's Solutions@ mailbox -- passed directly to GraphMailer's constructor
 * rather than mail-config.php's 'sender' key, so the shared config file
 * doesn't need touching and the quote tool is unaffected.
 *
 * NOT independently confirmed before shipping: whether the existing Entra
 * app registration's Mail.Send permission is tenant-wide (so sending as
 * ANY mailbox, including Hello@, just works) or restricted to Solutions@
 * only via an Exchange ApplicationAccessPolicy -- this build environment
 * cannot reach Microsoft Graph to test a real send. If the very first real
 * send 401/403s citing the sender/mailbox, that's the cause -- needs an
 * M365 admin to add Hello@codebluetechnology.com to that policy (or widen
 * it), not a code change here.
 *
 * POST /register/api/signup-email.php?action=send
 *   body: { company_id, company_name, contact_id, contact_name, email,
 *           phone? }
 *   -> { ok: true } once the email is sent (also logs a customer_signups
 *      row with email_status='sent'); { ok: false, error } if it couldn't
 *      be sent (still logs the attempt, email_status='failed', so the
 *      failure isn't silently lost -- staff can see it and hand the signed
 *      paper form to the customer as their copy instead).
 */

declare(strict_types=1);

require_once __DIR__ . '/_util.php';

register_install_error_handlers();

$pdo = register_db();
$user = register_require_login($pdo);

$action = $_GET['action'] ?? '';

const REGISTER_SIGNUP_SENDER = 'Hello@codebluetechnology.com';
const REGISTER_SIGNUP_FROM_NAME = 'CodeBlue Technology';
const REGISTER_SIGNUP_LOGO_URL = 'https://portal.codebluetechnology.com/assets/email/codeblue-logo.png';

/**
 * Renders the confirmation email body -- same letterhead conventions
 * (colors, fonts, footer contact line) as the Solutions Hub quote email
 * template (mail/send-quote.php's callers build a similar table-based
 * layout) for brand consistency, built fresh here since this is a
 * different shared module and a different kind of email (a fixed terms
 * confirmation, not a dynamic itemized quote).
 */
function register_signup_email_html(string $contactName, string $companyName, string $phone, string $email, string $dateStr): string
{
    $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

    $customerRows = '';
    if ($contactName !== '') {
        $customerRows .= '<tr><td style="padding:2px 0;color:#5A6472;font-size:13px;font-family:Arial,Helvetica,sans-serif;"><strong style="color:#33394A;">Name:</strong> ' . $e($contactName) . '</td></tr>';
    }
    if ($companyName !== '') {
        $customerRows .= '<tr><td style="padding:2px 0;color:#5A6472;font-size:13px;font-family:Arial,Helvetica,sans-serif;"><strong style="color:#33394A;">Business:</strong> ' . $e($companyName) . '</td></tr>';
    }
    if ($phone !== '') {
        $customerRows .= '<tr><td style="padding:2px 0;color:#5A6472;font-size:13px;font-family:Arial,Helvetica,sans-serif;"><strong style="color:#33394A;">Phone:</strong> ' . $e($phone) . '</td></tr>';
    }
    if ($email !== '') {
        $customerRows .= '<tr><td style="padding:2px 0;color:#5A6472;font-size:13px;font-family:Arial,Helvetica,sans-serif;"><strong style="color:#33394A;">Email:</strong> ' . $e($email) . '</td></tr>';
    }

    $rateRow = static fn (string $label, string $detail): string =>
        '<div style="padding:10px 0;border-bottom:1px solid #E2E5EA;">' .
        '<div style="font-size:13.5px;font-family:Arial,Helvetica,sans-serif;color:#1B2030;font-weight:700;">' . $e($label) . '</div>' .
        '<div style="margin-top:2px;font-size:12.5px;font-family:Arial,Helvetica,sans-serif;color:#5A6472;">' . $e($detail) . '</div>' .
        '</div>';

    return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">' .
        '<title>Thank You — CodeBlue Technology</title></head>' .
        '<body style="margin:0;padding:0;background:#F4F5F7;">' .
        '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F4F5F7;padding:24px 0;">' .
        '<tr><td align="center">' .
        '<table role="presentation" width="640" cellpadding="0" cellspacing="0" style="width:640px;max-width:640px;background:#FFFFFF;border-radius:8px;border:1px solid #E2E5EA;">' .

        '<tr><td style="padding:24px 24px 20px 24px;border-bottom:3px solid #182857;">' .
        '<img src="' . $e(REGISTER_SIGNUP_LOGO_URL) . '" width="200" alt="CodeBlue Technology" style="display:block;height:auto;width:200px;border:0;" />' .
        '</td></tr>' .

        '<tr><td style="padding:20px 24px 8px 24px;">' .
        '<div style="font-size:20px;font-family:Arial,Helvetica,sans-serif;font-weight:800;color:#182857;">Thank You For Your Business!</div>' .
        '<div style="margin-top:8px;font-size:13px;font-family:Arial,Helvetica,sans-serif;color:#5A6472;">' .
        'This confirms you signed CodeBlue Technology\'s rate structure and payment terms on ' . $e($dateStr) . ' at our register. ' .
        'Here is your copy for your records.' .
        '</div>' .
        '<table role="presentation" cellpadding="0" cellspacing="0" style="margin-top:14px;">' . $customerRows . '</table>' .
        '</td></tr>' .

        '<tr><td style="padding:0;">' .
        '<div style="background:#182857;padding:8px 16px;">' .
        '<span style="color:#FFFFFF;font-size:12px;font-family:Arial,Helvetica,sans-serif;font-weight:700;letter-spacing:.06em;text-transform:uppercase;">Rate Information — Time and Materials</span>' .
        '</div>' .
        '<div style="padding:4px 16px 0 16px;">' .
        '<div style="padding:12px 0;border-bottom:1px solid #E2E5EA;">' .
        '<div style="color:#182857;font-size:22px;font-family:Arial,Helvetica,sans-serif;font-weight:800;">$180.00 <span style="font-size:13px;font-weight:400;color:#5A6472;">per hour</span></div>' .
        '</div>' .
        $rateRow('Onsite Service', '1-hour minimum, 30-minute increments thereafter.') .
        $rateRow('Remote Service', '30-minute minimum, 30-minute increments thereafter.') .
        $rateRow('After-Hour Emergency Support', '2-hour minimum, 1.5× your hourly rate (before 8am and after 5pm).') .
        $rateRow('Holidays', '2-hour minimum, 2× your hourly rate.') .
        $rateRow('Travel', 'Billed for 1 direction only, for distances of 20 miles or more.') .
        '</div>' .
        '</td></tr>' .

        '<tr><td style="padding:20px 24px;">' .
        '<div style="font-size:14px;font-family:Arial,Helvetica,sans-serif;font-weight:700;color:#1B2030;margin-bottom:6px;">Payment Terms</div>' .
        '<div style="font-size:12.5px;font-family:Arial,Helvetica,sans-serif;color:#33394A;line-height:1.5;">' .
        'Services will be invoiced upon completion of the work. If you have any questions about our rate structure, billing schedule, ' .
        'or would like to learn more about the lower rates available with a Service Agreement, don\'t hesitate to contact us below.' .
        '</div>' .
        '</td></tr>' .

        '<tr><td style="padding:0 24px 20px 24px;">' .
        '<div style="font-size:11px;font-family:Arial,Helvetica,sans-serif;color:#8A93A3;font-style:italic;">This email confirms you read and understand CodeBlue Technology\'s terms, pricing, and billing schedule as signed at our register.</div>' .
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
    $input = register_read_json_body();
    $companyId = isset($input['company_id']) ? (int) $input['company_id'] : 0;
    $companyName = trim((string) ($input['company_name'] ?? ''));
    $contactId = isset($input['contact_id']) ? (int) $input['contact_id'] : 0;
    $contactName = trim((string) ($input['contact_name'] ?? ''));
    $email = trim((string) ($input['email'] ?? ''));
    $phone = trim((string) ($input['phone'] ?? ''));

    if ($companyId <= 0 || $contactId <= 0) {
        register_respond(400, ['ok' => false, 'error' => 'company_id and contact_id are required.']);
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        register_respond(400, ['ok' => false, 'error' => 'A valid email address is required to send the confirmation.']);
    }

    $configPath = __DIR__ . '/../../mail/mail-config.php';
    if (!is_file($configPath)) {
        $stmt = $pdo->prepare('INSERT INTO customer_signups (user_id, cw_company_id, cw_company_name, cw_contact_id, cw_contact_name, email_to, email_status, email_error) VALUES (:uid, :cid, :cname, :ctid, :ctname, :email, :status, :err)');
        $stmt->execute([':uid' => $user['id'], ':cid' => $companyId, ':cname' => $companyName, ':ctid' => $contactId, ':ctname' => $contactName, ':email' => $email, ':status' => 'failed', ':err' => 'Mail is not configured on this server yet (mail/mail-config.php is missing).']);
        register_respond(500, ['ok' => false, 'error' => 'Mail is not configured on this server yet.']);
    }
    /** @var array $config */
    $config = require $configPath;

    require_once __DIR__ . '/../../mail/graph-mailer.php';

    $dateStr = (new DateTimeImmutable('now', new DateTimeZone('America/New_York')))->format('F j, Y');
    $html = register_signup_email_html($contactName, $companyName, $phone, $email, $dateStr);

    try {
        $mailer = new GraphMailer(
            tenantId: (string) $config['tenant_id'],
            clientId: (string) $config['client_id'],
            clientSecret: (string) $config['client_secret'],
            senderUserId: REGISTER_SIGNUP_SENDER,
        );
        $mailer->send($email, 'Thank You For Your Business — CodeBlue Technology', $html, null, REGISTER_SIGNUP_FROM_NAME, true);

        $stmt = $pdo->prepare('INSERT INTO customer_signups (user_id, cw_company_id, cw_company_name, cw_contact_id, cw_contact_name, email_to, email_status) VALUES (:uid, :cid, :cname, :ctid, :ctname, :email, :status)');
        $stmt->execute([':uid' => $user['id'], ':cid' => $companyId, ':cname' => $companyName, ':ctid' => $contactId, ':ctname' => $contactName, ':email' => $email, ':status' => 'sent']);

        register_respond(200, ['ok' => true]);
    } catch (Throwable $e) {
        $stmt = $pdo->prepare('INSERT INTO customer_signups (user_id, cw_company_id, cw_company_name, cw_contact_id, cw_contact_name, email_to, email_status, email_error) VALUES (:uid, :cid, :cname, :ctid, :ctname, :email, :status, :err)');
        $stmt->execute([':uid' => $user['id'], ':cid' => $companyId, ':cname' => $companyName, ':ctid' => $contactId, ':ctname' => $contactName, ':email' => $email, ':status' => 'failed', ':err' => $e->getMessage()]);

        error_log('[signup-email] ' . $e->getMessage());
        register_respond(502, ['ok' => false, 'error' => 'Could not send the confirmation email: ' . $e->getMessage()]);
    }
}

register_respond(400, ['ok' => false, 'error' => 'Unknown action.']);
