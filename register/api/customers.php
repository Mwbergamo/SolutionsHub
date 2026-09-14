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
 * sync.md). What this file needed to confirm fresh, before writing any real
 * search code, was: (a) the true field shapes on Company/Contact records in
 * THIS instance (address/phone/website on Company; title/communicationItems
 * /company-linkage on Contact), and (b) whether three guessed single-field
 * search conditions actually work. A temporary action=probe (since removed)
 * pulled a few real records unconditionally and self-verified each
 * condition against one of those same real records. All three confirmed:
 *   - Company: 'name like "%...%"'          -- confirmed (found expected company)
 *   - Contact: 'lastName like "%...%"'      -- confirmed (found expected contact)
 *   - Contact: 'company/id = <id>'          -- confirmed (found expected contact)
 * No "and"/"or" combination of multiple fields was ever tested against this
 * instance, so none is used below -- search-contacts instead makes at most
 * two single-condition calls and merges/filters in PHP, per this project's
 * "diagnose before guessing" discipline (a wrong condition on a related/
 * array field has silently returned empty results, not an error, at least
 * twice before in this codebase -- see relationships-connectwise-sync.md's
 * board-name saga).
 *
 * Company field shapes confirmed via the same probe: id, identifier, name,
 * status{id,name}, phoneNumber, website, types[{id,name}], addressLine1,
 * addressLine2, city, state, zip, country{id,identifier,name}, site{id,name}.
 * Contact field shapes confirmed: id, firstName, lastName, title, inactiveFlag,
 * company{id,identifier,name}, communicationItems[{id,type{id,name},value,
 * defaultFlag,communicationType,domain?,extension?}] where communicationType
 * is the string "Email" or "Phone".
 *
 * CREATE (write) is still unconfirmed -- see action=probe-create below.
 * Company/Contact create has never been attempted against this ConnectWise
 * instance before (unlike Activity creation, which took 6 rounds of guessing
 * to get right -- see relationships-connectwise-sync.md). Do not wire
 * create-company/create-contact into checkout until probe-create's real
 * ConnectWise responses confirm the required-field schema.
 *
 * probe-create round 1 (2026-09-14): company create without a "site" field
 * failed with a real ConnectWise validation error -- "Company Site name is
 * required." (code InvalidField, field "site"). Confirmed fix: pass
 * 'site' => ['name' => 'Main'] and ConnectWise creates that site record
 * itself.
 *
 * probe-schema (2026-09-14), reading a real company's full default field
 * set (no 'fields' restriction) plus Michael's business-field list (see
 * screenshot of ConnectWise's own New Company screen): "Account ID" =
 * standard accountNumber (plain string), "Date Acquired" = standard
 * dateAcquired (ISO date string), "Terms Renewal Date" IS a real custom
 * field (id 34, type Date -- write via customFields:[{id:34,value:...}]),
 * "Territory" = standard territory field, backed by a system/locations
 * record (id, name) -- confirmed real ids so far: 40 "Trey's Accounts", 2
 * "Richmond"; "House Accounts"'s id still needed (probe-schema2).
 * There is no /system/customFields endpoint on this instance (404) -- not
 * needed, since every custom field id used above came directly off a real
 * sample record instead.
 *
 * probe-schema2 (2026-09-14) confirmed: "House accounts" territory =
 * system/locations id 45; Contact type "End User" = id 3 (note: the
 * /company/contacts/types list resource labels this field "description",
 * but it's embedded on a Contact record as "name" -- write with
 * types:[{id:3}], no name/description needed). Account Manager/Sales Rep
 * are NOT Contact fields at all -- they live on the Company's own
 * teams_href sub-resource (.../company/companies/{id}/teams): each row has
 * company, teamRole{id,name}, member{id,identifier,name}, plus
 * accountManagerFlag/techFlag/salesFlag booleans. "Sales Rep" teamRole id 3
 * confirmed on a real record. Michael Bergamo = member id 202 (confirmed
 * via territoryManager on a real company).
 *
 * GET  /register/api/customers.php?action=search-companies&q=...
 * GET  /register/api/customers.php?action=search-contacts&company_id=...&q=...
 *   (at least one of company_id/q required; company_id scopes to one
 *   company's contacts, in which case q further filters those results
 *   client-side rather than via an unconfirmed compound condition)
 *
 * GET  /register/api/customers.php?action=probe-create (TEMPORARY --
 *   creates ONE clearly-labeled real test Company and Contact in
 *   ConnectWise to learn the true required-field schema for writes, the
 *   same way Activity creation's schema was learned: guess conservatively,
 *   read ConnectWise's own validation error back, fix, retry. Every created
 *   record's name/identifier is prefixed "ZZZ REGISTER TEST - DELETE ME" so
 *   it's unmistakable in ConnectWise and safe to delete by hand afterward.
 *   DO NOT run this against production without knowing it will write real
 *   records.)
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
 * TEMPORARY diagnostic -- read-only, no side effects. Michael specified
 * (2026-09-14, screenshot of ConnectWise's own "New Company" screen) the
 * exact fields a register-created Company + Primary Contact must set:
 * Company/Phone/Address/City/State/Zip/Country, "Terms Renewal Date",
 * "Company ID", Territory ("House Accounts"), "Account ID", "Date
 * Acquired"; Primary Contact Name/Title("Purchaser")/Phone/Type("End
 * User")/Account Manager("Michael Bergamo")/Sales Rep("Michael Bergamo").
 * Several of those UI labels ("Terms Renewal Date", "Account ID", "Date
 * Acquired", and possibly "Account Manager"/"Sales Rep") don't obviously
 * match any standard ConnectWise REST field name confirmed so far -- they
 * may be custom fields specific to this instance, or standard fields under
 * different API names, or (Account Manager/Sales Rep) may actually belong
 * to the Company "Team" sub-resource visible just below Primary Contact in
 * the screenshot rather than the Contact itself. Guessing JSON keys for
 * these before checking would repeat the board-name saga's mistake, so this
 * action instead fetches real full-default records (no 'fields' param, no
 * guessed condition -- direct by-id GETs and a couple of unconditioned
 * small lists) plus this instance's system custom-field definitions, to
 * read the real field/customField names and IDs straight from ConnectWise
 * before writing create-company/create-contact for real.
 */
if ($action === 'probe-schema') {
    $result = ['ok' => true];

    try {
        $result['company_full'] = register_cw_request('/company/companies/2', [], 'GET', null, 12, 4);
    } catch (Throwable $e) {
        $result['company_full_error'] = $e->getMessage();
    }

    try {
        $result['companies_sample'] = register_cw_request('/company/companies', ['pageSize' => '5'], 'GET', null, 12, 4);
    } catch (Throwable $e) {
        $result['companies_sample_error'] = $e->getMessage();
    }

    try {
        $result['contact_full'] = register_cw_request('/company/contacts/2', [], 'GET', null, 12, 4);
    } catch (Throwable $e) {
        $result['contact_full_error'] = $e->getMessage();
    }

    try {
        $result['contacts_sample'] = register_cw_request('/company/contacts', ['pageSize' => '5'], 'GET', null, 12, 4);
    } catch (Throwable $e) {
        $result['contacts_sample_error'] = $e->getMessage();
    }

    register_respond(200, $result);
}

/**
 * TEMPORARY diagnostic, round 2 -- read-only. probe-schema (round 1)
 * confirmed: "Account ID" = standard accountNumber (plain string), "Date
 * Acquired" = standard dateAcquired (ISO date), "Territory" = standard
 * territory field backed by a system/locations record (id 40 = "Trey's
 * Accounts", id 2 = "Richmond" seen so far -- need "House Accounts"'s id).
 * "Terms Renewal Date" IS a real custom field (id 34, type "Date"). There
 * is no system/customFields endpoint on this instance (404) -- not needed,
 * since every custom field id we care about already showed up directly on
 * the sample records above.
 *
 * Still unknown: (a) "House Accounts" territory's location id, (b) the
 * Contact "Type" ("End User") id -- contact.types[] is confirmed real
 * (seen: 10=Primary Point of Contact, 4=Evaluator) but "End User" wasn't
 * in the small sample, (c) whether "Account Manager"/"Sales Rep" are
 * Contact fields at all -- neither appeared anywhere in contact_full's
 * full default field list, but Company objects do expose teams_href
 * (.../company/companies/{id}/teams), a sub-resource for per-company
 * member role assignments, which sits directly below "Primary Contact" in
 * the screenshot Michael sent -- likely where Account Manager/Sales Rep
 * actually live, not on the Contact.
 */
if ($action === 'probe-schema2') {
    $result = ['ok' => true];

    try {
        $result['locations'] = register_cw_request('/system/locations', ['pageSize' => '100'], 'GET', null, 12, 4);
    } catch (Throwable $e) {
        $result['locations_error'] = $e->getMessage();
    }

    try {
        $result['contact_types'] = register_cw_request('/company/contacts/types', ['pageSize' => '100'], 'GET', null, 12, 4);
    } catch (Throwable $e) {
        $result['contact_types_error'] = $e->getMessage();
    }

    // Direct sub-resource fetches (no condition guessing) on two real
    // companies, to see the Company Team role-assignment shape and find
    // real "Account Manager"/"Sales Rep" role ids if they exist there.
    try {
        $result['codeblue_team'] = register_cw_request('/company/companies/2/teams', ['pageSize' => '50'], 'GET', null, 12, 4);
    } catch (Throwable $e) {
        $result['codeblue_team_error'] = $e->getMessage();
    }

    try {
        $result['connectwise_team'] = register_cw_request('/company/companies/3/teams', ['pageSize' => '50'], 'GET', null, 12, 4);
    } catch (Throwable $e) {
        $result['connectwise_team_error'] = $e->getMessage();
    }

    register_respond(200, $result);
}

/**
 * TEMPORARY diagnostic -- creates one real, clearly-labeled test Company
 * and Contact (plus a phone communication item and two Company Team role
 * assignments) to learn ConnectWise's real required-field schema for
 * writes. Each step is isolated and its own real success/error is reported
 * separately, same methodology as the Activity-creation saga in
 * relationships-connectwise-sync.md. Remove this action once
 * create-company/create-contact are built and confirmed working.
 *
 * Round 2 (2026-09-14) fills in every business field Michael specified,
 * now that probe-schema/probe-schema2 confirmed the real names:
 * accountNumber, dateAcquired, territory{id:45 "House accounts"},
 * customFields id 34 "Terms Renewal Date", contact types{id:3 "End User"}.
 * Account Manager/Sales Rep are confirmed to live on the Company's
 * teams sub-resource (accountManagerFlag/salesFlag + a teamRole), not on
 * the Contact -- "Sales Rep" teamRole id 3 was already seen on a real
 * record; "Account Manager"'s teamRole id is looked up here by name from
 * the full /company/teamRoles list (a plain unconditioned list, same
 * "diagnose before guessing" reasoning as everywhere else in this file)
 * rather than guessed.
 */
if ($action === 'probe-create') {
    $stamp = date('Y-m-d H:i:s');
    $today = date('Y-m-d\T00:00:00\Z');
    $result = ['ok' => true, 'note' => 'This created real test records in ConnectWise. Search for "ZZZ REGISTER TEST" and delete them by hand when done.'];

    $accountManagerRoleId = null;
    try {
        $roles = register_cw_request('/company/teamRoles', ['pageSize' => '100'], 'GET', null, 12, 4);
        $result['team_roles'] = $roles;
        foreach ($roles as $role) {
            if (isset($role['name']) && stripos((string) $role['name'], 'Account Manager') !== false) {
                $accountManagerRoleId = $role['id'];
                break;
            }
        }
        $result['account_manager_role_id_found'] = $accountManagerRoleId;
    } catch (Throwable $e) {
        $result['team_roles_error'] = $e->getMessage();
    }

    $testName = 'ZZZ REGISTER TEST - DELETE ME (' . $stamp . ')';
    $companyId = null;
    try {
        $company = register_cw_request('/company/companies', [], 'POST', [
            'identifier' => 'ZZZREGTEST' . date('YmdHis'),
            'name' => $testName,
            'addressLine1' => '123 Test St',
            'city' => 'Richmond',
            'state' => 'VA',
            'zip' => '23219',
            'country' => ['id' => 1],
            'phoneNumber' => '8045550100',
            'status' => ['id' => 1],
            'site' => ['name' => 'Main'], // confirmed required, round 1
            'territory' => ['id' => 45], // "House accounts"
            'accountNumber' => $testName, // "Account ID" = same as Customer Name
            'dateAcquired' => $today,
            'customFields' => [
                ['id' => 34, 'value' => $today], // "Terms Renewal Date" = date of entry
            ],
        ], 12, 4);
        $companyId = $company['id'] ?? null;
        $result['create_company'] = ['ok' => true, 'response' => $company];
    } catch (Throwable $e) {
        $result['create_company'] = ['ok' => false, 'error' => $e->getMessage()];
    }

    if ($companyId !== null) {
        $contactId = null;
        try {
            $contact = register_cw_request('/company/contacts', [], 'POST', [
                'firstName' => 'ZZZ-REGISTER-TEST',
                'lastName' => 'DELETE-ME (' . $stamp . ')',
                'company' => ['id' => $companyId],
                'title' => 'Purchaser',
                'types' => [['id' => 3]], // "End User"
            ], 12, 4);
            $contactId = $contact['id'] ?? null;
            $result['create_contact'] = ['ok' => true, 'response' => $contact];
        } catch (Throwable $e) {
            $result['create_contact'] = ['ok' => false, 'error' => $e->getMessage()];
        }

        if ($contactId !== null) {
            try {
                $phone = register_cw_request('/company/contacts/' . $contactId . '/communications', [], 'POST', [
                    'type' => ['id' => 2], // "Direct" -- confirmed real on sample_contacts
                    'value' => '8045550100', // same as company phone, per Michael's rule
                    'communicationType' => 'Phone',
                    'defaultFlag' => true,
                ], 12, 4);
                $result['create_contact_phone'] = ['ok' => true, 'response' => $phone];
            } catch (Throwable $e) {
                $result['create_contact_phone'] = ['ok' => false, 'error' => $e->getMessage()];
            }
        }

        // Company Team: Sales Rep (teamRole 3, confirmed) + Account Manager
        // (teamRole id looked up above by name), both assigned to Michael
        // Bergamo (member id 202, confirmed via territoryManager on a real
        // company record).
        try {
            $salesRep = register_cw_request('/company/companies/' . $companyId . '/teams', [], 'POST', [
                'teamRole' => ['id' => 3],
                'member' => ['id' => 202],
                'salesFlag' => true,
            ], 12, 4);
            $result['create_team_sales_rep'] = ['ok' => true, 'response' => $salesRep];
        } catch (Throwable $e) {
            $result['create_team_sales_rep'] = ['ok' => false, 'error' => $e->getMessage()];
        }

        if ($accountManagerRoleId !== null) {
            try {
                $accountManager = register_cw_request('/company/companies/' . $companyId . '/teams', [], 'POST', [
                    'teamRole' => ['id' => $accountManagerRoleId],
                    'member' => ['id' => 202],
                    'accountManagerFlag' => true,
                ], 12, 4);
                $result['create_team_account_manager'] = ['ok' => true, 'response' => $accountManager];
            } catch (Throwable $e) {
                $result['create_team_account_manager'] = ['ok' => false, 'error' => $e->getMessage()];
            }
        } else {
            $result['create_team_account_manager'] = ['ok' => false, 'error' => 'No teamRole named "Account Manager" was found in /company/teamRoles -- see team_roles above for the real list.'];
        }
    }

    register_respond(200, $result);
}

register_respond(400, ['ok' => false, 'error' => 'Unknown action.']);
