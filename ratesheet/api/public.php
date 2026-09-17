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
 * POST /ratesheet/api/public.php?action=submit&t=<token>
 *   { first_name, last_name, email, address_line1, address_line2?, city,
 *     state, zip, business_name? (required iff account_kind=Commercial),
 *     payment_method: 'card'|'ach', want_copy_of_signup: bool,
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
 * PAYMENT DATA -- READ THIS BEFORE CHANGING ANYTHUING BELOW: Michael's
 * original spec asked for raw credit-card number/expiry/CVV and bank
 * account/routing numbers to be collected here and emailed in cleartext
 * to hello@codebluetechnology.com "for now." That was declined during
 * planning (2026-09-17): a full card number in an unencrypted email and
 * a CVV persisted/transmitted at all are both flat PCI-DSS violations and
 * a real breach/liability risk to CodeBlue and its customers -- this is
 * a hard line, not a style preference, and holds regardless of business
 * justification. Per Michael's own follow-up answer, this app instead
 * only collects a payment METHOD choice (card vs. ACH) and reserves a
 * clearly-marked spot for the real Alternative Payments.io integration
 * later ("hold space in the app... I will focus on that when the core
 * app has been started") -- see $paymentMethod below and
 * ratesheet_internal_notice_html()'s "Payment" section. Do not add card
 * number, expiry, CVV, bank account, or routing number fields to this
 * endpoint or to signup.js without revisiting that decision explicitly.
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

    $errors = [];
    if ($firstName === '') $errors[] = 'First name is required.';
    if ($lastName === '') $errors[] = 'Last name is required.';
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid email address is required.';
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

    $saveSubmission = function (string $status, ?string $failReason, ?int $cwCompanyId, ?int $cwContactId) use ($pdo, $row, $firstName, $lastName, $businessName, $email, $addr1, $addr2, $city, $state, $zip, $paymentMethod, $wantCopy, $invoicesEmailed, $agreed, $signatureDataUrl): void {
        $stmt = $pdo->prepare(
            'UPDATE rate_sheet_requests SET
                status = :status, fail_reason = :fail_reason,
                first_name = :first_name, last_name = :last_name, business_name = :business_name,
                customer_email = :email, address_line1 = :addr1, address_line2 = :addr2,
                city = :city, state = :state, zip = :zip,
                payment_method = :payment_method, want_copy_of_signup = :want_copy,
                invoices_emailed = :invoices_emailed, agreed_to_terms = :agreed,
                signature_data = :signature, signed_at = :signed_at,
                cw_company_id = :cw_company_id, cw_contact_id = :cw_contact_id,
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
            ':cw_company_id' => $cwCompanyId,
            ':cw_contact_id' => $cwContactId,
            ':submitted_at' => $now,
            ':id' => $row['id'],
        ]);
    };

    try {
        $company = ratesheet_cw_create_company($companyName, $addr1, $addr2, $city, $state, $zip);
        $companyId = (int) $company['id'];
        $contact = ratesheet_cw_create_contact($companyId, $firstName, $lastName, $email);
        $contactId = (int) $contact['id'];
    } catch (Throwable $e) {
        error_log('[ratesheet/public] ConnectWise create failed for request ' . $row['id'] . ': ' . $e->getMessage());
        $saveSubmission('failed', $e->getMessage(), null, null);
        ratesheet_respond(502, ['ok' => false, 'error' => 'We could not finish creating your account automatically, but your information was saved -- a CodeBlue Technology team member will finish setting up your account shortly.']);
    }

    $saveSubmission('submitted', null, $companyId, $contactId);

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

function ratesheet_cw_create_company(string $name, string $addressLine1, string $addressLine2, string $city, string $state, string $zip): array
{
    $today = gmdate('Y-m-d\T00:00:00\Z');

    $body = [
        'identifier' => ratesheet_cw_sanitize_account_id($name),
        'name' => $name,
        'country' => ['id' => 1], // United States, confirmed (register/api/customers.php)
        'status' => ['id' => 1], // Active, confirmed
        'site' => ['name' => 'Main'], // confirmed required
        'territory' => ['id' => 45], // "House accounts", confirmed
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
 * the text data to hello@codebluetechnology.com... so we can add their
 * payment info manually for now." Deliberately contains ONLY the payment
 * METHOD the customer chose, never a card/account number -- see this
 * file's header. Staff use this to manually collect real payment details
 * from the customer and enter them wherever CBT currently manages billing,
 * until Alternative Payments.io is wired in.
 */
function ratesheet_internal_notice_html(array $s): string
{
    $e = static fn (?string $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $row = static fn (string $label, string $value): string =>
        '<tr><td style="padding:4px 12px 4px 0;color:#5A6472;font-size:13px;font-family:Arial,Helvetica,sans-serif;vertical-align:top;white-space:nowrap;"><strong style="color:#33394A;">' . $label . ':</strong></td>' .
        '<td style="padding:4px 0;color:#33394A;font-size:13px;font-family:Arial,Helvetica,sans-serif;">' . $value . '</td></tr>';

    $fullAddress = trim($s['addr1'] . ($s['addr2'] !== '' ? ', ' . $s['addr2'] : '') . ', ' . $s['city'] . ', ' . $s['state'] . ' ' . $s['zip']);
    $paymentLabel = $s['payment_method'] === 'ach' ? 'ACH (bank transfer) — 3% discount applies' : 'Credit Card';

    $rows = $row('Name', $e($s['first_name'] . ' ' . $s['last_name']))
        . ($s['business_name'] !== '' ? $row('Business Name', $e($s['business_name'])) : '')
        . $row('Email', $e($s['email']))
        . $row('Address', $e($fullAddress))
        . $row('Location', $e($s['location']) . ' ($' . number_format($s['hourly_rate'], 2) . '/hr)')
        . $row('Account Type', $e($s['account_kind']))
        . $row('Payment Method Chosen', $e($paymentLabel))
        . $row('Wants Emailed Invoices', $s['invoices_emailed'] ? 'Yes' : 'No')
        . $row('Sent By', $e($s['rep_name']) . ' (' . $e($s['rep_email']) . ')')
        . $row('ConnectWise Company / Contact', '#' . (int) $s['cw_company_id'] . ' / #' . (int) $s['cw_contact_id']);

    return '<!doctype html><html><head><meta charset="utf-8"></head><body style="margin:0;padding:0;background:#F4F5F7;font-family:Arial,Helvetica,sans-serif;">' .
        '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F4F5F7;padding:24px 0;"><tr><td align="center">' .
        '<table role="presentation" width="640" cellpadding="0" cellspacing="0" style="width:640px;max-width:640px;background:#FFFFFF;border-radius:8px;border:1px solid #E2E5EA;">' .
        '<tr><td style="padding:20px 24px;border-bottom:3px solid #182857;"><div style="font-size:18px;font-weight:800;color:#182857;">New Rate Sheet Sign Up</div></td></tr>' .
        '<tr><td style="padding:16px 24px;"><table role="presentation" cellpadding="0" cellspacing="0">' . $rows . '</table></td></tr>' .
        '<tr><td style="padding:0 24px 20px 24px;"><div style="font-size:12px;color:#8A93A3;font-style:italic;">No card, bank account, or routing numbers were collected on this form (payment details are handled outside this system for now) -- follow up with the customer directly to collect and record their actual payment method.</div></td></tr>' .
        '</table></td></tr></table></body></html>';
}

/**
 * The customer's OWN copy of what they submitted + the terms they agreed
 * to, sent only when want_copy_of_signup=1 -- per Michael's "Feature: I
 * need a flag presented to the customer to select whether they want a
 * copy of their sign up form and if yes, send them a copy of all data
 * filled along with our terms and conditions."
 *
 * RATESHEET_LEGAL_SIGNATURE_TEXT below is Michael's verbatim legal text
 * (chat, 2026-09-17), kept identical to signup.js's matching constant --
 * update both together if this ever changes.
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
        '<tr><td style="padding:8px 24px 24px 24px;font-size:11.5px;color:#5A6472;line-height:1.6;white-space:pre-wrap;">' . RATESHEET_LEGAL_SIGNATURE_TEXT . '</td></tr>' .
        '</table></td></tr></table></body></html>';
}

/**
 * Verbatim legal text Michael specified (chat, 2026-09-17) to show under
 * the digital signature block. Kept identical here and in signup.js's
 * matching RATESHEET_LEGAL_TEXT constant -- update both together if this
 * ever changes.
 */
const RATESHEET_LEGAL_SIGNATURE_TEXT = <<<'TEXT'
I agree to pay CodeBlue Technology for services performed in the amounts specified within this rate agreement.

Taxes, shipping, handling and other fees may apply. We reserve the right to cancel orders arising from pricing or other errors.

Acceptance and Incorporation by Reference This Order together with the Master Services Agreement and Service Attachments and other terms and conditions identified on Exhibit A, all of which are incorporated herein by reference (collectively, the "Agreement") is between CodeBlue Technology (sometimes referred to as "we," "us," "our," "CBT," or "Provider"), and the customer identified on the Order (sometimes referred to as "you," "your," or "Client"). This Agreement is effective as of the date the Client accepts the Order (the "Effective Date").

By signing or accepting this Order, Client acknowledges, represents, and warrants that it has read and agrees to the terms and conditions identified on Exhibit A to this Order which are incorporated as if fully set forth herein. The parties hereby agree that electronic signatures to this Order shall be relied upon and will bind them to the obligations stated herein. Each party hereby warrants and represents that it has the express authority to execute this Agreement(s). Provider may make changes to the Agreement at any time. If there are changes, Provider will revise the date at the top of the document. Provider may or may not provide Client with additional notice regarding such changes. Client should review the terms and conditions regularly. Unless otherwise noted, the amended terms and conditions will be effective immediately, and your continued use of the Services thereafter constitutes your acceptance of the changes.

If you do not agree to the amended terms and conditions, you must stop using the Services immediately. Please note, you may incur a termination fee or other third-party fees, if applicable. You may access the current version of the terms and conditions at any time by visiting https://codebluetechnology.com/legal. The parties, acting through their authorized officers, hereby execute this Agreement.
TEXT;
