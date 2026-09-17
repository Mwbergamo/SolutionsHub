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
 *      404 if the token doesn't match any request.
 *
 * POST /ratesheet/api/public.php?action=card-checkout-init&t=<token>
 *   { first_name, last_name, email, business_name?, address_line1,
 *     address_line2?, city, state, zip, invoice_id? }
 *   -> { ok: true, customer_id, invoice_id, checkout_token, expires_at, environment }
 *      Creates (or reuses, if this row already has one) an Alternative
 *      Payments customer for this signup, creates a minimal throwaway
 *      invoice (or reuses invoice_id if passed in -- a token-refresh call
 *      shouldn't create a second one), then mints a short-lived
 *      checkout-auth token scoped to both. signup.js uses these to
 *      initialize Alternative Payments' own Web SDK client-side and mount
 *      its `addPaymentMethod` component -- see altpay.php's header for why
 *      this replaced an earlier hand-rolled approach that didn't work,
 *      and why a throwaway invoice is involved at all.
 *
 * POST /ratesheet/api/public.php?action=card-archive-invoice&t=<token>
 *   { invoice_id }
 *   -> { ok: true } always. Best-effort cleanup of the throwaway invoice
 *      above, called once a card is actually vaulted.
 *
 * POST /ratesheet/api/public.php?action=submit&t=<token>
 *   { first_name, last_name, email, address_line1, address_line2?, city,
 *     state, zip, business_name? (required iff account_kind=Commercial),
 *     payment_method: 'card'|'ach',
 *     -- card: altpay_customer_id, altpay_payment_method_id,
 *              altpay_payment_method_summary (all already produced by the
 *              Web SDK's addPaymentMethod component before Submit is ever
 *              clicked -- see action=card-checkout-init above)
 *     -- ach:  bank_routing_number, bank_account_number, bank_account_type ('checking'|'savings')
 *     want_copy_of_signup: bool,
 *     invoices_emailed: bool, agreed_to_terms: true, signature_data_url }
 *   -> { ok: true } once the Company + Contact are created in ConnectWise
 *      (per Michael, 2026-09-17 AskUserQuestion: "Just create the Company
 *      + Contact" -- no further ConnectWise automation; a human finishes
 *      service setup) and both notification emails are attempted.
 *   -> { ok: false, error } on validation failure (400) or a ConnectWise
 *      failure (502) -- a ConnectWise failure still SAVES everything the
 *      customer typed (status='failed', fail_reason set) so staff can
 *      finish the signup by hand rather than the customer's work being
 *      lost; the customer sees a plain "something went wrong, we'll
 *      finish this for you" message (built by signup.js), not a raw error.
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
 * Vaulting is fail-open like the ConnectWise create below: a vaulting
 * failure never blocks the signup or loses the customer's other data --
 * it's flagged (altpay_status/altpay_fail_reason) for staff to collect
 * payment manually, same spirit as a ConnectWise failure.
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

if ($action === 'card-checkout-init') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        ratesheet_respond(405, ['ok' => false, 'error' => 'Method not allowed.']);
    }
    if ($row['status'] === 'submitted') {
        ratesheet_respond(409, ['ok' => false, 'error' => 'This rate sheet has already been submitted.']);
    }

    $input = ratesheet_read_json_body();
    $firstName = trim((string) ($input['first_name'] ?? ''));
    $lastName = trim((string) ($input['last_name'] ?? ''));
    $email = trim((string) ($input['email'] ?? ''));
    $businessName = trim((string) ($input['business_name'] ?? ''));
    $addr1 = trim((string) ($input['address_line1'] ?? ''));
    $addr2 = trim((string) ($input['address_line2'] ?? ''));
    $city = trim((string) ($input['city'] ?? ''));
    $state = trim((string) ($input['state'] ?? ''));
    $zip = trim((string) ($input['zip'] ?? ''));
    // If signup.js already has an invoice from an earlier call on this
    // same page load (e.g. this is a checkout-auth token refresh, not the
    // first mount), it's passed back so we reuse it instead of creating a
    // second throwaway invoice per card attempt.
    $invoiceId = trim((string) ($input['invoice_id'] ?? ''));

    if ($firstName === '' || $lastName === '' || $email === '' || $addr1 === '' || $city === '' || $state === '' || $zip === '') {
        ratesheet_respond(400, ['ok' => false, 'error' => 'Please fill in your name, email, and address before adding a card.']);
    }

    // Same Commercial/Residential company-name rule as the final submit.
    $companyName = $row['account_kind'] === 'Commercial' ? $businessName : trim($firstName . ' ' . $lastName);

    try {
        $customerId = ratesheet_altpay_ensure_customer_for_row($pdo, $row, $companyName, $email, $addr1, $addr2, $city, $state, $zip);
        if ($invoiceId === '') {
            $invoiceId = ratesheet_altpay_create_placeholder_invoice($customerId);
        }
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
 * Best-effort cleanup of the throwaway invoice card-checkout-init created
 * (see altpay.php's header for why one exists at all) -- called by
 * signup.js once addPaymentMethod's onSuccess actually fires. Always
 * responds ok:true; archiving is housekeeping, never something a signup
 * should fail over. No customer/business data is echoed back.
 */
if ($action === 'card-archive-invoice') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        ratesheet_respond(405, ['ok' => false, 'error' => 'Method not allowed.']);
    }
    $input = ratesheet_read_json_body();
    $invoiceId = trim((string) ($input['invoice_id'] ?? ''));
    if ($invoiceId !== '') {
        ratesheet_altpay_archive_invoice($invoiceId);
    }
    ratesheet_respond(200, ['ok' => true]);
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
    $addr1 = trim((string) ($input['address_line1'] ?? ''));
    $addr2 = trim((string) ($input['address_line2'] ?? ''));
    $city = trim((string) ($input['city'] ?? ''));
    $state = trim((string) ($input['state'] ?? ''));
    $zip = trim((string) ($input['zip'] ?? ''));
    $businessName = trim((string) ($input['business_name'] ?? ''));
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
        ?string $altpayCustomerId = null,
        ?string $altpayPaymentMethodId = null,
        ?string $altpaySummary = null,
        ?string $altpayStatus = null,
        ?string $altpayFailReason = null
    ) use ($pdo, $row, $firstName, $lastName, $businessName, $email, $addr1, $addr2, $city, $state, $zip, $paymentMethod, $wantCopy, $invoicesEmailed, $agreed, $signatureDataUrl, $ipAddress): void {
        $stmt = $pdo->prepare(
            'UPDATE rate_sheet_requests SET
                status = :status, fail_reason = :fail_reason,
                first_name = :first_name, last_name = :last_name, business_name = :business_name,
                customer_email = :email, address_line1 = :addr1, address_line2 = :addr2,
                city = :city, state = :state, zip = :zip,
                payment_method = :payment_method, want_copy_of_signup = :want_copy,
                invoices_emailed = :invoices_emailed, agreed_to_terms = :agreed,
                signature_data = :signature, signed_at = :signed_at, ip_address = :ip_address,
                cw_company_id = :cw_company_id, cw_contact_id = :cw_contact_id,
                altpay_customer_id = :altpay_customer_id, altpay_payment_method_id = :altpay_payment_method_id,
                altpay_payment_method_summary = :altpay_summary, altpay_status = :altpay_status,
                altpay_fail_reason = :altpay_fail_reason,
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
            ':altpay_customer_id' => $altpayCustomerId,
            ':altpay_payment_method_id' => $altpayPaymentMethodId,
            ':altpay_summary' => $altpaySummary,
            ':altpay_status' => $altpayStatus,
            ':altpay_fail_reason' => $altpayFailReason,
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

    try {
        $company = ratesheet_cw_create_company($companyName, $addr1, $addr2, $city, $state, $zip, $territoryId);
        $companyId = (int) $company['id'];
        $contact = ratesheet_cw_create_contact($companyId, $firstName, $lastName, $email);
        $contactId = (int) $contact['id'];
    } catch (Throwable $e) {
        error_log('[ratesheet/public] ConnectWise create failed for request ' . $row['id'] . ': ' . $e->getMessage());
        $saveSubmission('failed', $e->getMessage(), null, null);
        ratesheet_respond(502, ['ok' => false, 'error' => 'We could not finish creating your account automatically, but your information was saved -- a CodeBlue Technology team member will finish setting up your account shortly.']);
    }

    // Record/vault the payment method with Alternative Payments --
    // fail-open, same philosophy as the ConnectWise Team-row/Territory
    // lookups above: a vaulting problem never undoes the signup or the
    // ConnectWise account that already exists, it's just flagged so staff
    // know to collect payment manually.
    $altpayCustomerId = null;
    $altpayPaymentMethodId = null;
    $altpaySummary = null;
    $altpayStatus = 'failed';
    $altpayFailReason = null;

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
        $altpayStatus = 'vaulted';
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
            $altpayStatus = 'vaulted';
        } catch (Throwable $e) {
            // Deliberately NOT logging $e->getMessage() here -- Alternative
            // Payments' error response could conceivably echo back the
            // submitted routing/account number in a validation message, and
            // that must never land in a server log.
            error_log('[ratesheet/public] Alternative Payments bank vaulting failed for request ' . $row['id'] . ' (' . get_class($e) . ') -- see Alternative Payments dashboard for details.');
            $altpayFailReason = 'Bank account vaulting failed -- see Alternative Payments dashboard for this customer.';
        }
    }

    $saveSubmission('submitted', null, $companyId, $contactId, $altpayCustomerId, $altpayPaymentMethodId, $altpaySummary, $altpayStatus, $altpayFailReason);

    // Both emails are best-effort: a failure here never undoes the
    // ConnectWise create or fails the customer's submission -- they've
    // already signed and their account already exists. Status is logged
    // to the row so staff can see + manually follow up if needed.
    $internalStatus = 'failed';
    $copyStatus = null;
    $configPath = __DIR__ . '/../../mail/mail-config.php';
    if (is_file($configPath)) {
        /** @var array $config */
        $config = require $configPath;
        require_once __DIR__ . '/../../mail/graph-mailer.php';

        $submission = [
            'first_name' => $firstName, 'last_name' => $lastName, 'business_name' => $businessName,
            'email' => $email, 'addr1' => $addr1, 'addr2' => $addr2, 'city' => $city, 'state' => $state, 'zip' => $zip,
            'location' => $row['location'], 'account_kind' => $row['account_kind'], 'hourly_rate' => (float) $row['hourly_rate'],
            'payment_method' => $paymentMethod, 'want_copy' => $wantCopy, 'invoices_emailed' => $invoicesEmailed,
            'rep_name' => $row['rep_name'], 'rep_email' => $row['rep_email'],
            'cw_company_id' => $companyId, 'cw_contact_id' => $contactId,
            'altpay_summary' => $altpaySummary, 'altpay_status' => $altpayStatus,
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
