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
 * TEMPORARY diagnostic -- creates one real, clearly-labeled test Company
 * and Contact (plus one email + one phone communication item on that
 * contact) to learn ConnectWise's real required-field schema for writes.
 * Each step is isolated and its own real success/error is reported
 * separately, same methodology as the Activity-creation saga in
 * relationships-connectwise-sync.md. Remove this action once
 * create-company/create-contact are built and confirmed working.
 */
if ($action === 'probe-create') {
    $stamp = date('Y-m-d H:i:s');
    $result = ['ok' => true, 'note' => 'This created real test records in ConnectWise. Search for "ZZZ REGISTER TEST" and delete them by hand when done.'];

    $companyId = null;
    try {
        $company = register_cw_request('/company/companies', [], 'POST', [
            'identifier' => 'ZZZREGTEST' . date('YmdHis'),
            'name' => 'ZZZ REGISTER TEST - DELETE ME (' . $stamp . ')',
            'addressLine1' => '123 Test St',
            'city' => 'Richmond',
            'state' => 'VA',
            'zip' => '23219',
            'phoneNumber' => '8045550100',
            'website' => 'https://example.invalid',
            'status' => ['id' => 1],
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
                'title' => 'Register diagnostic test contact',
            ], 12, 4);
            $contactId = $contact['id'] ?? null;
            $result['create_contact'] = ['ok' => true, 'response' => $contact];
        } catch (Throwable $e) {
            $result['create_contact'] = ['ok' => false, 'error' => $e->getMessage()];
        }

        if ($contactId !== null) {
            try {
                $email = register_cw_request('/company/contacts/' . $contactId . '/communications', [], 'POST', [
                    'type' => ['id' => 1], // 1 = "Email", per the probe's sample_contacts communicationItems
                    'value' => 'register-test-' . date('YmdHis') . '@example.invalid',
                    'communicationType' => 'Email',
                    'defaultFlag' => true,
                ], 12, 4);
                $result['create_contact_email'] = ['ok' => true, 'response' => $email];
            } catch (Throwable $e) {
                $result['create_contact_email'] = ['ok' => false, 'error' => $e->getMessage()];
            }

            try {
                $phone = register_cw_request('/company/contacts/' . $contactId . '/communications', [], 'POST', [
                    'type' => ['id' => 2], // 2 = "Direct", per the probe's sample_contacts communicationItems
                    'value' => '8045550101',
                    'communicationType' => 'Phone',
                    'defaultFlag' => true,
                ], 12, 4);
                $result['create_contact_phone'] = ['ok' => true, 'response' => $phone];
            } catch (Throwable $e) {
                $result['create_contact_phone'] = ['ok' => false, 'error' => $e->getMessage()];
            }
        }
    }

    register_respond(200, $result);
}

register_respond(400, ['ok' => false, 'error' => 'Unknown action.']);
