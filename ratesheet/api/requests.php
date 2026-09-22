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
 *
 * POST /ratesheet/api/requests.php?action=clear-test-data
 *   { confirm: true }
 *   Admin-only (ratesheet_is_admin()) -- added 2026-09-18, per Michael, to
 *   wipe out the development/testing rate sheets sent while this app was
 *   being built, so the dashboard starts clean for real customers. Deletes
 *   EVERY row in rate_sheet_requests (there's no way to distinguish "test"
 *   from "real" rows -- this app hadn't gone live yet at the time this was
 *   added, so a full wipe is what was actually meant). Requires a literal
 *   { confirm: true } in the body (not just the admin gate) so this can
 *   never fire from a stray/retried request -- see app.js's confirm()
 *   dialog before this is ever called. Does NOT touch ratesheet_users
 *   (real rep logins, not test data).
 *   -> { ok: true, deleted: <int> }
 */

declare(strict_types=1);

require_once __DIR__ . '/_util.php';
require_once __DIR__ . '/connectwise.php';
require_once __DIR__ . '/altpay.php';

ratesheet_install_error_handlers();

$pdo = ratesheet_db();
$user = ratesheet_require_login($pdo);

$action = $_GET['action'] ?? '';

const RATESHEET_SENDER = 'Hello@codebluetechnology.com';
const RATESHEET_FROM_NAME = 'CodeBlue Technology';
const RATESHEET_LOGO_URL = 'https://portal.codebluetechnology.com/assets/email/codeblue-logo.png';
// SolutionsHub site root is two levels up from /ratesheet/api/.
const RATESHEET_SITE_BASE = 'https://portal.codebluetechnology.com';

/**
 * The dashboard's RED/YELLOW/GREEN state, per Michael's Phase-C redesign
 * (2026-09-18): "When the form is sent, the rep should see it in their
 * dashboard with status RED (Sent) / It should change status to (Yellow)
 * signed / It should change status to (Green) Payment added." Michael's
 * explicit choice ("Auto-detect from ConnectWise") means the green state
 * is never stored in this database -- it's derived here from a LIVE
 * ConnectWise Company Status lookup ($statusNames, keyed by cw_company_id
 * -- see ratesheet_cw_company_statuses()/ratesheet_cw_company_status_name()
 * in connectwise.php), so ConnectWise stays the single source of truth and
 * this app can never drift out of sync with what Invoicing actually did.
 *
 * Deliberately fails CLOSED, not open: if the live lookup is missing or
 * unavailable for a submitted row (ConnectWise outage, company not found,
 * etc.), this returns 'signed' (yellow) rather than guessing 'payment_added'
 * (green) -- reps should never see a false "paid" before it's confirmed.
 *
 * ALSO fails closed against a second, distinct hazard (found live
 * 2026-09-22, per Michael): a live status that isn't Credit Hold is only
 * real evidence Invoicing released it on purpose if this app actually got
 * the Company onto Credit Hold in the first place. If the signup-time
 * attempt never confirmed that (credit_hold_status !== 'held' -- see
 * public.php's post-create verification), the Company can simply be
 * stuck on its default status (e.g. "Active") from day one, and reading
 * "not Credit Hold" as "paid" would show a false GREEN on an account that
 * was never actually protected. That combination gets its own
 * 'hold_not_set' state instead -- never silently reinterpreted as
 * 'payment_added'.
 *
 * @param array<int,string> $statusNames cw_company_id => live ConnectWise Company Status name
 * @return 'sent'|'signed'|'payment_added'|'failed'|'hold_not_set'
 */
function ratesheet_payment_status(array $r, array $statusNames): string
{
    if ($r['status'] === 'pending') {
        return 'sent';
    }
    if ($r['status'] === 'failed') {
        return 'failed';
    }
    if ($r['status'] === 'submitted') {
        $companyId = $r['cw_company_id'] !== null ? (int) $r['cw_company_id'] : null;
        $liveName = $companyId !== null ? ($statusNames[$companyId] ?? null) : null;
        if ($liveName === null) {
            // Live lookup unavailable for this row (ConnectWise outage,
            // company not found, etc.) -- fail closed to the conservative
            // "still on hold" state rather than guessing either way.
            return 'signed';
        }
        if (strcasecmp($liveName, RATESHEET_CREDIT_HOLD_STATUS_NAME) === 0) {
            return 'signed';
        }
        // Live status is definitively NOT Credit Hold. Only trust that as
        // "Invoicing released it" if this app actually confirmed Credit
        // Hold was applied at signup -- otherwise it just means the
        // safeguard never took, which is its own, more urgent state (see
        // this function's docblock).
        if ($r['credit_hold_status'] !== 'held') {
            return 'hold_not_set';
        }
        return 'payment_added';
    }
    // Legacy 'awaiting_payment' rows (superseded same-day redesign, see
    // db.php) -- treat as signed rather than inventing a new bucket for a
    // value nothing writes anymore.
    return 'signed';
}

// Per-page-load cap on how many still-on-hold rows
// ratesheet_maybe_auto_release_credit_hold() will check against
// Alternative Payments, so a large batch of simultaneously-pending
// accounts can't turn one dashboard load into dozens of sequential API
// calls -- any rows past this cap are simply picked up on the next load.
const RATESHEET_AUTO_RELEASE_MAX_CHECKS = 25;

/**
 * Auto-release check, added 2026-09-22 per Michael: "The account should
 * be automatically marked active only when a valid payment is added to
 * the account within Alternative Payment on that new account." Invoicing
 * adds that payment method directly in Alternative Payments' own
 * dashboard (see public.php's header -- this app never touches payment
 * data itself), with no field today tying that record back to a
 * specific rate sheet signup, so this looks the Alternative Payments
 * customer up **by email** (the one identifier guaranteed to exist in
 * both systems -- Michael's choice, asked directly) and checks whether
 * they have at least one payment method on file.
 *
 * Runs live on every dashboard/receipt view (also Michael's choice,
 * asked directly) rather than a scheduled job or an Alternative Payments
 * webhook -- the same "compute live, never store" philosophy this app
 * already uses for the GREEN/YELLOW read (see this file's header),
 * just extended one step further into an actual ConnectWise write once
 * the evidence is there.
 *
 * Only acts on rows the live ConnectWise lookup shows as CURRENTLY on
 * Credit Hold -- nothing to release otherwise. When a payment method is
 * found, PUTs the Company to Active (id 1, the same confirmed id this
 * app already uses as its create-time fallback -- see
 * ratesheet_cw_create_company()'s caller in public.php) via the same
 * proven ratesheet_cw_put_company_with_retry() mechanism the Credit Hold
 * enforcement itself uses.
 *
 * *** UNVERIFIED AGAINST THE LIVE ALTERNATIVE PAYMENTS API *** -- this
 * session has no Alternative Payments credentials, and the customer/
 * payment-method lookups this depends on
 * (ratesheet_altpay_find_customer_by_email()/
 * ratesheet_altpay_list_payment_methods() in altpay.php) have never been
 * exercised live -- only the POST create endpoints were, and only for
 * the abandoned two-step self-vaulting design. Every failure mode there
 * is designed to fail CLOSED (see those functions' docblocks): a false
 * negative here just leaves an account on Credit Hold a little longer
 * (safe); a false positive would release a real billing safeguard on
 * the wrong account (not safe). Please forward the relevant error-log
 * lines if this doesn't work on the first live try.
 *
 * @param array<int,array<string,mixed>> $rows the raw DB rows (needs customer_email, cw_company_id, status)
 * @param array<int,string> $statusNames cw_company_id => live ConnectWise Company Status name -- MUTATED IN PLACE so a row auto-released during this call renders correctly in the SAME page load rather than waiting for the next one.
 */
function ratesheet_maybe_auto_release_credit_hold(array $rows, array &$statusNames): void
{
    $checked = 0;
    foreach ($rows as $r) {
        if ($r['status'] !== 'submitted') {
            continue;
        }
        $companyId = $r['cw_company_id'] !== null ? (int) $r['cw_company_id'] : null;
        if ($companyId === null) {
            continue;
        }
        $liveName = $statusNames[$companyId] ?? null;
        if ($liveName === null || strcasecmp($liveName, RATESHEET_CREDIT_HOLD_STATUS_NAME) !== 0) {
            continue; // not currently on Credit Hold live -- nothing to release
        }
        if ($checked >= RATESHEET_AUTO_RELEASE_MAX_CHECKS) {
            error_log('ratesheet_maybe_auto_release_credit_hold: hit the per-load check cap (' . RATESHEET_AUTO_RELEASE_MAX_CHECKS . ') -- remaining on-hold rows will be checked on the next load.');
            break;
        }
        $email = is_string($r['customer_email'] ?? null) ? trim((string) $r['customer_email']) : '';
        if ($email === '') {
            continue;
        }
        $checked++;

        try {
            $customerId = ratesheet_altpay_find_customer_by_email($email);
            if ($customerId === null) {
                continue;
            }
            $paymentMethods = ratesheet_altpay_list_payment_methods($customerId);
            if ($paymentMethods === null || $paymentMethods === []) {
                continue;
            }
            $hasValidMethod = false;
            foreach ($paymentMethods as $pm) {
                if (is_array($pm) && !empty($pm['id'])) {
                    $hasValidMethod = true;
                    break;
                }
            }
            if (!$hasValidMethod) {
                continue;
            }

            ratesheet_cw_put_company_with_retry($companyId, ['status' => ['id' => 1]]); // Active, confirmed id (register/api/customers.php)
            $statusNames[$companyId] = 'Active';
            error_log('ratesheet_maybe_auto_release_credit_hold: auto-released Credit Hold for company ' . $companyId . ' (request ' . $r['id'] . ') -- Alternative Payments customer ' . $customerId . ' has a payment method on file.');
        } catch (Throwable $e) {
            error_log('ratesheet_maybe_auto_release_credit_hold: check/release failed for request ' . $r['id'] . ' (company ' . $companyId . '): ' . $e->getMessage());
        }
    }
}

/** @param array<int,string> $statusNames cw_company_id => live ConnectWise Company Status name (only needed when $includeCustomerFields) */
function ratesheet_request_row(array $r, bool $includeCustomerFields, array $statusNames = []): array
{
    $out = [
        'id' => (int) $r['id'],
        // Added 2026-09-22 per Michael's walk-in rate sheet request --
        // 'email' for a rep-sent link (public.php) or 'walkin' for a
        // walk-in customer at the counter (walkin.php) -- see db.php's
        // column comment. Drives app.js's "Walk-In" badge.
        'source' => $r['source'] ?? 'email',
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
        $out['phone'] = $r['phone'];
        $out['payment_method'] = $r['payment_method'];
        $out['cw_company_id'] = $r['cw_company_id'] !== null ? (int) $r['cw_company_id'] : null;
        $out['cw_contact_id'] = $r['cw_contact_id'] !== null ? (int) $r['cw_contact_id'] : null;
        // Informational only -- what THIS APP attempted at signup time, see
        // db.php's column comment. The dashboard color comes from
        // payment_status below, not this.
        $out['credit_hold_status'] = $r['credit_hold_status'];
        // RED "Sent" / YELLOW "Signed" / GREEN "Payment Added" / RED "Failed".
        $out['payment_status'] = ratesheet_payment_status($r, $statusNames);
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
    $allRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // One batched live ConnectWise lookup for every submitted row's
    // Company, rather than one API call per row -- see
    // ratesheet_cw_company_statuses()'s docblock in connectwise.php.
    $submittedCompanyIds = array_values(array_filter(array_map(
        fn (array $r) => $r['status'] === 'submitted' && $r['cw_company_id'] !== null ? (int) $r['cw_company_id'] : null,
        $allRows
    )));
    $statusNames = $submittedCompanyIds === [] ? [] : ratesheet_cw_company_statuses($submittedCompanyIds);

    // See ratesheet_maybe_auto_release_credit_hold()'s docblock above --
    // checks Alternative Payments for still-on-hold rows and releases
    // Credit Hold in ConnectWise when a payment method is found.
    // $statusNames is mutated in place so this same render reflects any
    // release immediately.
    ratesheet_maybe_auto_release_credit_hold($allRows, $statusNames);

    $rows = array_map(fn (array $r) => ratesheet_request_row($r, true, $statusNames), $allRows);
    ratesheet_respond(200, ['ok' => true, 'requests' => $rows]);
}

/**
 * The printable "accepted terms" record (added 2026-09-17, follow-up),
 * per Michael: "the accepted terms document... that the customer signed
 * that shows they read and accepted our terms doc, with their signature,
 * timestamp, IP Address and approved checkbox to display when clicked on
 * in the master list." Returns everything receipt.js needs to render
 * that page -- the legal/checkbox text comes from the same
 * ratesheet_legal_text()/ratesheet_checkbox_text() in _util.php used
 * elsewhere, so the printed record can never drift from what the
 * customer actually saw.
 *
 * GET /ratesheet/api/requests.php?action=detail&id=<id>
 *   -> { ok: true, request: {...} } -- 404 if no such row, 403 if this
 *      rep isn't allowed to see it (same admin/own-rows rule as ?action=list).
 *   -> { ok: false, error: 'not yet submitted' } (409) if the customer
 *      hasn't completed the signup yet -- nothing to show.
 */
if ($action === 'detail') {
    $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    if ($id <= 0) {
        ratesheet_respond(400, ['ok' => false, 'error' => 'Missing id.']);
    }
    $stmt = $pdo->prepare('SELECT * FROM rate_sheet_requests WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($r === false) {
        ratesheet_respond(404, ['ok' => false, 'error' => 'Rate sheet not found.']);
    }
    if (!ratesheet_is_admin($user) && strtolower((string) $r['rep_email']) !== strtolower((string) $user['email'])) {
        ratesheet_respond(403, ['ok' => false, 'error' => 'You do not have access to this rate sheet.']);
    }
    if ($r['status'] === 'pending') {
        ratesheet_respond(409, ['ok' => false, 'error' => 'This customer has not submitted their signup yet -- there is nothing to show.']);
    }

    // Single live ConnectWise lookup for this one row (list uses a batched
    // call instead -- see ?action=list above).
    $companyId = $r['cw_company_id'] !== null ? (int) $r['cw_company_id'] : null;
    $liveStatusName = ($r['status'] === 'submitted' && $companyId !== null)
        ? ratesheet_cw_company_status_name($companyId)
        : null;
    $statusNames = ($companyId !== null && $liveStatusName !== null) ? [$companyId => $liveStatusName] : [];

    // See ratesheet_maybe_auto_release_credit_hold()'s docblock above.
    ratesheet_maybe_auto_release_credit_hold([$r], $statusNames);
    $liveStatusName = ($companyId !== null) ? ($statusNames[$companyId] ?? $liveStatusName) : $liveStatusName;

    ratesheet_respond(200, ['ok' => true, 'request' => [
        'id' => (int) $r['id'],
        'status' => $r['status'],
        'payment_status' => ratesheet_payment_status($r, $statusNames),
        'live_billing_status' => $liveStatusName,
        'fail_reason' => $r['fail_reason'],
        'first_name' => $r['first_name'],
        'last_name' => $r['last_name'],
        'business_name' => $r['business_name'],
        'customer_email' => $r['customer_email'],
        'phone' => $r['phone'],
        'address_line1' => $r['address_line1'],
        'address_line2' => $r['address_line2'],
        'city' => $r['city'],
        'state' => $r['state'],
        'zip' => $r['zip'],
        'location' => $r['location'],
        'account_kind' => $r['account_kind'],
        'hourly_rate' => (float) $r['hourly_rate'],
        'payment_method' => $r['payment_method'],
        'credit_hold_status' => $r['credit_hold_status'],
        'invoices_emailed' => $r['invoices_emailed'] === null ? null : (bool) $r['invoices_emailed'],
        'want_copy_of_signup' => $r['want_copy_of_signup'] === null ? null : (bool) $r['want_copy_of_signup'],
        'agreed_to_terms' => (bool) $r['agreed_to_terms'],
        'signature_data' => $r['signature_data'],
        'signed_at' => $r['signed_at'],
        'ip_address' => $r['ip_address'],
        'rep_name' => $r['rep_name'],
        'rep_email' => $r['rep_email'],
        'source' => $r['source'] ?? 'email', // 'email' | 'walkin' -- see db.php's column comment
        'cw_company_id' => $companyId,
        'cw_contact_id' => $r['cw_contact_id'] !== null ? (int) $r['cw_contact_id'] : null,
        'legal_text' => ratesheet_legal_text(),
        'checkbox_text' => ratesheet_checkbox_text(),
    ]]);
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

if ($action === 'clear-test-data') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        ratesheet_respond(405, ['ok' => false, 'error' => 'Method not allowed.']);
    }
    if (!ratesheet_is_admin($user)) {
        ratesheet_respond(403, ['ok' => false, 'error' => 'Only an admin can clear rate sheet data.']);
    }
    $input = ratesheet_read_json_body();
    if (empty($input['confirm'])) {
        ratesheet_respond(400, ['ok' => false, 'error' => 'Missing confirmation.']);
    }
    $deleted = $pdo->exec('DELETE FROM rate_sheet_requests');
    ratesheet_respond(200, ['ok' => true, 'deleted' => (int) $deleted]);
}

ratesheet_respond(400, ['ok' => false, 'error' => 'Unknown action.']);
