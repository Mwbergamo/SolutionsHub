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
 *      (Company created with its Billing Status set to "Credit Hold" --
 *      see ratesheet_cw_create_company()) and both notification emails
 *      (hello@ general notice, invoicing@ "ready for payment" notice) are
 *      attempted.
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

    $input = ratesheet_read_json_body(1048576); // signature PNG can be a few hundred KB
    $firstName = trim((string) ($input['first_name'] ?? ''));
    $lastName = trim((string) ($input['last_name'] ?? ''));
    $email = trim((string) ($input['email'] ?? ''));
    $phone = trim((string) ($input['phone'] ?? ''));
    $addr1 = trim((string) ($input['address_line1'] ?? ''));
    $addr2 = trim((string) ($input['address_line2'] ?? ''));
    $city = trim((string) ($input['city'] ?? ''));
    $state = trim((string) ($input['state'] ?? ''));
    $zip = trim((string) ($input['zip'] ?? ''));
    $businessName = trim((string) ($input['business_name'] ?? ''));
    $paymentMethod = trim((string) ($input['payment_method'] ?? ''));

    $wantCopy = !empty($input['want_copy_of_signup']);
    $invoicesEmailed = !empty($input['invoices_emailed']);
    $agreed = !empty($input['agreed_to_terms']);
    $signatureDataUrl = trim((string) ($input['signature_data_url'] ?? ''));

    // Added 2026-09-17 (follow-up) for the printable "accepted terms"
    // record -- X-Forwarded-For first in case Bluehost sits behind any
    // proxy/CDN for this request, else the direct connecting address.
    $ipAddress = trim((string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? ''));
    if (str_contains($ipAddress, ',')) {
        $ipAddress = trim(explode(',', $ipAddress)[0]); // X-Forwarded-For can be a chain; the first hop is the client.
    }

    $errors = [];
    if ($firstName === '') $errors[] = 'First name is required.';
    if ($lastName === '') $errors[] = 'Last name is required.';
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid email address is required.';
    if (strlen(preg_replace('/\D+/', '', $phone) ?? '') < 7) $errors[] = 'A valid phone number is required.';
    if ($addr1 === '') $errors[] = 'Address is required.';
    if ($city === '') $errors[] = 'City is required.';
    if ($state === '') $errors[] = 'State is required.';
    if ($zip === '') $errors[] = 'ZIP code is required.';
    if ($row['account_kind'] === 'Commercial' && $businessName === '') $errors[] = 'Business name is required for a Commercial account.';
    if (!in_array($paymentMethod, ['card', 'ach'], true)) $errors[] = 'Please choose a payment method.';
    if (!$agreed) $errors[] = 'You must check the box acknowledging the terms and conditions.';
    if (!str_starts_with($signatureDataUrl, 'data:image/')) $errors[] = 'A signature is required.';

    if ($errors !== []) {
        ratesheet_respond(400, ['ok' => false, 'error' => implode(' ', $errors)]);
    }

    // Residential accounts don't show/collect a business name at all
    // (per Michael) -- the company in ConnectWise is the person's own name.
    $companyName = $row['account_kind'] === 'Commercial' ? $businessName : trim($firstName . ' ' . $lastName);

    $saveSubmission = function (
        string $status,
        ?string $failReason,
        ?int $cwCompanyId,
        ?int $cwContactId,
        ?string $creditHoldStatus
    ) use ($pdo, $row, $firstName, $lastName, $businessName, $email, $phone, $addr1, $addr2, $city, $state, $zip, $paymentMethod, $wantCopy, $invoicesEmailed, $agreed, $signatureDataUrl, $ipAddress): void {
        $stmt = $pdo->prepare(
            'UPDATE rate_sheet_requests SET
                status = :status, fail_reason = :fail_reason,
                first_name = :first_name, last_name = :last_name, business_name = :business_name,
                customer_email = :email, phone = :phone, address_line1 = :addr1, address_line2 = :addr2,
                city = :city, state = :state, zip = :zip,
                payment_method = :payment_method, want_copy_of_signup = :want_copy,
                invoices_emailed = :invoices_emailed, agreed_to_terms = :agreed,
                signature_data = :signature, signed_at = :signed_at, ip_address = :ip_address,
                cw_company_id = :cw_company_id, cw_contact_id = :cw_contact_id,
                credit_hold_status = :credit_hold_status,
                submitted_at = COALESCE(submitted_at, :submitted_at)
             WHERE id = :id'
        );
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $stmt->execute([
            ':status' => $status,
            ':fail_reason' => $failReason,
            ':first_name' => $firstName,
            ':last_name' => $lastName,
            ':business_name' => $businessName !== '' ? $businessName : null,
            ':email' => $email,
            ':phone' => $phone,
            ':addr1' => $addr1,
            ':addr2' => $addr2 !== '' ? $addr2 : null,
            ':city' => $city,
            ':state' => $state,
            ':zip' => $zip,
            ':payment_method' => $paymentMethod,
            ':want_copy' => $wantCopy ? 1 : 0,
            ':invoices_emailed' => $invoicesEmailed ? 1 : 0,
            ':agreed' => $agreed ? 1 : 0,
            ':signature' => $signatureDataUrl,
            ':signed_at' => $now,
            ':ip_address' => $ipAddress !== '' ? $ipAddress : null,
            ':cw_company_id' => $cwCompanyId,
            ':cw_contact_id' => $cwContactId,
            ':credit_hold_status' => $creditHoldStatus,
            ':submitted_at' => $now,
            ':id' => $row['id'],
        ]);
    };

    // Territory, per Sending Representative -- see
    // ratesheet_rep_territory_search_term()'s docblock in _util.php for
    // the full mapping and why this is a live ConnectWise name search
    // rather than a hardcoded id (only House Accounts' id is confirmed).
    $territorySearchTerm = ratesheet_rep_territory_search_term($row['rep_name']);
    $territoryId = $territorySearchTerm !== null
        ? ratesheet_cw_resolve_territory_id($territorySearchTerm)
        : RATESHEET_HOUSE_ACCOUNTS_TERRITORY_ID;

    // Credit Hold, per Michael (2026-09-18): the new Company's Billing
    // Status is set to "Credit Hold" directly at create time. Resolved by
    // live name search (see ratesheet_cw_resolve_status_id_by_name()'s
    // docblock for why the endpoint path itself is unverified) since this
    // is the entire safeguard keeping a not-yet-paying account from being
    // billed -- if it can't be resolved, this does NOT silently fall back
    // to a normal "Active" account with no flag: it still creates the
    // Company (never lose the customer's signed data) but loudly flags
    // BOTH notification emails below so a human catches it immediately.
    $creditHoldStatusId = ratesheet_cw_resolve_status_id_by_name(RATESHEET_CREDIT_HOLD_STATUS_NAME);
    if ($creditHoldStatusId === null) {
        error_log('[ratesheet/public] could not resolve the ConnectWise "Credit Hold" Company Status for request ' . $row['id'] . ' -- creating the Company as Active instead, pending manual correction.');
        $creditHoldStatusId = 1; // Active, confirmed fallback (register/api/customers.php).
    }

    try {
        $company = ratesheet_cw_create_company($companyName, $addr1, $addr2, $city, $state, $zip, $territoryId, $creditHoldStatusId);
        $companyId = (int) $company['id'];
        $contact = ratesheet_cw_create_contact($companyId, $firstName, $lastName, $phone, $email);
        $contactId = (int) $contact['id'];
    } catch (Throwable $e) {
        error_log('[ratesheet/public] ConnectWise create failed for request ' . $row['id'] . ': ' . $e->getMessage());
        $saveSubmission('failed', $e->getMessage(), null, null, null);
        ratesheet_respond(502, ['ok' => false, 'error' => 'We could not finish creating your account automatically, but your information was saved -- a CodeBlue Technology team member will finish setting up your account shortly.']);
    }

    // Verify, don't just trust the resolve step above -- added 2026-09-19
    // after a live test landed on the wrong Billing Status despite the
    // resolve step appearing to succeed (see
    // ratesheet_cw_resolve_status_id_by_name()'s docblock: an ignored
    // `conditions` filter, a wrongly-matched row, or ConnectWise itself
    // silently normalizing the `status` field on create could all cause
    // this). Read the just-created Company back and use its ACTUAL live
    // Billing Status name as the authoritative signal for whether Credit
    // Hold really took -- not whatever the pre-create resolve step
    // returned. $actualStatusName also gets surfaced in both notification
    // emails below so a mismatch is visible without server log access.
    $actualStatusName = ratesheet_cw_company_status_name($companyId);
    $creditHoldApplied = $actualStatusName !== null && stripos($actualStatusName, RATESHEET_CREDIT_HOLD_STATUS_NAME) !== false;
    if (!$creditHoldApplied) {
        error_log('[ratesheet/public] URGENT: ConnectWise Company ' . $companyId . ' (request ' . $row['id'] . ') did not land on "Credit Hold" -- live Billing Status reads "' . ($actualStatusName ?? 'unknown -- verification lookup itself failed') . '". Set it manually.');
    }

    $saveSubmission('submitted', null, $companyId, $contactId, $creditHoldApplied ? 'held' : 'lookup_failed');

    // Both emails are best-effort: a failure here never undoes the
    // ConnectWise create or fails the customer's submission -- they've
    // already signed and their account already exists. Status is logged
    // to the row so staff can see + manually follow up if needed.
    $internalStatus = 'failed';
    $invoicingStatus = 'failed';
    $copyStatus = null;
    $configPath = __DIR__ . '/../../mail/mail-config.php';
    if (is_file($configPath)) {
        /** @var array $config */
        $config = require $configPath;
        require_once __DIR__ . '/../../mail/graph-mailer.php';

        $submission = [
            'first_name' => $firstName, 'last_name' => $lastName, 'business_name' => $businessName,
            'email' => $email, 'phone' => $phone, 'addr1' => $addr1, 'addr2' => $addr2, 'city' => $city, 'state' => $state, 'zip' => $zip,
            'location' => $row['location'], 'account_kind' => $row['account_kind'], 'hourly_rate' => (float) $row['hourly_rate'],
            'payment_method' => $paymentMethod, 'want_copy' => $wantCopy, 'invoices_emailed' => $invoicesEmailed,
            'rep_name' => $row['rep_name'], 'rep_email' => $row['rep_email'],
            'cw_company_id' => $companyId, 'cw_contact_id' => $contactId,
            'credit_hold_applied' => $creditHoldApplied, 'credit_hold_actual_status' => $actualStatusName,
        ];

        try {
            $mailer = new GraphMailer(
                tenantId: (string) $config['tenant_id'],
                clientId: (string) $config['client_id'],
                clientSecret: (string) $config['client_secret'],
                senderUserId: 'Hello@codebluetechnology.com',
            );
            $mailer->send('hello@codebluetechnology.com', 'New Rate Sheet Sign Up — ' . $companyName, ratesheet_internal_notice_html($submission), null, 'CodeBlue Technology — Rate Sheet Sign Up', true);
            $internalStatus = 'sent';
        } catch (Throwable $e) {
            error_log('[ratesheet/public] internal notice email failed for request ' . $row['id'] . ': ' . $e->getMessage());
        }

        // New 2026-09-18, per Michael: the actionable notice telling
        // Invoicing this account is ready for them to add a payment
        // method in Alternative Payments themselves.
        try {
            $mailer->send('invoicing@codebluetechnology.com', ($creditHoldApplied ? '' : '[ACTION NEEDED — Credit Hold not set] ') . 'Ready For Payment On File — ' . $companyName, ratesheet_invoicing_notice_html($submission), null, 'CodeBlue Technology — Rate Sheet Sign Up', true);
            $invoicingStatus = 'sent';
        } catch (Throwable $e) {
            error_log('[ratesheet/public] invoicing notice email failed for request ' . $row['id'] . ': ' . $e->getMessage());
        }

        if ($wantCopy) {
            try {
                $mailer->send($email, 'Your CodeBlue Technology Rate Sheet Sign Up — Copy For Your Records', ratesheet_customer_copy_html($submission), null, 'CodeBlue Technology', true);
                $copyStatus = 'sent';
            } catch (Throwable $e) {
                error_log('[ratesheet/public] customer copy email failed for request ' . $row['id'] . ': ' . $e->getMessage());
                $copyStatus = 'failed';
            }
        }
    }
    $stmt = $pdo->prepare('UPDATE rate_sheet_requests SET internal_email_status = :i, invoicing_email_status = :v, customer_copy_email_status = :c WHERE id = :id');
    $stmt->execute([':i' => $internalStatus, ':v' => $invoicingStatus, ':c' => $copyStatus, ':id' => $row['id']]);

    ratesheet_respond(200, ['ok' => true]);
}

ratesheet_respond(400, ['ok' => false, 'error' => 'Unknown action.']);

// ---------------------------------------------------------------------
// ConnectWise create -- mirrored from register/api/customers.php's
// register_cw_create_company()/register_cw_create_contact(), which are
// proven working end-to-end against this same ConnectWise instance. Kept
// as this app's own copy (not a cross-app include) per this codebase's
// established self-contained-sub-app convention -- see connectwise.php's
// header. Trimmed to what THIS app actually collects: no tax-code
// resolution and no phone number (neither is collected on the public
// signup form), and Contact Title is "Rate Sheet Sign Up" rather than
// register's "Purchaser" (this isn't a point-of-sale purchase) -- an
// assumption, not something Michael specified; easy to change in one
// place if he wants different wording.
// ---------------------------------------------------------------------

function ratesheet_cw_sanitize_account_id(string $name, int $maxLength = 41): string
{
    $clean = preg_replace('/[^A-Za-z0-9 ]+/', '', $name) ?? '';
    $clean = trim(preg_replace('/\s+/', ' ', $clean) ?? '');
    if ($clean === '') {
        $clean = 'Account';
    }
    if (mb_strlen($clean) <= $maxLength) {
        return $clean;
    }
    $concatenated = str_replace(' ', '', $clean);
    return mb_substr($concatenated, 0, $maxLength);
}

/**
 * Resolves a ConnectWise Territory id by a live name search (conditions:
 * name like "%$searchTerm%"), falling back to House Accounts if nothing
 * matches or the lookup itself fails -- see
 * ratesheet_rep_territory_search_term()'s docblock in _util.php for why
 * this is a live search rather than a hardcoded id map: this build
 * environment has no way to confirm the real ids for anything other than
 * House Accounts (45, already proven in register/api/customers.php).
 * Failing open to House Accounts (rather than blocking the whole signup)
 * matches this app's existing philosophy for non-critical ConnectWise
 * steps (see ratesheet_cw_create_company()'s Team-row try/catch below).
 * Unlike Credit Hold's status lookup, a wrong Territory is low-stakes
 * (routing/reporting, not a billing safeguard), so fail-open here is fine.
 */
function ratesheet_cw_resolve_territory_id(string $searchTerm): int
{
    try {
        $condition = 'name like "%' . ratesheet_cw_condition_escape($searchTerm) . '%"';
        $rows = ratesheet_cw_request('/company/territories', ['conditions' => $condition, 'fields' => 'id,name'], 'GET', null, 15, 6);
        if (isset($rows[0]['id']) && is_int($rows[0]['id'])) {
            return (int) $rows[0]['id'];
        }
        error_log('ratesheet_cw_resolve_territory_id: no ConnectWise territory matched "' . $searchTerm . '" -- falling back to House Accounts.');
    } catch (Throwable $e) {
        error_log('ratesheet_cw_resolve_territory_id: lookup failed for "' . $searchTerm . '": ' . $e->getMessage() . ' -- falling back to House Accounts.');
    }
    return RATESHEET_HOUSE_ACCOUNTS_TERRITORY_ID;
}

/**
 * Creates the Company with its Billing Status set to $statusId (per
 * Michael, 2026-09-18: "Credit Hold" until Invoicing manually changes it
 * -- see this file's header and ratesheet_cw_resolve_status_id_by_name()
 * in connectwise.php for how that id is resolved and what happens if it
 * can't be). This is a plain create-time field (the same `status` field
 * this app already sent as Active/id 1 before), not a follow-up
 * PATCH/PUT -- no separate Company-update call is needed for this half of
 * the feature at all.
 */
function ratesheet_cw_create_company(string $name, string $addressLine1, string $addressLine2, string $city, string $state, string $zip, int $territoryId, int $statusId): array
{
    $today = gmdate('Y-m-d\T00:00:00\Z');

    $body = [
        'identifier' => ratesheet_cw_sanitize_account_id($name),
        'name' => $name,
        'country' => ['id' => 1], // United States, confirmed (register/api/customers.php)
        'status' => ['id' => $statusId],
        'site' => ['name' => 'Main'], // confirmed required
        'territory' => ['id' => $territoryId], // resolved per Sending Representative -- see caller
        'accountNumber' => ratesheet_cw_sanitize_account_id($name),
        'dateAcquired' => $today,
        'customFields' => [
            ['id' => 34, 'value' => $today], // "Terms Renewal Date", confirmed
        ],
    ];
    if ($addressLine1 !== '') $body['addressLine1'] = $addressLine1;
    if ($addressLine2 !== '') $body['addressLine2'] = $addressLine2;
    if ($city !== '') $body['city'] = $city;
    if ($state !== '') $body['state'] = $state;
    if ($zip !== '') $body['zip'] = $zip;

    $company = ratesheet_cw_request('/company/companies', [], 'POST', $body, 20, 8);
    $companyId = $company['id'] ?? null;
    if (!is_int($companyId)) {
        throw new RatesheetConnectWiseError('ConnectWise did not return a new company id.');
    }

    foreach ([
        ['teamRole' => ['id' => 3], 'salesFlag' => true],          // Sales Rep, confirmed
        ['teamRole' => ['id' => 1], 'accountManagerFlag' => true], // Account Manager, confirmed
    ] as $teamRow) {
        try {
            ratesheet_cw_request('/company/companies/' . $companyId . '/teams', [], 'POST', $teamRow + ['member' => ['id' => 202]], 20, 8); // Michael Bergamo, confirmed
        } catch (Throwable $e) {
            error_log('ratesheet_cw_create_company: failed to add team row for company ' . $companyId . ': ' . $e->getMessage());
        }
    }

    return $company;
}

/**
 * $phone added 2026-09-19, per Michael. The "Direct" phone communication
 * type (id 2) and the "Phone" communicationType string are the same
 * confirmed values register/api/customers.php's register_cw_create_contact()
 * already uses successfully on this same ConnectWise instance -- reused
 * here rather than guessed fresh.
 */
function ratesheet_cw_create_contact(int $companyId, string $firstName, string $lastName, string $phone, string $email): array
{
    $contact = ratesheet_cw_request('/company/contacts', [], 'POST', [
        'firstName' => $firstName,
        'lastName' => $lastName,
        'company' => ['id' => $companyId],
        'title' => 'Rate Sheet Sign Up',
        'types' => [['id' => 3]], // "End User", confirmed
    ], 20, 8);

    $contactId = $contact['id'] ?? null;
    if (is_int($contactId) && $phone !== '') {
        try {
            ratesheet_cw_request('/company/contacts/' . $contactId . '/communications', [], 'POST', [
                'type' => ['id' => 2], // "Direct", confirmed (register/api/customers.php)
                'value' => $phone,
                'communicationType' => 'Phone',
                'defaultFlag' => true,
            ], 20, 8);
        } catch (Throwable $e) {
            error_log('ratesheet_cw_create_contact: failed to add phone for contact ' . $contactId . ': ' . $e->getMessage());
        }
    }
    if (is_int($contactId) && $email !== '') {
        try {
            ratesheet_cw_request('/company/contacts/' . $contactId . '/communications', [], 'POST', [
                'type' => ['id' => 1], // "Email", confirmed
                'value' => $email,
                'communicationType' => 'Email',
                'defaultFlag' => true,
            ], 20, 8);
        } catch (Throwable $e) {
            error_log('ratesheet_cw_create_contact: failed to add email for contact ' . $contactId . ': ' . $e->getMessage());
        }
    }

    return $contact;
}

// ---------------------------------------------------------------------
// Email templates
// ---------------------------------------------------------------------

/**
 * Internal notice to hello@codebluetechnology.com -- per Michael: "send
 * the text data to hello@codebluetechnology.com." General "new signup"
 * notice (unchanged purpose from earlier in this project); never contains
 * a card or bank account/routing number -- see this file's header, and
 * note this app no longer collects those at all.
 */
function ratesheet_internal_notice_html(array $s): string
{
    $e = static fn (?string $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $row = static fn (string $label, string $value): string =>
        '<tr><td style="padding:4px 12px 4px 0;color:#5A6472;font-size:13px;font-family:Arial,Helvetica,sans-serif;vertical-align:top;white-space:nowrap;"><strong style="color:#33394A;">' . $label . ':</strong></td>' .
        '<td style="padding:4px 0;color:#33394A;font-size:13px;font-family:Arial,Helvetica,sans-serif;">' . $value . '</td></tr>';

    $fullAddress = trim($s['addr1'] . ($s['addr2'] !== '' ? ', ' . $s['addr2'] : '') . ', ' . $s['city'] . ', ' . $s['state'] . ' ' . $s['zip']);
    $paymentLabel = $s['payment_method'] === 'ach' ? 'ACH (bank transfer) — 3% discount applies' : 'Credit Card';
    $billingStatus = $s['credit_hold_applied']
        ? '<span style="color:#1D5FBF;">Credit Hold (awaiting Invoicing)</span>'
        : '<span style="color:#A6362B;">⚠ Not set automatically -- ConnectWise shows "' . $e($s['credit_hold_actual_status'] ?? 'unknown') . '". Set to Credit Hold manually.</span>';

    $rows = $row('Name', $e($s['first_name'] . ' ' . $s['last_name']))
        . ($s['business_name'] !== '' ? $row('Business Name', $e($s['business_name'])) : '')
        . $row('Email', $e($s['email']))
        . $row('Phone', $e($s['phone']))
        . $row('Address', $e($fullAddress))
        . $row('Location', $e($s['location']) . ' ($' . number_format($s['hourly_rate'], 2) . '/hr)')
        . $row('Account Type', $e($s['account_kind']))
        . $row('Payment Method Selected', $e($paymentLabel))
        . $row('ConnectWise Billing Status', $billingStatus)
        . $row('Wants Emailed Invoices', $s['invoices_emailed'] ? 'Yes' : 'No')
        . $row('Sent By', $e($s['rep_name']) . ' (' . $e($s['rep_email']) . ')')
        . $row('ConnectWise Company / Contact', '#' . (int) $s['cw_company_id'] . ' / #' . (int) $s['cw_contact_id']);

    return '<!doctype html><html><head><meta charset="utf-8"></head><body style="margin:0;padding:0;background:#F4F5F7;font-family:Arial,Helvetica,sans-serif;">' .
        '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F4F5F7;padding:24px 0;"><tr><td align="center">' .
        '<table role="presentation" width="640" cellpadding="0" cellspacing="0" style="width:640px;max-width:640px;background:#FFFFFF;border-radius:8px;border:1px solid #E2E5EA;">' .
        '<tr><td style="padding:20px 24px;border-bottom:3px solid #182857;"><div style="font-size:18px;font-weight:800;color:#182857;">New Rate Sheet Sign Up</div></td></tr>' .
        '<tr><td style="padding:16px 24px;"><table role="presentation" cellpadding="0" cellspacing="0">' . $rows . '</table></td></tr>' .
        '</table></td></tr></table></body></html>';
}

/**
 * NEW 2026-09-18, per Michael: "send an email to
 * invoicing@codebluetechnology.com stating that the customer is ready to
 * add their payment on file." A short, actionable email -- just enough
 * for Invoicing to find the ConnectWise Company and add the right kind of
 * payment method in Alternative Payments. If Credit Hold couldn't be set
 * automatically (see this file's header), the subject line and this body
 * both flag it prominently rather than let it go unnoticed.
 */
function ratesheet_invoicing_notice_html(array $s): string
{
    $e = static fn (?string $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $paymentLabel = $s['payment_method'] === 'ach' ? 'ACH (Bank Transfer)' : 'Credit Card';

    $alertBanner = $s['credit_hold_applied'] ? '' :
        '<tr><td style="padding:12px 24px;background:#FBE6E4;border-bottom:1px solid #E5534B;">' .
        '<div style="font-size:13px;font-family:Arial,Helvetica,sans-serif;color:#8a2a24;font-weight:700;">⚠ Action needed: Billing Status could not be set to Credit Hold automatically for this Company (ConnectWise shows it as "' . $e($s['credit_hold_actual_status'] ?? 'unknown') . '"). Please set it manually in ConnectWise.</div>' .
        '</td></tr>';

    return '<!doctype html><html><head><meta charset="utf-8"></head><body style="margin:0;padding:0;background:#F4F5F7;font-family:Arial,Helvetica,sans-serif;">' .
        '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F4F5F7;padding:24px 0;"><tr><td align="center">' .
        '<table role="presentation" width="640" cellpadding="0" cellspacing="0" style="width:640px;max-width:640px;background:#FFFFFF;border-radius:8px;border:1px solid #E2E5EA;">' .
        '<tr><td style="padding:20px 24px;border-bottom:3px solid #182857;"><div style="font-size:18px;font-weight:800;color:#182857;">Ready For Payment On File</div></td></tr>' .
        $alertBanner .
        '<tr><td style="padding:16px 24px;font-size:13px;color:#33394A;line-height:1.7;">' .
        'A new customer has signed their CodeBlue Technology rate sheet and is ready to have a payment method added in Alternative Payments. Their ConnectWise Company was created on <strong>Credit Hold</strong> and should stay that way until this is done.<br><br>' .
        '<strong>Company:</strong> ' . $e($s['business_name'] !== '' ? $s['business_name'] : ($s['first_name'] . ' ' . $s['last_name'])) . ' (ConnectWise Company #' . (int) $s['cw_company_id'] . ')<br>' .
        '<strong>Contact:</strong> ' . $e($s['first_name'] . ' ' . $s['last_name']) . ' — ' . $e($s['email']) . ' — ' . $e($s['phone']) . ' (ConnectWise Contact #' . (int) $s['cw_contact_id'] . ')<br>' .
        '<strong>Requested Payment Method:</strong> ' . $e($paymentLabel) . '<br><br>' .
        'Once the payment method is added in Alternative Payments, please also change this Company\'s Billing Status off Credit Hold in ConnectWise.' .
        '</td></tr>' .
        '</table></td></tr></table></body></html>';
}

/**
 * The customer's OWN copy of what they submitted + the terms they agreed
 * to, sent only when want_copy_of_signup=1 -- per Michael's "Feature: I
 * need a flag presented to the customer to select whether they want a
 * copy of their sign up form and if yes, send them a copy of all data
 * filled along with our terms and conditions."
 *
 * Legal text comes from the shared ratesheet_legal_text() in _util.php
 * (Michael's verbatim wording, chat 2026-09-17) -- the single source of
 * truth also used by the rep-facing printable "accepted terms" record in
 * requests.php. signup.js keeps its own copy since the public form can't
 * call an authenticated endpoint; keep all three in sync if this changes.
 */
function ratesheet_customer_copy_html(array $s): string
{
    $e = static fn (?string $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $fullAddress = trim($s['addr1'] . ($s['addr2'] !== '' ? ', ' . $s['addr2'] : '') . ', ' . $s['city'] . ', ' . $s['state'] . ' ' . $s['zip']);
    $paymentLabel = $s['payment_method'] === 'ach' ? 'ACH (bank transfer)' : 'Credit Card';

    return '<!doctype html><html><head><meta charset="utf-8"></head><body style="margin:0;padding:0;background:#F4F5F7;font-family:Arial,Helvetica,sans-serif;">' .
        '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F4F5F7;padding:24px 0;"><tr><td align="center">' .
        '<table role="presentation" width="640" cellpadding="0" cellspacing="0" style="width:640px;max-width:640px;background:#FFFFFF;border-radius:8px;border:1px solid #E2E5EA;">' .
        '<tr><td style="padding:20px 24px;border-bottom:3px solid #182857;"><div style="font-size:18px;font-weight:800;color:#182857;">Your CodeBlue Technology Sign Up — Copy For Your Records</div></td></tr>' .
        '<tr><td style="padding:16px 24px;font-size:13px;color:#33394A;line-height:1.6;">' .
        '<strong>Name:</strong> ' . $e($s['first_name'] . ' ' . $s['last_name']) . '<br>' .
        ($s['business_name'] !== '' ? '<strong>Business:</strong> ' . $e($s['business_name']) . '<br>' : '') .
        '<strong>Email:</strong> ' . $e($s['email']) . '<br>' .
        '<strong>Address:</strong> ' . $e($fullAddress) . '<br>' .
        '<strong>Rate:</strong> $' . number_format($s['hourly_rate'], 2) . '/hour (' . $e($s['location']) . ')<br>' .
        '<strong>Payment Method:</strong> ' . $e($paymentLabel) . '<br>' .
        '<strong>Emailed Invoices:</strong> ' . ($s['invoices_emailed'] ? 'Yes' : 'No') .
        '</td></tr>' .
        '<tr><td style="padding:8px 24px 24px 24px;font-size:11.5px;color:#5A6472;line-height:1.6;white-space:pre-wrap;">' . htmlspecialchars(ratesheet_legal_text(), ENT_QUOTES, 'UTF-8') . '</td></tr>' .
        '</table></td></tr></table></body></html>';
}
