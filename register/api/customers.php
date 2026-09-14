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
 * GET  /register/api/customers.php?action=search-companies&q=...
 * GET  /register/api/customers.php?action=search-contacts&company_id=...&q=...
 *   (at least one of company_id/q required; company_id scopes to one
 *   company's contacts, in which case q further filters those results
 *   client-side rather than via an unconfirmed compound condition)
 *
 * POST /register/api/customers.php?action=create-company
 *   body: { name, phone, address_line1?, address_line2?, city?, state?, zip? }
 *   -> creates a Company with every business rule Michael specified: the
 *      "Main" site, "House accounts" territory, Account ID (accountNumber,
 *      truncated to 41 chars) = name, Date Acquired = today, Terms Renewal
 *      Date custom field = today, and two Company Team rows (Sales Rep +
 *      Account Manager, both Michael Bergamo). state defaults to "VA",
 *      country to United States, per Michael's stated defaults.
 *
 * POST /register/api/customers.php?action=create-contact
 *   body: { company_id, first_name, last_name, phone? }
 *   -> creates a Contact under that Company with Title "Purchaser" and
 *      Type "End User" (Michael's rule for every register-created
 *      contact), plus a phone communication item if given.
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
        'identifier' => mb_substr($identifier ?? $name, 0, 41),
        'name' => $name,
        'phoneNumber' => $phone,
        'country' => ['id' => 1], // United States, confirmed
        'status' => ['id' => 1], // Active, confirmed
        'site' => ['name' => 'Main'], // confirmed required
        'territory' => ['id' => 45], // "House accounts", confirmed
        'accountNumber' => mb_substr($name, 0, 41), // confirmed 41-char max
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
 * given. Throws RegisterConnectWiseError on failure.
 */
function register_cw_create_contact(int $companyId, string $firstName, string $lastName, string $phone = ''): array
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
        $contact = register_cw_create_contact($companyId, $firstName, $lastName, trim((string) ($input['phone'] ?? '')));
        register_respond(200, ['ok' => true, 'contact' => $contact]);
    } catch (Throwable $e) {
        register_respond(502, ['ok' => false, 'error' => 'ConnectWise contact creation failed: ' . $e->getMessage()]);
    }
}

register_respond(400, ['ok' => false, 'error' => 'Unknown action.']);
