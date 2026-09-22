<?php
/**
 * ratesheet/api/walkin.php
 *
 * The WALK-IN counterpart to public.php -- added 2026-09-22 per
 * Michael's request: "We have walk in customers that need a version of
 * the rate sheet that mimics the function of the current version...
 * The walk in customer should see the page that allows them to enter
 * all of their contact and company information, and on submitting, it
 * triggers the same actions as if they were emailing in."
 *
 * public.php's flow is two people, two steps: a rep sends a tokenized
 * link (requests.php's ?action=send, INSERTs a 'pending' row), then the
 * customer completes it later on their own (public.php's ?action=submit,
 * looked up BY that token). A walk-in customer at the counter is one
 * person, one step -- there's no rep, no prior link, and no reason to
 * split "create the row" and "fill it out" into two requests. This file
 * does both in a single ?action=submit call: INSERT a brand-new row
 * (rep_name='Walk-In', source='walkin' -- see db.php's column comment --
 * territory therefore falls to House Accounts, same as every other
 * sender not in ratesheet_rep_territory_search_term()'s map), then hand
 * it straight to ratesheet_process_signup_submission() in submit-core.php
 * -- the EXACT SAME ConnectWise-create + Credit Hold + notification-
 * email logic public.php uses, so a walk-in signup really does trigger
 * "the same actions as if they were emailing in," per Michael's ask.
 *
 * Deliberately login-free, same reasoning as public.php: a walk-in
 * customer filling out their own info at the counter has no
 * SolutionsHub/Microsoft 365 account. Same-origin only, no token needed
 * (there's nothing to look up -- the row doesn't exist until this call
 * creates it).
 *
 * POST /ratesheet/api/walkin.php?action=submit
 *   { location: 'Warsaw'|'Richmond', account_kind: 'Commercial'|'Residential',
 *     first_name, last_name, email, phone, address_line1, address_line2?,
 *     city, state, zip, business_name? (required iff account_kind=Commercial),
 *     payment_method: 'card'|'ach', want_copy_of_signup: bool,
 *     invoices_emailed: bool, agreed_to_terms: true, signature_data_url }
 *   -> same { ok: true } / { ok: false, error } shape as public.php's
 *      ?action=submit -- see submit-core.php for the full behavior
 *      (ConnectWise Company+Contact creation, Credit Hold, the three
 *      notification emails, and the fail-open "we'll finish this by
 *      hand" behavior on a ConnectWise error).
 *   -> { ok: false, error } (400) if location/account_kind are missing
 *      or invalid -- checked here, before the row is even created,
 *      since (unlike public.php) nothing has picked these for the
 *      customer yet; every other field is validated inside
 *      ratesheet_process_signup_submission() same as public.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/_util.php';
require_once __DIR__ . '/connectwise.php';
require_once __DIR__ . '/submit-core.php';

ratesheet_install_error_handlers();

$pdo = ratesheet_db();
$action = $_GET['action'] ?? '';

// Not a real prospect/rep -- there isn't one for a walk-in. Never shown
// to the customer; only visible to staff (rep_email backs the "my own
// sends" dashboard filter for non-admin reps -- see requests.php's
// ?action=list) and it deliberately doesn't match any real
// ratesheet_sender_roster() entry, so a walk-in row is only ever visible
// to an admin (full-dashboard-visibility) account, same as the "We
// should still be able to see the walk-ins in the portal" ask.
const RATESHEET_WALKIN_REP_EMAIL = 'walkin@codebluetechnology.com';

if ($action === 'submit') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        ratesheet_respond(405, ['ok' => false, 'error' => 'Method not allowed.']);
    }

    $input = ratesheet_read_json_body(1048576); // signature PNG can be a few hundred KB

    $location = trim((string) ($input['location'] ?? ''));
    $accountKind = trim((string) ($input['account_kind'] ?? ''));
    if (!in_array($location, ['Warsaw', 'Richmond'], true)) {
        ratesheet_respond(400, ['ok' => false, 'error' => 'Location must be Warsaw or Richmond.']);
    }
    if (!in_array($accountKind, ['Commercial', 'Residential'], true)) {
        ratesheet_respond(400, ['ok' => false, 'error' => 'Kind of Account must be Commercial or Residential.']);
    }

    // The customer's own email doubles as prospect_email (NOT NULL) --
    // there's no separately-known "prospect" here the way public.php has
    // one from send-time. Not yet validated as a real email address at
    // this point (that happens inside ratesheet_process_signup_submission,
    // same as every other field) -- a blank/invalid one just falls back
    // to the sentinel below so the INSERT itself can't fail on it; the
    // submission is still rejected a moment later for the same reason
    // public.php would reject it, before anything is emailed or sent to
    // ConnectWise.
    $prospectEmail = trim((string) ($input['email'] ?? ''));
    if ($prospectEmail === '' || !filter_var($prospectEmail, FILTER_VALIDATE_EMAIL)) {
        $prospectEmail = RATESHEET_WALKIN_REP_EMAIL;
    }

    $rate = ratesheet_hourly_rate($location);
    $token = bin2hex(random_bytes(24));

    $stmt = $pdo->prepare(
        'INSERT INTO rate_sheet_requests
            (token, prospect_email, rep_name, rep_email, location, account_kind, hourly_rate, source)
         VALUES (:token, :pemail, :rname, :remail, :loc, :kind, :rate, :source)'
    );
    $stmt->execute([
        ':token' => $token,
        ':pemail' => $prospectEmail,
        ':rname' => 'Walk-In',
        ':remail' => RATESHEET_WALKIN_REP_EMAIL,
        ':loc' => $location,
        ':kind' => $accountKind,
        ':rate' => $rate,
        ':source' => 'walkin',
    ]);
    $requestId = (int) $pdo->lastInsertId();

    $stmt = $pdo->prepare('SELECT * FROM rate_sheet_requests WHERE id = :id');
    $stmt->execute([':id' => $requestId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        // Should be unreachable (we just inserted it) -- fail loudly
        // rather than silently continuing with no row to update.
        ratesheet_respond(500, ['ok' => false, 'error' => 'Could not create the walk-in signup record. Please try again.']);
    }

    ratesheet_process_signup_submission($pdo, $row, $input);
}

ratesheet_respond(400, ['ok' => false, 'error' => 'Unknown action.']);
