<?php
/**
 * relationships/api/task-email.php
 *
 * Sends the "We have a next step!" notification email when a meeting
 * to-do task is created -- added 2026-09-16 per Michael: "I need emails
 * sent out when To-Do's are created from meetings. The assigned rep needs
 * to receive an email from Solutions@codebluetechnology.com."
 *
 * Reuses the SAME shared Microsoft Graph mail module already live for the
 * Solutions Hub quote tool and the Register app's New Customer Sign Up
 * confirmation email (mail/graph-mailer.php + mail/mail-config.php, two
 * levels up from this file -- see that folder's own docblock/.gitignore).
 * UNLIKE the Register app's confirmation email (which sends as
 * Hello@codebluetechnology.com, overriding mail-config.php's default),
 * this one sends as mail-config.php's own default sender -- which IS
 * Solutions@codebluetechnology.com (confirmed live and working, see
 * claude/mail-quote-email-fix.md) -- exactly the mailbox Michael asked
 * for, so no sender override is needed here at all.
 *
 * Recipient: relationships_todo_roster_cw_email() (catalog.php) -- the
 * same static roster-name -> real ConnectWise/work email map added
 * 2026-09-16 for the ConnectWise Activity assignment bug fix (see
 * claude/relationships-meeting-capture.md). Deliberately NOT
 * $assignee['email'] from a crc_users lookup, for the same reason: this
 * must not depend on the assigned rep having registered a Relationships
 * login.
 *
 * Per this integration's standing "save locally, log the failure, never
 * let an external send block the local save" instruction -- meetings.php
 * calls relationships_send_task_email() in its own try/catch, AFTER the
 * task and its ConnectWise push attempt are already committed, and logs
 * the outcome to meeting_tasks.email_status/email_error rather than
 * letting a failed send affect the task-creation response.
 */

declare(strict_types=1);

const RELATIONSHIPS_TASK_EMAIL_LOGO_URL = 'https://portal.codebluetechnology.com/assets/email/codeblue-logo.png';
const RELATIONSHIPS_TASK_EMAIL_PORTAL_URL = 'https://portal.codebluetechnology.com/relationships';

/**
 * Renders the task-notification email body. Same letterhead conventions
 * (navy header bar, logo, footer contact line) as the Register app's New
 * Customer Sign Up confirmation email (register/api/signup-email.php) for
 * brand consistency -- built fresh here since this is a different kind of
 * email (an internal staff notification with a details table, not a
 * customer-facing terms confirmation).
 *
 * $ctx keys: created_by_name, assigned_to_name, customer_name, todo_text,
 * created_at_display (human-readable Eastern timestamp).
 */
function relationships_task_email_html(array $ctx): string
{
    $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

    $row = static fn (string $label, string $value): string =>
        '<tr><td style="padding:6px 0;border-bottom:1px solid #E2E5EA;">' .
        '<div style="font-size:11px;font-family:Arial,Helvetica,sans-serif;color:#8A93A3;text-transform:uppercase;letter-spacing:.04em;">' . $e($label) . '</div>' .
        '<div style="margin-top:2px;font-size:14px;font-family:Arial,Helvetica,sans-serif;color:#1B2030;">' . $e($value) . '</div>' .
        '</td></tr>';

    $detailRows =
        $row('Created By', (string) $ctx['created_by_name']) .
        $row('Assigned To', (string) $ctx['assigned_to_name']) .
        $row('Customer', (string) $ctx['customer_name']) .
        $row('To-Do', (string) $ctx['todo_text']) .
        $row('Time Stamp', (string) $ctx['created_at_display']);

    return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">' .
        '<title>We have a next step! — CodeBlue Technology</title></head>' .
        '<body style="margin:0;padding:0;background:#F4F5F7;">' .
        '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F4F5F7;padding:24px 0;">' .
        '<tr><td align="center">' .
        '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:600px;max-width:600px;background:#FFFFFF;border-radius:8px;border:1px solid #E2E5EA;">' .

        '<tr><td style="padding:24px 24px 20px 24px;border-bottom:3px solid #182857;">' .
        '<img src="' . $e(RELATIONSHIPS_TASK_EMAIL_LOGO_URL) . '" width="180" alt="CodeBlue Technology" style="display:block;height:auto;width:180px;border:0;" />' .
        '</td></tr>' .

        '<tr><td style="padding:20px 24px 4px 24px;">' .
        '<div style="font-size:20px;font-family:Arial,Helvetica,sans-serif;font-weight:800;color:#182857;">We have a next step!</div>' .
        '<div style="margin-top:6px;font-size:13px;font-family:Arial,Helvetica,sans-serif;color:#5A6472;">' .
        'A to-do was just added in the Relationships portal. Details below.' .
        '</div>' .
        '</td></tr>' .

        '<tr><td style="padding:8px 24px 4px 24px;">' .
        '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">' . $detailRows . '</table>' .
        '</td></tr>' .

        '<tr><td style="padding:20px 24px;">' .
        '<a href="' . $e(RELATIONSHIPS_TASK_EMAIL_PORTAL_URL) . '" style="display:inline-block;background:#182857;color:#FFFFFF;text-decoration:none;font-family:Arial,Helvetica,sans-serif;font-size:13.5px;font-weight:700;padding:11px 20px;border-radius:6px;">' .
        'Open Relationships Portal</a>' .
        '<div style="margin-top:12px;font-size:12.5px;font-family:Arial,Helvetica,sans-serif;color:#5A6472;">' .
        'Please log in to Portal.codebluetechnology.com/relationships and check out this task. Check it off when completed.' .
        '</div>' .
        '</td></tr>' .

        '<tr><td style="padding:16px 24px;background:#F4F5F7;border-top:1px solid #E2E5EA;text-align:center;">' .
        '<div style="font-size:11.5px;font-family:Arial,Helvetica,sans-serif;color:#7A8393;">CodeBlue Technology &nbsp;|&nbsp; (804) 521-7660 &nbsp;|&nbsp; Service@codebluetechnology.com</div>' .
        '</td></tr>' .
        '</table>' .
        '</td></tr>' .
        '</table>' .
        '</body></html>';
}

/**
 * Sends the task-notification email. Throws (GraphMailerException, or a
 * RuntimeException if mail-config.php is missing) on any failure --
 * meetings.php's caller is responsible for catching this and logging the
 * outcome; this function never touches the database itself.
 *
 * $ctx keys: to_email (string), created_by_name, assigned_to_name,
 * customer_name, todo_text, created_at_display (all string).
 */
function relationships_send_task_email(array $ctx): void
{
    $configPath = __DIR__ . '/../../mail/mail-config.php';
    if (!is_file($configPath)) {
        throw new RuntimeException('Mail is not configured on this server yet (mail/mail-config.php is missing).');
    }
    /** @var array $config */
    $config = require $configPath;

    require_once __DIR__ . '/../../mail/graph-mailer.php';

    $html = relationships_task_email_html($ctx);
    $subject = 'We have a next step! — ' . (string) $ctx['customer_name'];
    // Header-injection guard: subject travels into a raw header line.
    $subject = str_replace(["\r", "\n"], ' ', $subject);

    // Deliberately NO senderUserId override -- mail-config.php's own
    // default sender IS Solutions@codebluetechnology.com (confirmed live,
    // see claude/mail-quote-email-fix.md), exactly what Michael asked
    // this to send from.
    $mailer = new GraphMailer(
        tenantId: (string) $config['tenant_id'],
        clientId: (string) $config['client_id'],
        clientSecret: (string) $config['client_secret'],
        senderUserId: (string) $config['sender'],
    );
    $fromName = (string) ($config['from_name'] ?? 'CodeBlue Technology');

    $mailer->send((string) $ctx['to_email'], $subject, $html, null, $fromName, true);
}
