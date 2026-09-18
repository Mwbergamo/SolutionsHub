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
 * GET  /ratesheet/api/public.php?action=context&t=<token>
 *   -> { ok: true, location, account_kind, hourly_rate, status }
 *      404 if the token doesn't match any request. status drives which
 *      step signup.js shows: 'pending' -> Step 1, 'awaiting_payment' ->
 *      Step 2 (resumed on reload), 'submitted' -> Thank You / Already
 *      Submitted, 'failed' -> Step 1's own generic failure message.
 *
 * UPDATED 2026-09-17 (follow-up #4, per Michael): signup is now two steps
 * against this same row's status column (no new table) --
 *
 * POST /ratesheet/api/public.php?action=step1-submit&t=<token>
 *   { first_name, last_name, email, address_line1, address_line2?, city,
 *     state, zip, business_name? (required iff account_kind=Commercial),
 *     want_copy_of_signup: bool, invoices_emailed: bool,
 *     agreed_to_terms: true, signature_data_url }
 *   -> { ok: true } once the Company (Credit Hold ON --
 *      ratesheet_cw_create_company() below) + Contact are created in
 *      ConnectWise, and an Alternative Payments customer + a PERMANENT
 *      "account setup" invoice exist for this signup (see altpay.php's
 *      ratesheet_altpay_create_placeholder_invoice() -- no longer a
 *      throwaway, never archived). Sets status='awaiting_payment'.
 *   -> { ok: false, error } on validation failure (400) or a ConnectWise
 *      failure (502) -- same fail-open-but-save behavior as before: the
 *      customer's typed data is saved (status='failed', fail_reason set)
 *      so staff can finish by hand; the customer sees a generic
 *      "something went wrong" message (signup.js), not a raw error.
 *
 * POST /ratesheet/api/public.php?action=card-checkout-init&t=<token>
 *   {} (no body needed -- reuses this row's own saved identity/address
 *   and its altpay_customer_id/altpay_invoice_id from Step 1)
 *   -> { ok: true, customer_id, invoice_id, checkout_token, expires_at, environment }
 *      Mints a short-lived checkout-auth token scoped to the customer +
 *      invoice Step 1 already created (falls back to creating them only
 *      for a pre-migration row that reached Step 2 without them).
 *      signup.js uses these to initialize Alternative Payments' Web SDK
 *      and mount its `addPaymentMethod` component. 409 if this row hasn't
 *      completed Step 1 yet.
 *
 * POST /ratesheet/api/public.php?action=step2-submit&t=<token>
 *   { payment_method: 'card'|'ach',
 *     -- card: altpay_customer_id, altpay_payment_method_id,
 *              altpay_payment_method_summary (all already produced by the
 *              Web SDK's addPaymentMethod component before Submit is ever
 *              clicked -- see action=card-checkout-init above)
 *     -- ach:  bank_routing_number, bank_account_number, bank_account_type ('checking'|'savings') }
 *   -> { ok: true } once a payment method is actually vaulted with
 *      Alternative Payments -- sets status='submitted', releases Credit
 *      Hold (ratesheet_cw_release_credit_hold(), connectwise.php -- itself
 *      fail-open/logged, since the customer's payment is already saved by
 *      that point), and sends the internal notice (+ customer copy if
 *      requested) emails.
 *   -> { ok: false, error } on validation failure (400) or a vaulting
 *      failure (502) -- per Michael's explicit call (2026-09-17 follow-up
 *      #4 AskUserQuestion): unlike Step 1, this does NOT fail open. Credit
 *      Hold stays ON and status stays 'awaiting_payment' so the customer
 *      can just retry -- that gate is the entire point of this redesign.
 *
 * PAYMENT DATA -- READ THIS BEFORE CHANGING ANYTHING BELOW: Michael's
 * original spec asked for raw credit-card number/expiry/CVV and bank
 * account/routing numbers to be collected here and emailed in cleartext
 * to hello@codebluetechnology.com "for now." That was declined during
 * planning (2026-09-17): a full card number in an unencrypted email and
 * a CVV persisted/transmitted at all are both flat PCI-DSS violations and
 * a real breach/liability risk to CodeBlue and its customers -- this is
 * a hard line, not a style preference, and holds regardless of business
 * justification. Per Michael's own follow-up answer, this app first
 * shipped with a payment METHOD choice only (card vs. ACH, no numbers),
 * with a clearly-marked spot reserved for the real integration.
 *
 * UPDATED 2026-09-17 (follow-up #2): that integration is now wired in --
 * Alternative Payments (altpay.php). The PCI posture is unchanged, just
 * enforced differently per method:
 *   - Card: CORRECTED (follow-up #3) -- the browser now uses Alternative
 *     Payments' own Web SDK (addPaymentMethod component) to collect and
 *     vault the card entirely client-side, via their Evervault-backed
 *     hosted form. This endpoint never sees any card data at all, not
 *     even encrypted -- it only ever sees the resulting payment_method id
 *     and a display summary ("Visa ending 4242") that the SDK hands back
 *     to signup.js on success. See altpay.php's header for why an earlier
 *     "relay the Evervault ciphertext ourselves" approach didn't work.
 *   - ACH: Alternative Payments' bank payment-method API takes the
 *     routing/account number directly (no client-side tokenization step
 *     is documented for bank accounts). So a raw routing/account number
 *     DOES pass through this endpoint for an ACH signup -- but it lives
 *     only in a local PHP variable long enough to relay it to Alternative
 *     Payments (see ratesheet_altpay_create_bank_payment_method()) and is
 *     then discarded: never written to rate_sheet_requests, never
 *     error_log()'d. Only Alternative Payments' own payment_method id and
 *     a redacted summary ("Bank account ending 6789") are saved.
 * UPDATED 2026-09-17 (follow-up #4): with the two-step signup, a vaulting
 * failure in Step 2 is deliberately NOT fail-open anymore (Michael's
 * explicit call) -- see this file's ?action=step2-submit docs above for
 * why: it's the entire point of gating Credit Hold release on it.
 */

declare(strict_types=1);

require_once __DIR__ . '/_util.php';
require_once __DIR__ . '/connectwise.php';
require_once __DIR__ . '/altpay.php';

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

/**
 * Ensures an Alternative Payments customer exists for this rate sheet
 * row, reusing $row['altpay_customer_id'] if it's already been created
 * (by an earlier card-checkout-init call, or -- in principle -- an
 * earlier submit attempt) rather than creating a duplicate customer
 * record every time. Persists a newly-created id back onto the row
 * immediately, so it survives even if the customer never finishes
 * checkout (useful for staff follow-up, and avoids re-creating it on a
 * page reload).
 */
function ratesheet_altpay_ensure_customer_for_row(
    PDO $pdo,
    array $row,
    string $companyName,
    string $email,
    string $addr1,
    string $addr2,
    string $city,
    string $state,
    string $zip
): string {
    if (!empty($row['altpay_customer_id'])) {
        return (string) $row['altpay_customer_id'];
    }

    $customerId = ratesheet_altpay_create_customer([
        'name' => $companyName,
        'email' => $email,
        'external_id' => 'ratesheet-' . $row['id'],
        'street_address' => $addr1 . ($addr2 !== '' ? ' ' . $addr2 : ''),
        'city' => $city,
        'state' => $state,
        'postal_code' => $zip,
        'country' => 'US',
    ]);

    $stmt = $pdo->prepare('UPDATE rate_sheet_requests SET altpay_customer_id = :cid WHERE id = :id');
    $stmt->execute([':cid' => $customerId, ':id' => $row['id']]);

    return $customerId;
}

/**
 * Ensures a PERMANENT "account setup" Alternative Payments invoice exists
 * for this row, reusing $row['altpay_invoice_id'] if Step 1 already
 * created one -- mirrors ratesheet_altpay_ensure_customer_for_row()'s
 * shape. Added 2026-09-17 (follow-up #4): unlike the old throwaway
 * invoice (created and archived per card attempt), this is created once
 * in Step 1 and never archived -- Step 2 checks out against it.
 */
function ratesheet_altpay_ensure_invoice_for_row(PDO $pdo, array $row, string $customerId): string
{
    if (!empty($row['altpay_invoice_id'])) {
        return (string) $row['altpay_invoice_id'];
    }

    $invoiceId = ratesheet_altpay_create_placeholder_invoice($customerId);

    $stmt = $pdo->prepare('UPDATE rate_sheet_requests SET altpay_invoice_id = :iid WHERE id = :id');
    $stmt->execute([':iid' => $invoiceId, ':id' => $row['id']]);

    return $invoiceId;
}

/**
 * Mints a checkout-auth token for the Web SDK's addPaymentMethod
 * component. UPDATED 2026-09-17 (follow-up #4): now purely a "reuse"
 * action -- the customer + invoice were already created in Step 1
 * (?action=step1-submit) and live on this row, so no identity/address
 * body is needed here anymore. Only falls back to creating them itself
 * for a pre-migration row that somehow reached Step 2 without them.
 */
if ($action === 'card-checkout-init') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        ratesheet_respond(405, ['ok' => false, 'error' => 'Method not allowed.']);
    }
    if ($row['status'] === 'submitted') {
        ratesheet_respond(409, ['ok' => false, 'error' => 'This rate sheet has already been submitted.']);
    }
    if ($row['status'] === 'pending') {
        ratesheet_respond(409, ['ok' => false, 'error' => 'Please complete Step 1 first.']);
    }

    // Same Commercial/Residential company-name rule as step1-submit --
    // only reached if a pre-migration row needs its Alternative Payments
    // customer created here for the first time (the normal case already
    // has one from Step 1).
    $companyName = $row['account_kind'] === 'Commercial'
        ? (string) $row['business_name']
        : trim((string) $row['first_name'] . ' ' . (string) $row['last_name']);

    try {
        $customerId = ratesheet_altpay_ensure_customer_for_row(
            $pdo, $row, $companyName, (string) $row['customer_email'],
            (string) $row['address_line1'], (string) $row['address_line2'],
            (string) $row['city'], (string) $row['state'], (string) $row['zip']
        );
        $invoiceId = ratesheet_altpay_ensure_invoice_for_row($pdo, $row, $customerId);
        $checkoutAuth = ratesheet_altpay_checkout_auth_token($customerId, $invoiceId);
    } catch (Throwable $e) {
        error_log('[ratesheet/public] card-checkout-init failed for request ' . $row['id'] . ': ' . $e->getMessage());
        // TEMPORARY DEBUG (2026-09-17, follow-up #3): same reasoning as the
        // debug prefix this replaced -- surfacing Alternative Payments' own
        // error text (never our client_id/client_secret) while we confirm
        // this corrected flow against the sandbox. Revert to a generic
        // message once confirmed working end-to-end.
        ratesheet_respond(502, ['ok' => false, 'error' => 'DEBUG: ' . $e->getMessage()]);
    }

    $config = ratesheet_altpay_config();
    ratesheet_respond(200, [
        'ok' => true,
        'customer_id' => $customerId,
        'invoice_id' => $invoiceId,
        'checkout_token' => $checkoutAuth['token'],
        'expires_at' => $checkoutAuth['expires_at'],
        'environment' => $config['environment'] ?? 'staging',
    ]);
}

/**
 * Step 1 -- identity, address, terms, signature. Creates the ConnectWise
 * Company (Credit Hold ON) + Contact and the Alternative Payments
 * customer + permanent setup invoice, then sets status='awaiting_payment'
 * so Step 2 can check out against them. See this file's header for the
 * full contract, and connectwise.php's ratesheet_cw_release_credit_hold()
 * for where Credit Hold eventually comes back off.
 */
if ($action === 'step1-submit') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        ratesheet_respond(405, ['ok' => false, 'error' => 'Method not allowed.']);
    }
    if ($row['status'] !== 'pending' && $row['status'] !== 'failed') {
        // 'failed' is allowed through again -- same as before, a failed
        // Step 1 (ConnectWise create itself) should be retryable rather
        // than a dead end. 'awaiting_payment'/'submitted' mean Step 1
        // already succeeded -- nothing to redo.
        ratesheet_respond(409, ['ok' => false, 'error' => 'This step has already been completed.']);
    }

    $input = ratesheet_read_json_body(1048576); // signature PNG can be a few hundred KB
    $firstName = trim((string) ($input['first_name'] ?? ''));
    $lastName = trim((string) ($input['last_name'] ?? ''));
    $email = trim((string) ($input['email'] ?? ''));
    $addr1 = trim((string) ($input['address_line1'] ?? ''));
    $addr2 = trim((string) ($input['address_line2'] ?? ''));
    $city = trim((string) ($input['city'] ?? ''));
    $state = trim((string) ($input['state'] ?? ''));
    $zip = trim((string) ($input['zip'] ?? ''));
    $businessName = trim((string) ($input['business_name'] ?? ''));

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
    if ($addr1 === '') $errors[] = 'Address is required.';
    if ($city === '') $errors[] = 'City is required.';
    if ($state === '') $errors[] = 'State is required.';
    if ($zip === '') $errors[] = 'ZIP code is required.';
    if ($row['account_kind'] === 'Commercial' && $businessName === '') $errors[] = 'Business name is required for a Commercial account.';
    if (!$agreed) $errors[] = 'You must check the box acknowledging the terms and conditions.';
    if (!str_starts_with($signatureDataUrl, 'data:image/')) $errors[] = 'A signature is required.';

    if ($errors !== []) {
        ratesheet_respond(400, ['ok' => false, 'error' => implode(' ', $errors)]);
    }

    // Residential accounts don't show/collect a business name at all
    // (per Michael) -- the company in ConnectWise is the person's own name.
    $companyName = $row['account_kind'] === 'Commercial' ? $businessName : trim($firstName . ' ' . $lastName);

    $saveStep1 = function (
        string $status,
        ?string $failReason,
        ?int $cwCompanyId,
        ?int $cwContactId,
        ?string $altpayCustomerId = null,
        ?string $altpayInvoiceId = null
    ) use ($pdo, $row, $firstName, $lastName, $businessName, $email, $addr1, $addr2, $city, $state, $zip, $wantCopy, $invoicesEmailed, $agreed, $signatureDataUrl, $ipAddress): void {
        $stmt = $pdo->prepare(
            'UPDATE rate_sheet_requests SET
                status = :status, fail_reason = :fail_reason,
                first_name = :first_name, last_name = :last_name, business_name = :business_name,
                customer_email = :email, address_line1 = :addr1, address_line2 = :addr2,
                city = :city, state = :state, zip = :zip,
                want_copy_of_signup = :want_copy, invoices_emailed = :invoices_emailed, agreed_to_terms = :agreed,
                signature_data = :signature, signed_at = :signed_at, ip_address = :ip_address,
                cw_company_id = :cw_company_id, cw_contact_id = :cw_contact_id,
                altpay_customer_id = COALESCE(:altpay_customer_id, altpay_customer_id),
                altpay_invoice_id = COALESCE(:altpay_invoice_id, altpay_invoice_id)
             WHERE id = :id'
        );
        $stmt->execute([
            ':status' => $status,
            ':fail_reason' => $failReason,
            ':first_name' => $firstName,
            ':last_name' => $lastName,
            ':business_name' => $businessName !== '' ? $businessName : null,
            ':email' => $email,
            ':addr1' => $addr1,
            ':addr2' => $addr2 !== '' ? $addr2 : null,
            ':city' => $city,
            ':state' => $state,
            ':zip' => $zip,
            ':want_copy' => $wantCopy ? 1 : 0,
            ':invoices_emailed' => $invoicesEmailed ? 1 : 0,
            ':agreed' => $agreed ? 1 : 0,
            ':signature' => $signatureDataUrl,
            ':signed_at' => gmdate('Y-m-d\TH:i:s\Z'),
            ':ip_address' => $ipAddress !== '' ? $ipAddress : null,
            ':cw_company_id' => $cwCompanyId,
            ':cw_contact_id' => $cwContactId,
            ':altpay_customer_id' => $altpayCustomerId,
            ':altpay_invoice_id' => $altpayInvoiceId,
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

    try {
        $company = ratesheet_cw_create_company($companyName, $addr1, $addr2, $city, $state, $zip, $territoryId);
        $companyId = (int) $company['id'];
        $contact = ratesheet_cw_create_contact($companyId, $firstName, $lastName, $email);
        $contactId = (int) $contact['id'];
    } catch (Throwable $e) {
        error_log('[ratesheet/public] ConnectWise create failed for request ' . $row['id'] . ': ' . $e->getMessage());
        $saveStep1('failed', $e->getMessage(), null, null);
        ratesheet_respond(502, ['ok' => false, 'error' => 'We could not finish creating your account automatically, but your information was saved -- a CodeBlue Technology team member will finish setting up your account shortly.']);
    }

    // Alternative Payments customer + permanent setup invoice, needed so
    // Step 2 (action=card-checkout-init) has something to check out
    // against. Best-effort: if this fails, Step 1 still succeeds (the
    // ConnectWise account is the part that can't be redone) -- Step 2's
    // own card-checkout-init will just create these itself on demand.
    $altpayCustomerId = null;
    $altpayInvoiceId = null;
    try {
        $altpayCustomerId = ratesheet_altpay_ensure_customer_for_row($pdo, $row, $companyName, $email, $addr1, $addr2, $city, $state, $zip);
        $altpayInvoiceId = ratesheet_altpay_ensure_invoice_for_row($pdo, $row, $altpayCustomerId);
    } catch (Throwable $e) {
        error_log('[ratesheet/public] Alternative Payments customer/invoice setup failed for request ' . $row['id'] . ': ' . $e->getMessage());
    }

    $saveStep1('awaiting_payment', null, $companyId, $contactId, $altpayCustomerId, $altpayInvoiceId);

    ratesheet_respond(200, ['ok' => true]);
}

/**
 * Step 2 -- payment only. Checks out against the invoice Step 1 created,
 * vaults the payment method, and only THEN releases Credit Hold. Per
 * Michael's explicit call (2026-09-17 follow-up #4): unlike Step 1 (and
 * unlike the old single-step flow), a vaulting failure here does NOT save
 * or fail open -- status stays 'awaiting_payment' and Credit Hold stays
 * on, so the customer can just retry. See this file's header.
 */
if ($action === 'step2-submit') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        ratesheet_respond(405, ['ok' => false, 'error' => 'Method not allowed.']);
    }
    if ($row['status'] === 'submitted') {
        ratesheet_respond(409, ['ok' => false, 'error' => 'This rate sheet has already been submitted.']);
    }
    if ($row['status'] !== 'awaiting_payment') {
        ratesheet_respond(409, ['ok' => false, 'error' => 'Please complete Step 1 first.']);
    }

    $input = ratesheet_read_json_body();
    $paymentMethod = trim((string) ($input['payment_method'] ?? ''));

    // Card: by the time Submit is clicked, the card has already been
    // vaulted client-side via Alternative Payments' Web SDK (see
    // action=card-checkout-init above and signup.js) -- these are just
    // the resulting ids/summary, never card data itself. ACH: raw
    // routing/account numbers, held only in these local variables and
    // relayed straight through to Alternative Payments below -- never
    // written to $input's origin (the request body) back out, never
    // persisted, never logged.
    $altpayCustomerIdInput = trim((string) ($input['altpay_customer_id'] ?? ''));
    $altpayPaymentMethodIdInput = trim((string) ($input['altpay_payment_method_id'] ?? ''));
    $altpayPaymentMethodSummaryInput = trim((string) ($input['altpay_payment_method_summary'] ?? ''));
    $bankRoutingNumber = trim((string) ($input['bank_routing_number'] ?? ''));
    $bankAccountNumber = trim((string) ($input['bank_account_number'] ?? ''));
    $bankAccountType = trim((string) ($input['bank_account_type'] ?? ''));

    $errors = [];
    if (!in_array($paymentMethod, ['card', 'ach'], true)) {
        $errors[] = 'Please choose a payment method.';
    } elseif ($paymentMethod === 'card') {
        if ($altpayPaymentMethodIdInput === '' || $altpayCustomerIdInput === '') {
            $errors[] = 'Please add a card before submitting.';
        }
    } else { // ach
        if (!preg_match('/^\d{9}$/', $bankRoutingNumber)) $errors[] = 'A valid 9-digit routing number is required.';
        if ($bankAccountNumber === '' || !preg_match('/^\d{4,17}$/', $bankAccountNumber)) $errors[] = 'A valid bank account number is required.';
        if (!in_array($bankAccountType, ['checking', 'savings'], true)) $errors[] = 'Please choose checking or savings.';
    }
    if ($errors !== []) {
        ratesheet_respond(400, ['ok' => false, 'error' => implode(' ', $errors)]);
    }

    // Everything else (name/address/business name) already lives on the
    // row from Step 1 -- this step only ever touches payment fields.
    $firstName = (string) $row['first_name'];
    $lastName = (string) $row['last_name'];
    $businessName = (string) $row['business_name'];
    $email = (string) $row['customer_email'];
    $addr1 = (string) $row['address_line1'];
    $addr2 = (string) $row['address_line2'];
    $city = (string) $row['city'];
    $state = (string) $row['state'];
    $zip = (string) $row['zip'];
    $companyName = $row['account_kind'] === 'Commercial' ? $businessName : trim($firstName . ' ' . $lastName);
    $companyId = $row['cw_company_id'] !== null ? (int) $row['cw_company_id'] : null;
    $contactId = $row['cw_contact_id'] !== null ? (int) $row['cw_contact_id'] : null;

    $altpayCustomerId = null;
    $altpayPaymentMethodId = null;
    $altpaySummary = null;

    if ($paymentMethod === 'card') {
        // Nothing to call here -- the card was already vaulted client-side
        // via Alternative Payments' Web SDK before Submit was even
        // clickable (see action=card-checkout-init and signup.js). We just
        // trust the row's own stored customer id over whatever the client
        // resent (it can only be identical or stale, never newer), and
        // fall back to the client-provided value only if the row somehow
        // doesn't have one yet.
        $altpayCustomerId = !empty($row['altpay_customer_id']) ? (string) $row['altpay_customer_id'] : $altpayCustomerIdInput;
        $altpayPaymentMethodId = $altpayPaymentMethodIdInput;
        $altpaySummary = $altpayPaymentMethodSummaryInput !== '' ? $altpayPaymentMethodSummaryInput : 'Card on file';
    } else {
        try {
            $altpayCustomerId = ratesheet_altpay_ensure_customer_for_row($pdo, $row, $companyName, $email, $addr1, $addr2, $city, $state, $zip);
            $vaulted = ratesheet_altpay_create_bank_payment_method($altpayCustomerId, [
                'routing_number' => $bankRoutingNumber,
                'account_number' => $bankAccountNumber,
                'subtype' => $bankAccountType,
                'receiver_name' => trim($firstName . ' ' . $lastName),
            ]);
            $altpayPaymentMethodId = $vaulted['id'];
            $altpaySummary = $vaulted['summary'];
        } catch (Throwable $e) {
            // Deliberately NOT logging $e->getMessage() here -- Alternative
            // Payments' error response could conceivably echo back the
            // submitted routing/account number in a validation message, and
            // that must never land in a server log.
            error_log('[ratesheet/public] Alternative Payments bank vaulting failed for request ' . $row['id'] . ' (' . get_class($e) . ') -- see Alternative Payments dashboard for details.');
            // Per Michael's explicit call: do NOT save or fail open here --
            // status stays 'awaiting_payment', Credit Hold stays on, and
            // the customer just sees an error and can try again.
            ratesheet_respond(502, ['ok' => false, 'error' => 'We could not save your bank account for payment. Please double-check the routing and account numbers and try again, or choose a card instead.']);
        }
    }

    $stmt = $pdo->prepare(
        'UPDATE rate_sheet_requests SET
            status = :status, payment_method = :payment_method,
            altpay_customer_id = :altpay_customer_id, altpay_payment_method_id = :altpay_payment_method_id,
            altpay_payment_method_summary = :altpay_summary, altpay_status = :altpay_status,
            altpay_fail_reason = NULL, submitted_at = COALESCE(submitted_at, :submitted_at)
         WHERE id = :id'
    );
    $stmt->execute([
        ':status' => 'submitted',
        ':payment_method' => $paymentMethod,
        ':altpay_customer_id' => $altpayCustomerId,
        ':altpay_payment_method_id' => $altpayPaymentMethodId,
        ':altpay_summary' => $altpaySummary,
        ':altpay_status' => 'vaulted',
        ':submitted_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ':id' => $row['id'],
    ]);

    // Only now -- payment actually vaulted and saved -- release Credit
    // Hold. Fail-open/logged (connectwise.php): a ConnectWise quirk here
    // should never re-surface as a failure to a customer who already paid.
    if ($companyId !== null) {
        ratesheet_cw_release_credit_hold($companyId);
    }

    // Both emails are best-effort: a failure here never undoes the
    // ConnectWise account or the payment method already on file. Status
    // is logged to the row so staff can see + manually follow up if needed.
    $internalStatus = 'failed';
    $copyStatus = null;
    $configPath = __DIR__ . '/../../mail/mail-config.php';
    if (is_file($configPath)) {
        /** @var array $config */
        $config = require $configPath;
        require_once __DIR__ . '/../../mail/graph-mailer.php';

        $wantCopy = (bool) $row['want_copy_of_signup'];
        $submission = [
            'first_name' => $firstName, 'last_name' => $lastName, 'business_name' => $businessName,
            'email' => $email, 'addr1' => $addr1, 'addr2' => $addr2, 'city' => $city, 'state' => $state, 'zip' => $zip,
            'location' => $row['location'], 'account_kind' => $row['account_kind'], 'hourly_rate' => (float) $row['hourly_rate'],
            'payment_method' => $paymentMethod, 'want_copy' => $wantCopy, 'invoices_emailed' => (bool) $row['invoices_emailed'],
            'rep_name' => $row['rep_name'], 'rep_email' => $row['rep_email'],
            'cw_company_id' => $companyId, 'cw_contact_id' => $contactId,
            'altpay_summary' => $altpaySummary, 'altpay_status' => 'vaulted',
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
    $stmt = $pdo->prepare('UPDATE rate_sheet_requests SET internal_email_status = :i, customer_copy_email_status = :c WHERE id = :id');
    $stmt->execute([':i' => $internalStatus, ':c' => $copyStatus, ':id' => $row['id']]);

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
 * Resolves a ConnectWise Company STATUS id by a live name search against
 * /company/statuses. Added/CORRECTED 2026-09-17 (follow-up #4, per
 * Michael): "Credit Hold" is not a separate boolean field on Company at
 * all -- it's one of the Company Status values (the same `status` field
 * this app already sets to Active/id=1 at creation), set from the
 * Company's Finance area in the ConnectWise UI. The earlier attempt to
 * PUT a made-up 'creditHold' boolean broke company creation outright (no
 * such field exists to reject gracefully). No safe universal fallback
 * exists here the way House Accounts does for territories (see
 * ratesheet_cw_resolve_territory_id() above) -- if this instance has no
 * Company Status literally named "Credit Hold" configured, returns null
 * and the caller (ratesheet_cw_create_company() below) just leaves the
 * company at its default Active status, logged for staff to configure.
 */
function ratesheet_cw_resolve_company_status_id(string $name): ?int
{
    try {
        $condition = 'name = "' . ratesheet_cw_condition_escape($name) . '"';
        $rows = ratesheet_cw_request('/company/statuses', ['conditions' => $condition, 'fields' => 'id,name'], 'GET', null, 15, 6);
        if (isset($rows[0]['id']) && is_int($rows[0]['id'])) {
            return (int) $rows[0]['id'];
        }
        error_log('ratesheet_cw_resolve_company_status_id: no ConnectWise Company Status named "' . $name . '" found.');
    } catch (Throwable $e) {
        error_log('ratesheet_cw_resolve_company_status_id: lookup failed for "' . $name . '": ' . $e->getMessage());
    }
    return null;
}

function ratesheet_cw_create_company(string $name, string $addressLine1, string $addressLine2, string $city, string $state, string $zip, int $territoryId): array
{
    $today = gmdate('Y-m-d\T00:00:00\Z');

    $body = [
        'identifier' => ratesheet_cw_sanitize_account_id($name),
        'name' => $name,
        'country' => ['id' => 1], // United States, confirmed (register/api/customers.php)
        'status' => ['id' => 1], // Active, confirmed
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

    // Credit Hold ON, added 2026-09-17 (follow-up #4), CORRECTED same day
    // per Michael: this is not a boolean field -- it's the Company's
    // STATUS (the same field already set to Active/id=1 above), changed
    // to a status literally named "Credit Hold" from the Finance area of
    // the Company record. "The company must be created in ConnectWise
    // first. Once saved, within the Company Finance, the account status
    // should be changed to Credit Hold." -- so this is deliberately a
    // SEPARATE best-effort PUT after creation, not part of the POST body
    // above: creating the account is the part that can't be redone (same
    // reasoning as the team-row assignments right below), so it must
    // succeed even if this instance turns out to have no "Credit Hold"
    // status configured.
    $creditHoldStatusId = ratesheet_cw_resolve_company_status_id('Credit Hold');
    if ($creditHoldStatusId !== null) {
        try {
            ratesheet_cw_put_company_with_retry($companyId, ['status' => ['id' => $creditHoldStatusId]]);
        } catch (Throwable $e) {
            error_log('ratesheet_cw_create_company: failed to set Credit Hold status for company ' . $companyId . ': ' . $e->getMessage());
        }
    } else {
        error_log('ratesheet_cw_create_company: no "Credit Hold" Company Status configured on this ConnectWise instance -- company ' . $companyId . ' left at its default Active status.');
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

function ratesheet_cw_create_contact(int $companyId, string $firstName, string $lastName, string $email): array
{
    $contact = ratesheet_cw_request('/company/contacts', [], 'POST', [
        'firstName' => $firstName,
        'lastName' => $lastName,
        'company' => ['id' => $companyId],
        'title' => 'Rate Sheet Sign Up',
        'types' => [['id' => 3]], // "End User", confirmed
    ], 20, 8);

    $contactId = $contact['id'] ?? null;
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
 * the text data to hello@codebluetechnology.com." Never contains a card
 * or bank account/routing number -- see this file's header. Shows
 * whether the payment method was successfully vaulted with Alternative
 * Payments (and a redacted summary if so) or needs manual follow-up.
 */
function ratesheet_internal_notice_html(array $s): string
{
    $e = static fn (?string $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $row = static fn (string $label, string $value): string =>
        '<tr><td style="padding:4px 12px 4px 0;color:#5A6472;font-size:13px;font-family:Arial,Helvetica,sans-serif;vertical-align:top;white-space:nowrap;"><strong style="color:#33394A;">' . $label . ':</strong></td>' .
        '<td style="padding:4px 0;color:#33394A;font-size:13px;font-family:Arial,Helvetica,sans-serif;">' . $value . '</td></tr>';

    $fullAddress = trim($s['addr1'] . ($s['addr2'] !== '' ? ', ' . $s['addr2'] : '') . ', ' . $s['city'] . ', ' . $s['state'] . ' ' . $s['zip']);
    $paymentLabel = $s['payment_method'] === 'ach' ? 'ACH (bank transfer) — 3% discount applies' : 'Credit Card';
    $paymentOnFile = $s['altpay_status'] === 'vaulted'
        ? $e($s['altpay_summary']) . ' <span style="color:#1E8A4C;">(saved to Alternative Payments)</span>'
        : '<span style="color:#A6362B;">Not saved automatically -- please collect and enter this manually.</span>';

    $rows = $row('Name', $e($s['first_name'] . ' ' . $s['last_name']))
        . ($s['business_name'] !== '' ? $row('Business Name', $e($s['business_name'])) : '')
        . $row('Email', $e($s['email']))
        . $row('Address', $e($fullAddress))
        . $row('Location', $e($s['location']) . ' ($' . number_format($s['hourly_rate'], 2) . '/hr)')
        . $row('Account Type', $e($s['account_kind']))
        . $row('Payment Method Chosen', $e($paymentLabel))
        . $row('Payment On File', $paymentOnFile)
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
