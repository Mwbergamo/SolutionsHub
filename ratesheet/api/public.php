<?php
/**
 * ratesheet/api/public.php
 *
 * The CUSTOMER-facing side of the Customer Rate Sheet Sign Up app (added
 * 2026-09-17, per Michael). Deliberately login-free -- a prospect filling
 * out their own signup has no SolutionsHub/Microsoft 365 account -- so
 * access control here is a random 24-byte token (see requests.php's
 * ?action=send) rather than ratesheet_require_login(). Same-origin only
 * (no CORS headers added): the public page (signup.html) that calls this
 * is served from this same site.
 *
 * REDESIGNED 2026-09-18 (second redesign this same day) back into a
 * SINGLE-STEP flow, per Michael's explicit new workflow -- replacing the
 * two-step (Step 1 info/terms, Step 2 self-service Alternative Payments
 * card/ACH vaulting via their Web SDK) design from earlier the same day.
 * Michael's own words: "I want the prospect to fill out their info and
 * select Card or ACH, accept all terms and sign. When the acceptance has
 * been complete, I want it to send an email to
 * invoicing@codebluetechnology.com stating that the customer is ready to
 * add their payment on file. When the customer signs up in the form, the
 * company and contact should be added to ConnectWise and the Billing
 * Status of that company should be marked 'Credit Hold' until Invoicing
 * team changes it manually with a card/ach added in Alt Pay."
 *
 * So: this app no longer calls Alternative Payments' API AT ALL. Payment
 * Method (Card vs. ACH) is collected as a plain preference only -- no card
 * number, no bank routing/account number, nothing self-service. Once the
 * customer submits, the Invoicing team gets an email and handles adding
 * the actual payment method directly in Alternative Payments' own
 * dashboard, and manually changes the ConnectWise Company's Billing
 * Status off "Credit Hold" once that's done. See requests.php for how the
 * rep dashboard reflects that (live ConnectWise Status lookup, not
 * anything tracked in this app's own database) -- Michael's explicit
 * choice over a manual "mark complete" button in this app, so ConnectWise
 * stays the single source of truth.
 *
 * altpay.php (the Alternative Payments REST client built earlier this
 * session) is UNUSED by this file now -- kept in the repo, not deleted,
 * in case self-service vaulting is revisited later, but nothing here
 * requires or calls it anymore. This also makes the whole `403
 * ForbiddenError` saga from earlier moot for this app: nothing here talks
 * to Alternative Payments' API at all anymore.
 *
 * GET  /ratesheet/api/public.php?action=context&t=<token>
 *   -> { ok: true, location, account_kind, hourly_rate, status }
 *      404 if the token doesn't match any request.
 *
 * POST /ratesheet/api/public.php?action=submit&t=<token>
 *   { first_name, last_name, email, address_line1, address_line2?, city,
 *     state, zip, business_name? (required iff account_kind=Commercial),
 *     payment_method: 'card'|'ach' (a PREFERENCE only -- no card/bank
 *       numbers are collected on this form at all anymore),
 *     want_copy_of_signup: bool, invoices_emailed: bool,
 *     agreed_to_terms: true, signature_data_url }
 *   -> { ok: true } once the Company + Contact are created in ConnectWise
 *      (Company created with its Billing Status set to "Credit Hold" at
 *      create time -- see ratesheet_cw_create_company() -- and then, per
 *      Michael, 2026-09-22, EXPLICITLY re-set via a follow-up
 *      ratesheet_cw_put_company_with_retry() call, the same fetch-merge-
 *      PUT-with-retry mechanism register/relationships already use for
 *      Company updates. This mirrors what a person does by hand: open the
 *      new Company's Finance tab, change Status to "Credit Hold," and
 *      click Save -- a real corrective action, not just a warning, since
 *      the create-time field alone was found not to reliably stick) and
 *      both notification emails (hello@ general notice, invoicing@
 *      "ready for payment" notice) are attempted.
 *   -> { ok: false, error } on validation failure (400), if this row has
 *      already been submitted (409), or on a ConnectWise failure (502) --
 *      a ConnectWise failure still SAVES everything the customer typed
 *      (status='failed', fail_reason set) so staff can finish the signup
 *      by hand rather than the customer's work being lost; the customer
 *      sees a plain "something went wrong, we'll finish this for you"
 *      message (built by signup.js), not a raw error.
 *
 * PAYMENT DATA -- READ THIS BEFORE CHANGING ANYTHING BELOW: Michael's
 * original spec asked for raw credit-card number/expiry/CVV and bank
 * account/routing numbers to be collected here and emailed in cleartext
 * to hello@codebluetechnology.com "for now." That was declined during
 * planning (2026-09-17): a full card number in an unencrypted email and
 * a CVV persisted/transmitted at all are both flat PCI-DSS violations and
 * a real breach/liability risk to CodeBlue and its customers -- this is
 * a hard line, not a style preference, and holds regardless of business
 * justification. This app has never collected raw card/bank numbers on
 * the public form as a result -- first as a placeholder "method choice
 * only," briefly replaced same-day by real Alternative Payments
 * self-service vaulting, and now (this redesign) back to a method
 * choice only, this time permanently by design: Invoicing collects the
 * actual payment method directly in Alternative Payments' own dashboard,
 * never through this app.
 */

declare(strict_types=1);

require_once __DIR__ . '/_util.php';
require_once __DIR__ . '/connectwise.php';
require_once __DIR__ . '/submit-core.php'; // ratesheet_process_signup_submission() -- shared with walkin.php, see its header

ratesheet_install_error_handlers();

$pdo = ratesheet_db();
$action = $_GET['action'] ?? '';
$token = trim((string) ($_GET['t'] ?? ''));

if ($token === '') {
    ratesheet_respond(400, ['ok' => false, 'error' => 'Missing signup link token.']);
}

$stmt = $pdo->prepare('SELECT * FROM rate_sheet_requests WHERE token = :token');
$stmt->execute([':token' => $token]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if ($row === false) {
    ratesheet_respond(404, ['ok' => false, 'error' => 'This signup link is not valid. Please contact CodeBlue Technology for a new one.']);
}

if ($action === 'context') {
    ratesheet_respond(200, [
        'ok' => true,
        'location' => $row['location'],
        'account_kind' => $row['account_kind'],
        'hourly_rate' => (float) $row['hourly_rate'],
        'status' => $row['status'],
    ]);
}

if ($action === 'submit') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        ratesheet_respond(405, ['ok' => false, 'error' => 'Method not allowed.']);
    }
    if ($row['status'] === 'submitted') {
        ratesheet_respond(409, ['ok' => false, 'error' => 'This rate sheet has already been submitted.']);
    }

    // See submit-core.php -- extracted 2026-09-22 so walkin.php (added the
    // same day, per Michael's walk-in rate sheet request) can run a
    // walk-in customer's submission through the exact same ConnectWise-
    // create + Credit Hold + notification-email logic, rather than a
    // second copy of it. This call is otherwise identical to what used
    // to be inline here -- pure move, no behavior change.
    $input = ratesheet_read_json_body(1048576); // signature PNG can be a few hundred KB
    ratesheet_process_signup_submission($pdo, $row, $input);
}

ratesheet_respond(400, ['ok' => false, 'error' => 'Unknown action.']);
