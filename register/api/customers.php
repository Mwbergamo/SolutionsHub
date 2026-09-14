<?php
/**
 * register/api/customers.php
 *
 * Attaches a real ConnectWise Company and/or Contact to a register sale
 * (added 2026-09-14, replacing checkout's old free-text customer_name
 * field per Michael). Every sale must resolve to a real Company or Contact
 * -- no anonymous/walk-in name-only option (Michael's explicit choice,
 * 2026-09-14 AskUserQuestion).
 *
 * ---- Research trail (2026-09-14) ----
 * Company/Contact READS were already proven safe in this ConnectWise
 * instance by the Relationships app (see claude/relationships-connectwise-
 * sync.md). This file's own temporary diagnostics (action=probe,
 * action=probe-schema, action=probe-schema2, action=probe-create -- all
 * since removed now that their findings are confirmed and built on)
 * established the following against real data before any of it shipped:
 *
 * SEARCH (read) -- self-verified by pulling real records and re-finding
 * them via a guessed condition:
 *   - Company: 'name like "%...%"'          -- confirmed
 *   - Contact: 'lastName like "%...%"'      -- confirmed
 *   - Contact: 'company/id = <id>'          -- confirmed
 * No "and"/"or" combination of multiple fields was ever tested against
 * this instance, so none is used below -- search-contacts instead makes
 * at most two single-condition calls and merges/filters in PHP, per this
 * project's "diagnose before guessing" discipline (a wrong condition on a
 * related/array field has silently returned empty results, not an error,
 * at least twice before in this codebase -- see relationships-connectwise-
 * sync.md's board-name saga).
 *
 * Company field shapes: id, identifier, name, status{id,name},
 * phoneNumber, website, types[{id,name}], addressLine1, addressLine2,
 * city, state, zip, country{id,identifier,name}, site{id,name},
 * accountNumber (plain string, max 41 chars), dateAcquired (ISO date),
 * territory{id,name} (backed by a system/locations record), customFields
 * (array of {id,caption,type,value,...} -- id 34 is "Terms Renewal Date",
 * a real custom field, type Date). No /system/customFields endpoint
 * exists on this instance (404) -- not needed, every custom field id used
 * here came directly off a real sample record.
 *
 * Contact field shapes: id, firstName, lastName, title, inactiveFlag,
 * company{id,identifier,name}, types[{id,name}] (id 3 = "End User" --
 * note the /company/contacts/types LIST resource labels this same field
 * "description", but it's "name" when embedded on a Contact; write with
 * types:[{id:3}], no name/description needed), communicationItems[{id,
 * type{id,name},value,defaultFlag,communicationType,domain?,extension?}]
 * where communicationType is the string "Email" or "Phone".
 *
 * CREATE (write) -- Company/Contact create had never been attempted
 * against this ConnectWise instance before (unlike Activity creation,
 * which took 6 rounds of guessing -- see relationships-connectwise-
 * sync.md). Confirmed end-to-end over 3 live probe-create rounds against
 * real ConnectWise responses:
 *   - Round 1: company create needs a 'site' -- "Company Site name is
 *     required." Fixed with site => ['name' => 'Main'] (ConnectWise
 *     creates that site record itself).
 *   - Round 2: 'accountNumber' has a real max length of 41 chars --
 *     "The field accountNumber must be a string with a maximum length of
 *     41." Since Michael's rule is "Account ID = same as Customer Name",
 *     a long customer name must be truncated before writing it there.
 *   - Round 2 also confirmed, via a plain unconditioned GET of
 *     /company/teamRoles (found by name, not hardcoded): "Account
 *     Manager" = teamRole id 1, "Sales Rep" = teamRole id 3 (also seen
 *     directly on a real company's team). "House accounts" territory =
 *     system/locations id 45 (from a plain GET /system/locations list).
 *     Michael Bergamo = member id 202 (confirmed via territoryManager on
 *     a real company record).
 *   - Round 3: every field together succeeded -- company (with site,
 *     territory, accountNumber, dateAcquired, the Terms Renewal Date
 *     custom field), a Purchaser/End User contact with a phone, and both
 *     Company Team rows (Sales Rep + Account Manager, both member 202).
 *     Company Team rows are NOT Contact fields -- they live on the
 *     Company's own teams sub-resource (.../company/companies/{id}/teams),
 *     each row a teamRole + member + accountManagerFlag/techFlag/
 *     salesFlag booleans.
 *
 * register_cw_create_company()/register_cw_create_contact() below are the
 * exact payload shapes proven in that round-3 run, now parameterized for
 * real checkout input instead of hardcoded test values.
 *
 * ---- Multi-step invoicing setup (2026-09-14, per Michael) ----
 * Company creation is only step 1 of a multi-step process ConnectWise
 * needs to actually invoice and transfer to QuickBooks Online: (1) create
 * company, (2) create contact under it -- both done above and confirmed --
 * then (3) make that contact the company's Primary Contact, (4) set
 * Company Finance fields: Account ID mirrors the company name with no
 * special characters (concatenated rather than truncated mid-word where
 * it doesn't fit -- this is what transfers to QBO after invoice close),
 * Billing Terms = "Card on file" for every register sale, Bill To contact
 * = the contact just created, Invoice Delivery Method = Email.
 *
 * register_cw_sanitize_account_id() (below) implements the Account ID
 * rule now -- pure string logic, no ConnectWise call, so no diagnostic
 * needed. It's applied to both accountNumber and identifier (Company ID),
 * since narrowing the character set can only make a create MORE likely to
 * succeed, never less.
 *
 * The rest -- Primary Contact, Billing Terms lookup, Bill To, Invoice
 * Delivery Method -- are all NEW write surfaces (a company UPDATE/PATCH,
 * never attempted anywhere in this codebase, plus an unconfirmed
 * billingTerms id for "Card on file") and are deliberately NOT wired into
 * register_cw_create_company() yet. action=probe-finance (read-only: looks
 * up the real "Card on file" billingTerms id) and action=probe-finance-
 * write (creates one more clearly-labeled real test company+contact via
 * the now-proven create functions, then attempts each Company Finance
 * field as an isolated PATCH so a wrong guess on one doesn't block
 * learning the others) exist to confirm the real PATCH shape before any
 * of this is folded into the real create flow -- same discipline as
 * everything else in this file.
 *
 * GET  /register/api/customers.php?action=search-companies&q=...
 * GET  /register/api/customers.php?action=search-contacts&company_id=...&q=...
 *   (at least one of company_id/q required; company_id scopes to one
 *   company's contacts, in which case q further filters those results
 *   client-side rather than via an unconfirmed compound condition)
 *
 * POST /register/api/customers.php?action=create-company
 *   body: { name, phone, address_line1?, address_line2?, city?, state?, zip? }
 *   -> creates a Company with every business rule Michael specified: the
 *      "Main" site, "House accounts" territory, Account ID (accountNumber
 *      and identifier, sanitized per register_cw_sanitize_account_id()) =
 *      name, Date Acquired = today, Terms Renewal Date custom field =
 *      today, and two Company Team rows (Sales Rep + Account Manager,
 *      both Michael Bergamo). state defaults to "VA", country to United
 *      States, per Michael's stated defaults.
 *
 * POST /register/api/customers.php?action=create-contact
 *   body: { company_id, first_name, last_name, phone?, email? }
 *   -> creates a Contact under that Company with Title "Purchaser" and
 *      Type "End User" (Michael's rule for every register-created
 *      contact), plus phone/email communication items if given.
 *
 * GET  /register/api/customers.php?action=probe-finance (TEMPORARY,
 *   read-only) -- looks up the real billingTerms id for "Card on file".
 *
 * GET  /register/api/customers.php?action=probe-finance-write (TEMPORARY)
 *   -- creates one more real, clearly-labeled test Company + Contact and
 *   attempts each remaining Company Finance / Primary Contact field
 *   (Primary Contact, Bill To, Billing Terms, Invoice Delivery Method) as
 *   an isolated PATCH, to confirm ConnectWise's real update shape before
 *   any of it ships. DO NOT run against production without knowing it
 *   will write real records.
 */

declare(strict_types=1);

require_once __DIR__ . '/_util.php';
require_once __DIR__ . '/connectwise.php';

register_install_error_handlers();

$pdo = register_db();
register_require_login($pdo);

$action = $_GET['action'] ?? '';

/**
 * Search Companies by name (single confirmed condition -- see docblock).
 */
if ($action === 'search-companies') {
    $q = trim((string) ($_GET['q'] ?? ''));
    if ($q === '' || mb_strlen($q) < 2) {
        register_respond(200, ['ok' => true, 'companies' => []]);
    }

    try {
        $companies = register_cw_request('/company/companies', [
            'conditions' => 'name like "%' . register_cw_condition_escape($q) . '%"',
            'fields' => 'id,identifier,name,addressLine1,addressLine2,city,state,zip,phoneNumber,website',
            'pageSize' => '20',
        ], 'GET', null, 12, 4);
        register_respond(200, ['ok' => true, 'companies' => $companies]);
    } catch (Throwable $e) {
        register_respond(502, ['ok' => false, 'error' => 'ConnectWise company search failed: ' . $e->getMessage()]);
    }
}

/**
 * Search Contacts, scoped to a Company (confirmed 'company/id = X'
 * condition) and/or filtered by name. See docblock: no compound condition
 * is sent to ConnectWise -- when both company_id and q are given, q is
 * applied client-side against the company-scoped results instead.
 */
if ($action === 'search-contacts') {
    $companyId = isset($_GET['company_id']) && $_GET['company_id'] !== '' ? (int) $_GET['company_id'] : null;
    $q = trim((string) ($_GET['q'] ?? ''));
    $fields = 'id,firstName,lastName,title,company,communicationItems,inactiveFlag';

    if ($companyId === null && ($q === '' || mb_strlen($q) < 2)) {
        register_respond(200, ['ok' => true, 'contacts' => []]);
    }

    try {
        if ($companyId !== null) {
            // Confirmed condition: fetch every contact for this company,
            // then apply the free-text filter (if any) in PHP.
            $contacts = register_cw_request('/company/contacts', [
                'conditions' => 'company/id = ' . $companyId,
                'fields' => $fields,
                'pageSize' => '100',
            ], 'GET', null, 12, 4);

            $contacts = array_values(array_filter($contacts, static fn (array $c): bool => empty($c['inactiveFlag'])));

            if ($q !== '') {
                $needle = mb_strtolower($q);
                $contacts = array_values(array_filter($contacts, static function (array $c) use ($needle): bool {
                    $haystack = mb_strtolower(trim(($c['firstName'] ?? '') . ' ' . ($c['lastName'] ?? '')));
                    return mb_strpos($haystack, $needle) !== false;
                }));
            }
        } else {
            // No company scope: two single-field confirmed conditions
            // (firstName like, lastName like), merged and deduped by id --
            // avoids guessing at "field1 like ... or field2 like ..." syntax.
            $escaped = register_cw_condition_escape($q);
            $byFirst = register_cw_request('/company/contacts', [
                'conditions' => 'firstName like "%' . $escaped . '%"',
                'fields' => $fields,
                'pageSize' => '20',
            ], 'GET', null, 12, 4);
            $byLast = register_cw_request('/company/contacts', [
                'conditions' => 'lastName like "%' . $escaped . '%"',
                'fields' => $fields,
                'pageSize' => '20',
            ], 'GET', null, 12, 4);

            $byId = [];
            foreach (array_merge($byFirst, $byLast) as $c) {
                if (empty($c['inactiveFlag'])) {
                    $byId[$c['id']] = $c;
                }
            }
            $contacts = array_values($byId);
        }

        register_respond(200, ['ok' => true, 'contacts' => $contacts]);
    } catch (Throwable $e) {
        register_respond(502, ['ok' => false, 'error' => 'ConnectWise contact search failed: ' . $e->getMessage()]);
    }
}

/**
 * Creates a new ConnectWise Company for a register sale, per every
 * business rule Michael specified and confirmed end-to-end via
 * probe-create's 3 live test rounds (see this file's docblock). Site
 * "Main" is required; Territory = "House accounts" (system/locations id
 * 45); Account ID (accountNumber) = the company name, truncated to
 * ConnectWise's real 41-char max; Date Acquired = today; Terms Renewal
 * Date (custom field id 34) = today. Adds two Company Team rows, both
 * Michael Bergamo (member id 202): Sales Rep (teamRole id 3) and Account
 * Manager (teamRole id 1) -- best-effort, since the company itself is
 * already created successfully by that point and a team-row failure
 * shouldn't block the sale.
 *
 * $identifier lets a caller pass a pre-disambiguated ConnectWise
 * identifier (ConnectWise's ids/identifiers must be unique) -- if
 * omitted, $name is used directly, matching Michael's "Company ID = same
 * as Customer Name" rule for the common case.
 *
 * Throws RegisterConnectWiseError on failure (e.g. a duplicate identifier)
 * -- the caller/action below turns that into a real error response.
 */
/**
 * Implements Michael's Account ID rule (2026-09-14): mirror the company
 * name, strip special characters, and if it's still too long, concatenate
 * (drop spaces) rather than just chopping the name off mid-word -- this
 * value is what transfers to QuickBooks Online as the Account ID after an
 * invoice closes, so it's kept conservative (letters/digits/spaces only)
 * rather than guessing which punctuation QBO itself would tolerate. Pure
 * string logic -- no ConnectWise call, so no diagnostic was needed.
 */
function register_cw_sanitize_account_id(string $name, int $maxLength = 41): string
{
    $clean = preg_replace('/[^A-Za-z0-9 ]+/', '', $name) ?? '';
    $clean = trim(preg_replace('/\s+/', ' ', $clean) ?? '');
    if ($clean === '') {
        $clean = 'Account';
    }
    if (mb_strlen($clean) <= $maxLength) {
        return $clean;
    }
    // Too long even after stripping punctuation -- concatenate (drop the
    // spaces) to fit more of the real name in before falling back to a
    // hard truncate, per Michael's "concatenate where needed" rule.
    $concatenated = str_replace(' ', '', $clean);
    return mb_substr($concatenated, 0, $maxLength);
}

function register_cw_create_company(
    string $name,
    string $phone,
    string $addressLine1 = '',
    string $addressLine2 = '',
    string $city = '',
    string $state = 'VA',
    string $zip = '',
    ?string $identifier = null
): array {
    $today = gmdate('Y-m-d\T00:00:00\Z');

    $body = [
        'identifier' => register_cw_sanitize_account_id($identifier ?? $name),
        'name' => $name,
        'phoneNumber' => $phone,
        'country' => ['id' => 1], // United States, confirmed
        'status' => ['id' => 1], // Active, confirmed
        'site' => ['name' => 'Main'], // confirmed required
        'territory' => ['id' => 45], // "House accounts", confirmed
        'accountNumber' => register_cw_sanitize_account_id($name), // "mirror the Company Name, no special characters"
        'dateAcquired' => $today,
        'customFields' => [
            ['id' => 34, 'value' => $today], // "Terms Renewal Date", confirmed
        ],
    ];
    if ($addressLine1 !== '') {
        $body['addressLine1'] = $addressLine1;
    }
    if ($addressLine2 !== '') {
        $body['addressLine2'] = $addressLine2;
    }
    if ($city !== '') {
        $body['city'] = $city;
    }
    if ($state !== '') {
        $body['state'] = $state;
    }
    if ($zip !== '') {
        $body['zip'] = $zip;
    }

    $company = register_cw_request('/company/companies', [], 'POST', $body, 12, 4);
    $companyId = $company['id'] ?? null;
    if (!is_int($companyId)) {
        throw new RegisterConnectWiseError('ConnectWise did not return a new company id.');
    }

    foreach ([
        ['teamRole' => ['id' => 3], 'salesFlag' => true],       // Sales Rep, confirmed
        ['teamRole' => ['id' => 1], 'accountManagerFlag' => true], // Account Manager, confirmed
    ] as $teamRow) {
        try {
            register_cw_request(
                '/company/companies/' . $companyId . '/teams',
                [],
                'POST',
                $teamRow + ['member' => ['id' => 202]], // Michael Bergamo, confirmed
                12,
                4
            );
        } catch (Throwable $e) {
            error_log('register_cw_create_company: failed to add team row for company ' . $companyId . ': ' . $e->getMessage());
        }
    }

    return $company;
}

/**
 * Creates a new ConnectWise Contact under an existing Company, per
 * Michael's rule that every register-created contact is the point-of-sale
 * purchaser: Title "Purchaser", Type "End User" (id 3, confirmed). Adds a
 * phone communication item (type "Direct", id 2, confirmed) if $phone is
 * given, and an email communication item (type "Email", id 1 -- confirmed
 * read-only from real Contact records; the same POST shape already proven
 * live for phone, just a different type id/communicationType, so no
 * separate write diagnostic was needed) if $email is given. Throws
 * RegisterConnectWiseError on failure of the contact create itself; a
 * communication-item failure is logged but non-fatal, same as the Company
 * Team rows in register_cw_create_company().
 */
function register_cw_create_contact(int $companyId, string $firstName, string $lastName, string $phone = '', string $email = ''): array
{
    $contact = register_cw_request('/company/contacts', [], 'POST', [
        'firstName' => $firstName,
        'lastName' => $lastName,
        'company' => ['id' => $companyId],
        'title' => 'Purchaser',
        'types' => [['id' => 3]], // "End User", confirmed
    ], 12, 4);

    $contactId = $contact['id'] ?? null;
    if (is_int($contactId) && $phone !== '') {
        try {
            register_cw_request('/company/contacts/' . $contactId . '/communications', [], 'POST', [
                'type' => ['id' => 2], // "Direct", confirmed
                'value' => $phone,
                'communicationType' => 'Phone',
                'defaultFlag' => true,
            ], 12, 4);
        } catch (Throwable $e) {
            error_log('register_cw_create_contact: failed to add phone for contact ' . $contactId . ': ' . $e->getMessage());
        }
    }
    if (is_int($contactId) && $email !== '') {
        try {
            register_cw_request('/company/contacts/' . $contactId . '/communications', [], 'POST', [
                'type' => ['id' => 1], // "Email", confirmed
                'value' => $email,
                'communicationType' => 'Email',
                'defaultFlag' => true,
            ], 12, 4);
        } catch (Throwable $e) {
            error_log('register_cw_create_contact: failed to add email for contact ' . $contactId . ': ' . $e->getMessage());
        }
    }

    return $contact;
}

if ($action === 'create-company') {
    $input = register_read_json_body();
    $name = trim((string) ($input['name'] ?? ''));
    $phone = trim((string) ($input['phone'] ?? ''));
    if ($name === '') {
        register_respond(400, ['ok' => false, 'error' => 'name is required.']);
    }

    try {
        $company = register_cw_create_company(
            $name,
            $phone,
            trim((string) ($input['address_line1'] ?? '')),
            trim((string) ($input['address_line2'] ?? '')),
            trim((string) ($input['city'] ?? '')),
            trim((string) ($input['state'] ?? '')) !== '' ? trim((string) $input['state']) : 'VA',
            trim((string) ($input['zip'] ?? ''))
        );
        register_respond(200, ['ok' => true, 'company' => $company]);
    } catch (Throwable $e) {
        register_respond(502, ['ok' => false, 'error' => 'ConnectWise company creation failed: ' . $e->getMessage()]);
    }
}

if ($action === 'create-contact') {
    $input = register_read_json_body();
    $companyId = isset($input['company_id']) ? (int) $input['company_id'] : 0;
    $firstName = trim((string) ($input['first_name'] ?? ''));
    $lastName = trim((string) ($input['last_name'] ?? ''));
    if ($companyId <= 0 || $firstName === '' || $lastName === '') {
        register_respond(400, ['ok' => false, 'error' => 'company_id, first_name, and last_name are required.']);
    }

    try {
        $contact = register_cw_create_contact(
            $companyId,
            $firstName,
            $lastName,
            trim((string) ($input['phone'] ?? '')),
            trim((string) ($input['email'] ?? ''))
        );
        register_respond(200, ['ok' => true, 'contact' => $contact]);
    } catch (Throwable $e) {
        register_respond(502, ['ok' => false, 'error' => 'ConnectWise contact creation failed: ' . $e->getMessage()]);
    }
}

/**
 * TEMPORARY diagnostic -- read-only. Looks up the real billingTerms id for
 * "Card on file" (Michael's required Billing Terms for every register
 * sale), which no code in this file has ever needed before. Tries the
 * standard-looking /finance/billingTerms list endpoint first; if that
 * 404s, falls back to sampling a broad, unconditioned set of real
 * companies and collecting whatever distinct billingTerms{id,name} values
 * are actually in use, so there's still a real answer even if the guessed
 * endpoint path is wrong.
 */
if ($action === 'probe-finance') {
    $result = ['ok' => true];
    $cardOnFileId = null;

    try {
        $terms = register_cw_request('/finance/billingTerms', ['pageSize' => '100'], 'GET', null, 12, 4);
        $result['billing_terms'] = $terms;
        foreach ($terms as $term) {
            if (isset($term['name']) && stripos((string) $term['name'], 'Card on file') !== false) {
                $cardOnFileId = $term['id'];
                break;
            }
        }
    } catch (Throwable $e) {
        $result['billing_terms_error'] = $e->getMessage();

        try {
            $companies = register_cw_request('/company/companies', [
                'fields' => 'id,name,billingTerms',
                'pageSize' => '100',
            ], 'GET', null, 12, 4);
            $seen = [];
            foreach ($companies as $c) {
                if (!empty($c['billingTerms']['id'])) {
                    $seen[$c['billingTerms']['id']] = $c['billingTerms']['name'] ?? null;
                }
            }
            $result['billing_terms_seen_on_companies'] = $seen;
            foreach ($seen as $id => $name) {
                if ($name !== null && stripos((string) $name, 'Card on file') !== false) {
                    $cardOnFileId = $id;
                    break;
                }
            }
        } catch (Throwable $e2) {
            $result['billing_terms_fallback_error'] = $e2->getMessage();
        }
    }

    $result['card_on_file_id_found'] = $cardOnFileId;
    register_respond(200, $result);
}

/**
 * TEMPORARY diagnostic -- creates one more clearly-labeled real test
 * Company + Contact (via the now-proven register_cw_create_company()/
 * register_cw_create_contact(), so this also exercises those for real),
 * then attempts each remaining Company Finance / Primary Contact field as
 * an ISOLATED PATCH to /company/companies/{id}, since a company UPDATE has
 * never been attempted anywhere in this codebase and ConnectWise's PATCH
 * body shape (a JSON-Patch-style array of {op,path,value}, guessed here --
 * unconfirmed) may not be right. Each field's real success/error is
 * reported separately so a wrong guess on one doesn't block learning the
 * others. Remove once every finding here is confirmed and folded into
 * register_cw_create_company()/a real "finalize invoicing setup" step.
 */
if ($action === 'probe-finance-write') {
    $stamp = date('Y-m-d H:i:s');
    $result = ['ok' => true, 'note' => 'This created real test records in ConnectWise. Search for "ZZZ REGISTER TEST" and delete them by hand when done.'];

    $testName = 'ZZZ REGISTER TEST - DELETE ME (' . $stamp . ')';
    $companyId = null;
    $contactId = null;
    try {
        $company = register_cw_create_company($testName, '8045550100', '123 Test St', '', 'Richmond', 'VA', '23219');
        $companyId = $company['id'] ?? null;
        $result['create_company'] = ['ok' => true, 'response' => $company];
    } catch (Throwable $e) {
        $result['create_company'] = ['ok' => false, 'error' => $e->getMessage()];
    }

    if ($companyId !== null) {
        try {
            $contact = register_cw_create_contact($companyId, 'ZZZ-REGISTER-TEST', 'DELETE-ME (' . $stamp . ')', '8045550100', 'register-test-' . date('YmdHis') . '@example.invalid');
            $contactId = $contact['id'] ?? null;
            $result['create_contact'] = ['ok' => true, 'response' => $contact];
        } catch (Throwable $e) {
            $result['create_contact'] = ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    if ($companyId !== null && $contactId !== null) {
        // Look up "Card on file" the same way probe-finance does, so this
        // one action can test every Company Finance field in one pass.
        $cardOnFileId = null;
        try {
            $terms = register_cw_request('/finance/billingTerms', ['pageSize' => '100'], 'GET', null, 12, 4);
            $result['billing_terms'] = $terms;
            foreach ($terms as $term) {
                if (isset($term['name']) && stripos((string) $term['name'], 'Card on file') !== false) {
                    $cardOnFileId = $term['id'];
                    break;
                }
            }
        } catch (Throwable $e) {
            $result['billing_terms_error'] = $e->getMessage();
        }

        $patches = [
            'set_primary_contact' => [['op' => 'replace', 'path' => '/defaultContact', 'value' => ['id' => $contactId]]],
            'set_bill_to_contact' => [['op' => 'replace', 'path' => '/billingContact', 'value' => ['id' => $contactId]]],
            'set_invoice_delivery_email' => [['op' => 'replace', 'path' => '/invoiceDeliveryMethod', 'value' => ['id' => 2]]], // "E-Mail", confirmed read-only
            'set_invoice_to_email_address' => [['op' => 'replace', 'path' => '/invoiceToEmailAddress', 'value' => 'register-test-' . date('YmdHis') . '@example.invalid']],
        ];
        if ($cardOnFileId !== null) {
            $patches['set_billing_terms_card_on_file'] = [['op' => 'replace', 'path' => '/billingTerms', 'value' => ['id' => $cardOnFileId]]];
        } else {
            $result['set_billing_terms_card_on_file'] = ['ok' => false, 'error' => 'No billingTerms named "Card on file" was found -- see billing_terms above for the real list.'];
        }
        foreach ($patches as $key => $patchBody) {
            try {
                $response = register_cw_request('/company/companies/' . $companyId, [], 'PATCH', $patchBody, 12, 4);
                $result[$key] = ['ok' => true, 'response' => $response];
            } catch (Throwable $e) {
                $result[$key] = ['ok' => false, 'error' => $e->getMessage(), 'tried_patch' => $patchBody];
            }
        }
    }

    register_respond(200, $result);
}

/**
 * TEMPORARY diagnostic, read-only except for the PATCH attempts on an
 * EXISTING company (no new test records created -- reuse the ones
 * probe-finance-write already made, e.g. ?company_id=7912&contact_id=16678,
 * so repeated attempts don't keep littering ConnectWise with junk data).
 *
 * probe-finance-write's every PATCH attempt failed with the SAME error --
 * "String was not recognized as a valid DateTime" -- regardless of which
 * field was targeted (defaultContact, billingContact, billingTerms,
 * invoiceDeliveryMethod, invoiceToEmailAddress). That points at the PATCH
 * envelope/body shape itself (guessed as a JSON-Patch-style array of
 * {op,path,value}), not at any one field. This isolates the question with
 * three narrower attempts:
 *   (a) the same JSON-Patch array shape, but targeting a field with
 *       nothing date-related about it at all ("name") -- if this STILL
 *       fails with the DateTime error, the envelope itself is wrong.
 *   (b) a plain merge-style object body (not an array) for that same
 *       harmless field -- {"name": "..."} instead of a JSON-Patch array.
 *   (c) a plain merge-style object body for the actual field that
 *       matters -- {"defaultContact": {"id": ...}}.
 */
if ($action === 'probe-patch-format') {
    $companyId = isset($_GET['company_id']) ? (int) $_GET['company_id'] : 0;
    $contactId = isset($_GET['contact_id']) ? (int) $_GET['contact_id'] : 0;
    if ($companyId <= 0) {
        register_respond(400, ['ok' => false, 'error' => 'company_id is required (reuse an existing ZZZ REGISTER TEST company id).']);
    }

    $result = ['ok' => true];
    $marker = ' [patch test ' . date('H:i:s') . ']';
    $before = [];

    try {
        $before = register_cw_request('/company/companies/' . $companyId, [], 'GET', null, 12, 4);
        $result['company_before'] = ['id' => $before['id'] ?? null, 'name' => $before['name'] ?? null];
    } catch (Throwable $e) {
        $result['company_before_error'] = $e->getMessage();
    }

    try {
        $r = register_cw_request(
            '/company/companies/' . $companyId,
            [],
            'PATCH',
            [['op' => 'replace', 'path' => '/name', 'value' => ($before['name'] ?? 'Test') . $marker . 'A']],
            12,
            4
        );
        $result['jsonpatch_array_on_name'] = ['ok' => true, 'response' => ['id' => $r['id'] ?? null, 'name' => $r['name'] ?? null]];
    } catch (Throwable $e) {
        $result['jsonpatch_array_on_name'] = ['ok' => false, 'error' => $e->getMessage()];
    }

    try {
        $r = register_cw_request(
            '/company/companies/' . $companyId,
            [],
            'PATCH',
            ['name' => ($before['name'] ?? 'Test') . $marker . 'B'],
            12,
            4
        );
        $result['merge_object_on_name'] = ['ok' => true, 'response' => ['id' => $r['id'] ?? null, 'name' => $r['name'] ?? null]];
    } catch (Throwable $e) {
        $result['merge_object_on_name'] = ['ok' => false, 'error' => $e->getMessage()];
    }

    if ($contactId > 0) {
        try {
            $r = register_cw_request(
                '/company/companies/' . $companyId,
                [],
                'PATCH',
                ['defaultContact' => ['id' => $contactId]],
                12,
                4
            );
            $result['merge_object_on_default_contact'] = ['ok' => true, 'response' => ['id' => $r['id'] ?? null, 'defaultContact' => $r['defaultContact'] ?? null]];
        } catch (Throwable $e) {
            $result['merge_object_on_default_contact'] = ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    register_respond(200, $result);
}

register_respond(400, ['ok' => false, 'error' => 'Unknown action.']);
