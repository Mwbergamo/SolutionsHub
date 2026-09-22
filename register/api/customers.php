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
 * company, (2) create contact under it, (3) make that contact the
 * company's Primary Contact, (4) set Company Finance fields: Account ID
 * mirrors the company name with no special characters (concatenated
 * rather than truncated mid-word where it doesn't fit -- this is what
 * transfers to QBO after invoice close), Billing Terms = "Card on file"
 * for every register sale, Bill To contact = the contact just created,
 * Invoice Delivery Method = Email. All four steps are implemented and
 * confirmed end-to-end against real ConnectWise (register_cw_create_
 * company(), register_cw_create_contact(), register_cw_finalize_company_
 * invoicing() below).
 *
 * register_cw_sanitize_account_id() implements the Account ID rule --
 * pure string logic, no ConnectWise call. Applied to both accountNumber
 * and identifier (Company ID), since narrowing the character set can only
 * make a create MORE likely to succeed, never less.
 *
 * register_cw_finalize_company_invoicing() (step 3+4) required its own
 * research trail, since Company UPDATE had never been attempted anywhere
 * in this codebase (unlike Activity creation, which took 6 rounds -- see
 * relationships-connectwise-sync.md):
 *   - PATCH /company/companies/{id} requires a JSON-Patch (RFC 6902)
 *     array body -- confirmed, since a plain merge-object produces
 *     ConnectWise's own clear 400 "expected array format" error. BUT even
 *     with that correct envelope, every PATCH attempt -- regardless of
 *     target field (name, defaultContact, billingContact, billingTerms,
 *     invoiceDeliveryMethod, invoiceToEmailAddress) or payload -- 500s
 *     identically with "String was not recognized as a valid DateTime."
 *     A null-valued Date-type custom field triggering a full-record
 *     revalidation bug was tested and disproved (the test company had
 *     none, error persisted). Root cause unknown -- looks like a
 *     ConnectWise-side quirk/bug in this instance's PATCH pipeline for
 *     the Company entity. PATCH is NOT used for Company updates here.
 *   - PUT (full-object replace) works instead, with one catch: sending
 *     the fetched record straight back fails with a SPECIFIC, actionable
 *     error -- "typeIds can only be used when creating a new company."
 *     (ConnectWise's own internal name for the "types" field on a
 *     Company). register_cw_finalize_company_invoicing() strips whatever
 *     field ConnectWise names in that exact error (not hardcoded to
 *     "types", in case a differently-shaped record hits a different
 *     create-only field) and retries, up to 5 attempts.
 *   - Confirmed end-to-end (2026-09-14): one PUT call, after stripping
 *     "types", successfully sets defaultContact (Primary Contact),
 *     billingContact (Bill To), billingTerms (id 11, "Card on File",
 *     confirmed via /finance/billingTerms), and invoiceDeliveryMethod
 *     (id 2, "E-Mail") all together.
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
 *      today + 365 days (per Michael, 2026-09-22 -- see
 *      register_cw_create_company()'s docblock), and two Company Team
 *      rows (Sales Rep + Account Manager, both Michael Bergamo). state
 *      defaults to "VA", country to United States, per Michael's stated
 *      defaults.
 *
 * POST /register/api/customers.php?action=create-contact
 *   body: { company_id, first_name, last_name, phone?, email? }
 *   -> creates a Contact under that Company with Title "Purchaser" and
 *      Type "End User" (Michael's rule for every register-created
 *      contact), plus phone/email communication items if given.
 *
 * POST /register/api/customers.php?action=finalize-company-invoicing
 *   body: { company_id, contact_id }
 *   -> call once, right after a BRAND-NEW company+contact pair are both
 *      created. Makes contact_id the company's Primary Contact and Bill
 *      To contact, and sets Billing Terms to "Card on file" and Invoice
 *      Delivery Method to Email. Do NOT call this for an existing/
 *      recalled company -- it overwrites whatever billing setup staff
 *      may already have on that account.
 *
 * ---- Sales Tax (added 2026-09-16, per Michael -- see tax.php/tax-core.php) ----
 * create-company (above) now also accepts an optional `tax_exempt: true`
 * flag -- resolved server-side to the real ConnectWise Tax Code id (VA-
 * STATE, ConnectWise's own flagged default, unless tax_exempt was given, in
 * which case Exempt), never a client-supplied id, and set directly in the
 * Company create payload alongside territory/site.
 *
 * GET  /register/api/customers.php?action=company-tax&company_id=...
 *   -> { ok: true, tax_code: { id, identifier, name, rate, is_default } | null }
 *   Live-reads a company's CURRENT ConnectWise Tax Code (never cached/
 *   guessed -- a customer's tax status can change in ConnectWise at any
 *   time, independent of this app), joined against the locally-synced
 *   tax_codes table for the rate. Call this whenever a Company is resolved
 *   (search selection, a Contact's parent company, or right after
 *   create-company) so checkout can show/compute real tax before totaling.
 *
 * POST /register/api/customers.php?action=set-tax-exempt
 *   body: { company_id }
 *   -> { ok: true, tax_code: {...} }
 *   Updates that company's REAL ConnectWise Tax Code to Exempt, permanently
 *   (Michael's explicit choice -- not a one-sale-only override). One-
 *   directional: un-exempting a customer is a ConnectWise-side correction,
 *   same as switching them to Out of State or a different VA locality.
 */

declare(strict_types=1);

require_once __DIR__ . '/_util.php';
require_once __DIR__ . '/connectwise.php';
require_once __DIR__ . '/tax-core.php';

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
 * Date (custom field id 34) = today + 365 days -- UPDATED 2026-09-22,
 * per Michael: "I need them created with a Term Renewal Date of 1 year
 * (365 days) after the date they sign up," swept across every
 * ConnectWise company-create call in this codebase (see
 * ratesheet/api/submit-core.php's ratesheet_cw_create_company() for the
 * identical fix there); previously this was set to today, same as Date
 * Acquired. Adds two Company Team rows, both
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
    ?string $identifier = null,
    ?int $taxCodeId = null
): array {
    $today = gmdate('Y-m-d\T00:00:00\Z');
    // Terms Renewal Date = signup date + 365 days (1 year), per Michael
    // (2026-09-22): "I need them created with a Term Renewal Date of 1
    // year (365 days) after the date they sign up... adjust this in all
    // apps and sub-apps, including rate sheets." Previously set to
    // $today (same as Date Acquired) -- swept across every ConnectWise
    // company-create call in this codebase, see
    // ratesheet/api/submit-core.php's ratesheet_cw_create_company() for
    // the identical fix. Date Acquired itself is unchanged -- still
    // today, the real signup date.
    $termsRenewalDate = gmdate('Y-m-d\T00:00:00\Z', strtotime('+365 days'));

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
            ['id' => 34, 'value' => $termsRenewalDate], // "Terms Renewal Date" -- signup date + 365 days, per Michael (2026-09-22)
        ],
    ];
    // Tax Code (added 2026-09-16, per Michael: new register customers get a
    // real ConnectWise Tax Code at creation, VA-STATE by default or Exempt
    // if chosen at signup) -- set directly in the create payload, same as
    // territory/site above, rather than a separate follow-up write. $taxCodeId
    // is resolved by the caller (register_default_tax_code()/
    // register_exempt_tax_code() in tax-core.php) -- null (tax codes not yet
    // synced) simply omits the field rather than guessing/hardcoding an id,
    // same fail-open-without-blocking-the-create philosophy as the Company
    // Team rows below.
    if ($taxCodeId !== null) {
        $body['taxCode'] = ['id' => $taxCodeId];
    }
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

/**
 * The final step of company creation per Michael's multi-step invoicing
 * rule: makes $contactId the company's Primary Contact AND Bill To
 * contact, and sets Company Finance's Billing Terms to "Card on file"
 * (confirmed id 11 via /finance/billingTerms) and Invoice Delivery Method
 * to Email (confirmed id 2). Account ID/accountNumber is already handled
 * at company-create time by register_cw_sanitize_account_id().
 *
 * Confirmed live (probe-patch-format/2/3/4/5, 2026-09-14): ConnectWise's
 * PATCH (JSON-Patch array) endpoint for Company always 500s with a
 * generic "String was not recognized as a valid DateTime." error on this
 * instance, regardless of target field or payload -- a server-side
 * quirk/bug, root cause unknown, that made every field (name,
 * defaultContact, billingContact, billingTerms, invoiceDeliveryMethod,
 * invoiceToEmailAddress) fail identically even with the confirmed-correct
 * JSON-Patch envelope. PUT (full-object replace) works instead, with one
 * catch: the fetched record's "types" field trips "typeIds can only be
 * used when creating a new company." (ConnectWise's own internal name for
 * that field), so it must be stripped before sending the record back.
 * This auto-strips whatever field ConnectWise names in that specific
 * error (not just "types"), in case a differently-shaped company record
 * hits a different create-only field -- confirmed working end-to-end for
 * both defaultContact/billingContact and billingTerms/
 * invoiceDeliveryMethod together (probe-patch-format5).
 *
 * Throws RegisterConnectWiseError if it can't resolve an error to a real
 * field to strip, or if it doesn't succeed within $maxAttempts.
 */
/**
 * Shared PUT-with-auto-strip mechanism (extracted 2026-09-16 from what was
 * originally register_cw_finalize_company_invoicing()'s own retry loop, now
 * also used by register_cw_set_company_tax_code() below): fetches the full
 * Company record, merges $fieldsToSet on top, and PUTs it back. ConnectWise's
 * PATCH endpoint for Company always 500s on this instance regardless of
 * target field (confirmed 2026-09-14, see this file's docblock) -- PUT
 * works, with one catch: the fetched record's "types" field trips "typeIds
 * can only be used when creating a new company." (ConnectWise's own
 * internal name for that field), so it must be stripped before resending.
 * This strips whatever field ConnectWise names in that specific error (not
 * just "types"), in case a differently-shaped company record hits a
 * different create-only field, and retries up to $maxAttempts times.
 */
function register_cw_put_company_with_retry(int $companyId, array $fieldsToSet, int $maxAttempts = 5): array
{
    $full = register_cw_request('/company/companies/' . $companyId, [], 'GET', null, 12, 4);
    // Array union (not array_merge): for any key present in both, the LEFT
    // operand's value wins -- so every field in $fieldsToSet overrides the
    // fetched record, and everything else from the fetched record passes
    // through unchanged.
    $modified = $fieldsToSet + $full;

    for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
        try {
            return register_cw_request('/company/companies/' . $companyId, [], 'PUT', $modified, 12, 4);
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            $jsonStart = strpos($msg, '{');
            $decoded = $jsonStart !== false ? json_decode(substr($msg, $jsonStart), true) : null;
            $offendingField = null;
            if (is_array($decoded) && isset($decoded['errors']) && is_array($decoded['errors'])) {
                foreach ($decoded['errors'] as $err) {
                    $field = $err['field'] ?? null;
                    $errMsg = $err['message'] ?? '';
                    if (is_string($field) && $field !== '' && stripos($errMsg, 'can only be used when creating') !== false) {
                        $offendingField = $field;
                        break;
                    }
                }
            }

            // ConnectWise names its OWN internal field name (e.g.
            // "typeIds"), which doesn't always match our JSON key (e.g.
            // "types", an array of {id,name}) -- try the literal name,
            // then the "XIds" -> "Xs" plural-array rewrite confirmed live.
            $realKey = null;
            if ($offendingField !== null) {
                $candidates = [$offendingField];
                if (substr($offendingField, -3) === 'Ids') {
                    $base = substr($offendingField, 0, -3);
                    $candidates[] = $base . 's';
                    $candidates[] = $base;
                }
                foreach ($candidates as $candidate) {
                    if (array_key_exists($candidate, $modified)) {
                        $realKey = $candidate;
                        break;
                    }
                }
            }

            if ($realKey === null) {
                throw new RegisterConnectWiseError('Could not update company ' . $companyId . ': ' . $msg);
            }
            unset($modified[$realKey]);
        }
    }

    throw new RegisterConnectWiseError('Could not update company ' . $companyId . ' after ' . $maxAttempts . ' attempts.');
}

function register_cw_finalize_company_invoicing(int $companyId, int $contactId): array
{
    return register_cw_put_company_with_retry($companyId, [
        'defaultContact' => ['id' => $contactId],
        'billingContact' => ['id' => $contactId],
        'billingTerms' => ['id' => 11], // "Card on File", confirmed via /finance/billingTerms
        'invoiceDeliveryMethod' => ['id' => 2], // "E-Mail", confirmed
    ]);
}

/**
 * Sets an EXISTING company's ConnectWise Tax Code -- added 2026-09-16 for
 * the "Mark Tax Exempt" register action (Michael, AskUserQuestion: marking
 * a customer exempt at the register should update ConnectWise permanently,
 * not just override one sale). Reuses the same proven PUT-with-auto-strip
 * mechanism as invoicing setup. Unlike register_cw_finalize_company_
 * invoicing() (brand-new companies only), this is explicitly for an
 * EXISTING/recalled company -- it only ever touches the taxCode field,
 * never billing terms/contacts, so it's safe to call on any company.
 */
function register_cw_set_company_tax_code(int $companyId, int $taxCodeId): array
{
    return register_cw_put_company_with_retry($companyId, [
        'taxCode' => ['id' => $taxCodeId],
    ]);
}

// register_cw_company_tax_code_id() moved to tax-core.php 2026-09-16 so
// checkout.php can also call it (server-side tax computation needs the
// same live lookup this file's action=company-tax uses).

if ($action === 'create-company') {
    $input = register_read_json_body();
    $name = trim((string) ($input['name'] ?? ''));
    $phone = trim((string) ($input['phone'] ?? ''));
    if ($name === '') {
        register_respond(400, ['ok' => false, 'error' => 'name is required.']);
    }

    // Tax Code default (2026-09-16, per Michael): a brand-new register
    // customer gets VA-STATE unless tax_exempt was explicitly chosen at
    // signup. Resolved from the local tax_codes table (never guessed/
    // hardcoded) -- if tax codes haven't been synced yet, $taxCodeId stays
    // null and register_cw_create_company() simply omits the field rather
    // than blocking the whole company create over a missing tax sync.
    $taxExempt = !empty($input['tax_exempt']);
    $taxCodeRow = $taxExempt ? register_exempt_tax_code($pdo) : register_default_tax_code($pdo);
    $taxCodeId = $taxCodeRow['id'] ?? null;

    try {
        $company = register_cw_create_company(
            $name,
            $phone,
            trim((string) ($input['address_line1'] ?? '')),
            trim((string) ($input['address_line2'] ?? '')),
            trim((string) ($input['city'] ?? '')),
            trim((string) ($input['state'] ?? '')) !== '' ? trim((string) $input['state']) : 'VA',
            trim((string) ($input['zip'] ?? '')),
            null,
            $taxCodeId
        );
        $response = ['ok' => true, 'company' => $company];
        if ($taxCodeRow !== null) {
            $response['tax_code'] = $taxCodeRow;
        } else {
            // Tax codes have never been synced -- flag it rather than
            // silently creating a company with no Tax Code set at all.
            $response['tax_code_warning'] = 'Tax codes have not been synced from ConnectWise yet -- this company was created with no Tax Code set. Run the tax code sync, then set it manually in ConnectWise.';
        }
        register_respond(200, $response);
    } catch (Throwable $e) {
        register_respond(502, ['ok' => false, 'error' => 'ConnectWise company creation failed: ' . $e->getMessage()]);
    }
}

/**
 * Live-reads a company's currently-assigned tax code, joined against the
 * locally-synced tax_codes table for the identifier/rate (added 2026-09-16).
 * Called whenever a Company is resolved at checkout or Existing Customer
 * Look Up -- so the customer panel can show their real current tax status
 * and rate before the sale is even totaled.
 */
if ($action === 'company-tax') {
    $companyId = isset($_GET['company_id']) ? (int) $_GET['company_id'] : 0;
    if ($companyId <= 0) {
        register_respond(400, ['ok' => false, 'error' => 'company_id is required.']);
    }

    try {
        $live = register_cw_company_tax_code_id($companyId);
        if ($live === null) {
            register_respond(200, ['ok' => true, 'tax_code' => null]);
        }
        $local = register_tax_code_by_id($pdo, $live['id']);
        if ($local !== null) {
            register_respond(200, ['ok' => true, 'tax_code' => $local]);
        }
        // ConnectWise has this company on a real tax code, but it's not in
        // our local sync (a code added/changed in ConnectWise since the
        // last "Sync Tax Codes", or a cancelled one) -- surface the raw
        // name rather than silently reporting no tax code at all, but with
        // no rate to compute from (checkout.php falls back to the default
        // code's rate in this case -- see its own comment).
        register_respond(200, ['ok' => true, 'tax_code' => [
            'id' => $live['id'], 'identifier' => null, 'name' => $live['name'], 'rate' => null, 'is_default' => false,
        ]]);
    } catch (Throwable $e) {
        register_respond(502, ['ok' => false, 'error' => 'Could not read this company\'s tax code from ConnectWise: ' . $e->getMessage()]);
    }
}

/**
 * "Mark Tax Exempt" (added 2026-09-16, per Michael/AskUserQuestion: this
 * updates the customer's REAL ConnectWise Tax Code permanently, not just a
 * one-sale override). One-directional by design -- un-exempting a customer
 * is a ConnectWise-side correction like any other tax code change (see
 * this feature's own scope decision), not a toggle this screen offers.
 */
if ($action === 'set-tax-exempt') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        register_respond(405, ['ok' => false, 'error' => 'Method not allowed.']);
    }
    $input = register_read_json_body();
    $companyId = isset($input['company_id']) ? (int) $input['company_id'] : 0;
    if ($companyId <= 0) {
        register_respond(400, ['ok' => false, 'error' => 'company_id is required.']);
    }

    $exemptCode = register_exempt_tax_code($pdo);
    if ($exemptCode === null) {
        register_respond(502, ['ok' => false, 'error' => 'Tax codes have not been synced from ConnectWise yet -- run the tax code sync first.']);
    }

    try {
        register_cw_set_company_tax_code($companyId, $exemptCode['id']);
        register_respond(200, ['ok' => true, 'tax_code' => $exemptCode]);
    } catch (Throwable $e) {
        register_respond(502, ['ok' => false, 'error' => 'Could not mark this customer Tax Exempt in ConnectWise: ' . $e->getMessage()]);
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

if ($action === 'finalize-company-invoicing') {
    // Call this once, right after a brand-new Company + Contact are both
    // created (action=create-company then action=create-contact) --
    // completes Michael's required multi-step invoicing setup: makes the
    // new contact the Primary Contact and Bill To contact, and sets
    // Billing Terms/Invoice Delivery Method. Do NOT call this for an
    // existing/recalled Company -- it will overwrite whatever Billing
    // Terms, Bill To, etc. staff may have already set for that account.
    $input = register_read_json_body();
    $companyId = isset($input['company_id']) ? (int) $input['company_id'] : 0;
    $contactId = isset($input['contact_id']) ? (int) $input['contact_id'] : 0;
    if ($companyId <= 0 || $contactId <= 0) {
        register_respond(400, ['ok' => false, 'error' => 'company_id and contact_id are required.']);
    }

    try {
        $company = register_cw_finalize_company_invoicing($companyId, $contactId);
        register_respond(200, ['ok' => true, 'company' => $company]);
    } catch (Throwable $e) {
        register_respond(502, ['ok' => false, 'error' => 'ConnectWise company invoicing setup failed: ' . $e->getMessage()]);
    }
}

register_respond(400, ['ok' => false, 'error' => 'Unknown action.']);
