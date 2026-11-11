<?php
/**
 * relationships/api/project-assignment-email.php
 *
 * Sends the "You're the Project Relationship Coordinator" announcement
 * email when a coordinator is assigned to a project -- added 2026-11-11
 * per Michael: "When you assign a project to a resource, they need to
 * receive an email from Solutions Hub that announces them as the
 * 'Project Relationship Coordinator' and states their responsibility for
 * their project."
 *
 * Reuses the SAME shared Microsoft Graph mail module as the meeting-task
 * notification email (relationships/api/task-email.php) --
 * mail/graph-mailer.php + mail/mail-config.php, two levels up from this
 * file. Sends as mail-config.php's own default sender
 * (Solutions@codebluetechnology.com), matching Michael's own wording
 * ("an email from Solutions Hub") exactly, so no sender override is
 * needed here either.
 *
 * Recipient: relationships_todo_roster_cw_email() (catalog.php) -- same
 * fixed roster-name -> real work-email map task-email.php uses, and the
 * same 7-name roster projects.php's assign action already validates
 * assigned_to_name against. Fires from projects.php's 'assign' action, in
 * its own try/catch, AFTER the local project_assignments row is already
 * written -- a failed send is logged to that row's email_status/
 * email_error and never blocks or undoes the assignment itself, same
 * fail-open spirit as relationships_send_task_email().
 *
 * Body content is Michael's own wording, verbatim where he gave exact
 * text:
 *   1. Update your customer when the order has been placed
 *   2. Update your customer when the order has been received
 *   3. Update your customer when the kick off has been scheduled with you
 *   4. Send your customer the team contact info for your project
 * ...closing on the exact line he asked for ("All of these steps can be
 * done within Solutions Hub under Projects"), a link to the Projects
 * screen he gave (portal.codebluetechnology.com/relationships/index.html
 * ?view=projects&section=projects), and the standing policy line he asked
 * included verbatim ("Account Managers are required to update customer
 * until kick off has been scheduled.").
 *
 * NOTE for Michael: his list of 4 responsibilities doesn't mention "order
 * shipped" -- the Projects checklist itself still has 5 steps (placed /
 * shipped / received / kick-off / team communication), this announcement
 * email just doesn't call out shipped by name. Built exactly as he
 * dictated; flag if shipped should be added as a 5th bullet.
 */

declare(strict_types=1);

const RELATIONSHIPS_PROJECT_EMAIL_LOGO_URL = 'https://portal.codebluetechnology.com/assets/email/codeblue-logo.png';
const RELATIONSHIPS_PROJECT_EMAIL_PORTAL_URL = 'https://portal.codebluetechnology.com/relationships/index.html?view=projects&section=projects';

/**
 * Renders the assignment-announcement email body. Same letterhead
 * conventions (navy header bar, logo, footer contact line) as
 * relationships_task_email_html() for brand consistency with the other
 * internal staff notification this app already sends.
 *
 * $ctx keys: coordinator_name, project_name, company_name (all string;
 * project_name/company_name may be '' if the frontend didn't have them on
 * hand -- the email still sends, just less specific).
 */
function relationships_project_assignment_email_html(array $ctx): string
{
    $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

    $projectLabel = (string) $ctx['project_name'] !== '' ? (string) $ctx['project_name'] : 'your assigned project';
    $companyLine = (string) $ctx['company_name'] !== ''
        ? '<div style="margin-top:2px;font-size:14px;font-family:Arial,Helvetica,sans-serif;color:#5A6472;">' . $e((string) $ctx['company_name']) . '</div>'
        : '';

    $listItem = static fn (string $text): string =>
        '<li style="margin:0 0 10px 0;font-size:14px;font-family:Arial,Helvetica,sans-serif;color:#1B2030;line-height:1.5;">' . $e($text) . '</li>';

    $responsibilities =
        $listItem('Update your customer when the order has been placed.') .
        $listItem('Update your customer when the order has been received.') .
        $listItem('Update your customer when the kick off has been scheduled with you.') .
        $listItem('Send your customer the team contact info for your project.');

    return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">' .
        '<title>You&#8217;re the Project Relationship Coordinator &mdash; CodeBlue Technology</title></head>' .
        '<body style="margin:0;padding:0;background:#F4F5F7;">' .
        '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F4F5F7;padding:24px 0;">' .
        '<tr><td align="center">' .
        '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:600px;max-width:600px;background:#FFFFFF;border-radius:8px;border:1px solid #E2E5EA;">' .

        '<tr><td style="padding:24px 24px 20px 24px;border-bottom:3px solid #182857;">' .
        '<img src="' . $e(RELATIONSHIPS_PROJECT_EMAIL_LOGO_URL) . '" width="180" alt="CodeBlue Technology" style="display:block;height:auto;width:180px;border:0;" />' .
        '</td></tr>' .

        '<tr><td style="padding:20px 24px 4px 24px;">' .
        '<div style="font-size:20px;font-family:Arial,Helvetica,sans-serif;font-weight:800;color:#182857;">You&#8217;re the Project Relationship Coordinator</div>' .
        '<div style="margin-top:6px;font-size:14px;font-family:Arial,Helvetica,sans-serif;color:#1B2030;">' .
        'You&#8217;ve been assigned as the Project Relationship Coordinator for:' .
        '</div>' .
        '<div style="margin-top:10px;font-size:16px;font-family:Arial,Helvetica,sans-serif;font-weight:700;color:#182857;">' . $e($projectLabel) . '</div>' .
        $companyLine .
        '</td></tr>' .

        '<tr><td style="padding:16px 24px 4px 24px;">' .
        '<div style="font-size:13px;font-family:Arial,Helvetica,sans-serif;color:#5A6472;">That makes you responsible for:</div>' .
        '<ul style="margin:10px 0 0 0;padding-left:20px;">' . $responsibilities . '</ul>' .
        '</td></tr>' .

        '<tr><td style="padding:16px 24px;">' .
        '<a href="' . $e(RELATIONSHIPS_PROJECT_EMAIL_PORTAL_URL) . '" style="display:inline-block;background:#182857;color:#FFFFFF;text-decoration:none;font-family:Arial,Helvetica,sans-serif;font-size:13.5px;font-weight:700;padding:11px 20px;border-radius:6px;">' .
        'Open Projects in Solutions Hub</a>' .
        '<div style="margin-top:12px;font-size:12.5px;font-family:Arial,Helvetica,sans-serif;color:#5A6472;">' .
        'Navigate to Solutions Hub to update your customer.' .
        '</div>' .
        '</td></tr>' .

        '<tr><td style="padding:4px 24px 20px 24px;">' .
        '<div style="font-size:13.5px;font-family:Arial,Helvetica,sans-serif;color:#1B2030;font-weight:700;">' .
        'All of these steps can be done within Solutions Hub under Projects.' .
        '</div>' .
        '</td></tr>' .

        '<tr><td style="padding:14px 24px;background:#FBEFEF;border-top:1px solid #E2E5EA;border-bottom:1px solid #E2E5EA;">' .
        '<div style="font-size:12.5px;font-family:Arial,Helvetica,sans-serif;color:#8A2B2B;font-weight:700;">' .
        'Account Managers are required to update customer until kick off has been scheduled.' .
        '</div>' .
        '</td></tr>' .

        '<tr><td style="padding:16px 24px;background:#F4F5F7;text-align:center;">' .
        '<div style="font-size:11.5px;font-family:Arial,Helvetica,sans-serif;color:#7A8393;">CodeBlue Technology &nbsp;|&nbsp; (804) 521-7660 &nbsp;|&nbsp; Service@codebluetechnology.com</div>' .
        '</td></tr>' .
        '</table>' .
        '</td></tr>' .
        '</table>' .
        '</body></html>';
}

/**
 * Sends the assignment-announcement email. Throws (GraphMailerException,
 * or a RuntimeException if mail-config.php is missing) on any failure --
 * projects.php's 'assign' action is responsible for catching this and
 * logging the outcome to project_assignments.email_status/email_error;
 * this function never touches the database itself.
 *
 * $ctx keys: to_email, coordinator_name, project_name, company_name (all
 * string).
 */
function relationships_send_project_assignment_email(array $ctx): void
{
    $configPath = __DIR__ . '/../../mail/mail-config.php';
    if (!is_file($configPath)) {
        throw new RuntimeException('Mail is not configured on this server yet (mail/mail-config.php is missing).');
    }
    /** @var array $config */
    $config = require $configPath;

    require_once __DIR__ . '/../../mail/graph-mailer.php';

    $html = relationships_project_assignment_email_html($ctx);
    $projectLabel = (string) $ctx['project_name'] !== '' ? (string) $ctx['project_name'] : 'your project';
    $subject = "You're the Project Relationship Coordinator — " . $projectLabel;
    // Header-injection guard: subject travels into a raw header line.
    $subject = str_replace(["\r", "\n"], ' ', $subject);

    // Deliberately NO senderUserId override -- mail-config.php's own
    // default sender IS Solutions@codebluetechnology.com (confirmed live,
    // see claude/mail-quote-email-fix.md), exactly what Michael asked for
    // ("an email from Solutions Hub").
    $mailer = new GraphMailer(
        tenantId: (string) $config['tenant_id'],
        clientId: (string) $config['client_id'],
        clientSecret: (string) $config['client_secret'],
        senderUserId: (string) $config['sender'],
    );
    $fromName = (string) ($config['from_name'] ?? 'CodeBlue Technology');

    $mailer->send((string) $ctx['to_email'], $subject, $html, null, $fromName, true);
}
