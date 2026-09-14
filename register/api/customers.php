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
 * THIS FILE IS CURRENTLY DIAGNOSTIC-ONLY. Per this project's established
 * discipline (see claude/relationships-connectwise-sync.md's board-name and
 * Activity-creation bug-fix histories -- an unverified ConnectWise condition
 * or field name has burned this codebase repeatedly, usually by silently
 * returning zero/wrong results rather than erroring), nothing here is
 * guessed: search/create actions are being built ONLY after each piece is
 * confirmed against real ConnectWise data via the temporary probe action
 * below. Do not add search-companies/search-contacts/create-company/
 * create-contact actions until their exact condition syntax and required
 * fields are confirmed the same way Activity creation's were.
 *
 * GET /register/api/customers.php?action=probe (temporary, remove once
 *   every finding below is confirmed)
 *   -> pulls a few real Company/Contact records unconditionally (to see
 *      the real field shapes -- address/phone/website on Company; title/
 *      communicationItems/company linkage on Contact) AND self-verifies a
 *      guessed LIKE-search condition and a company/id contact filter
 *      against those same real records, so no guess about condition
 *      syntax ships without being proven against live data first (same
 *      lesson as the board-name saga in relationships-connectwise-sync.md).
 */

declare(strict_types=1);

require_once __DIR__ . '/_util.php';
require_once __DIR__ . '/connectwise.php';

register_install_error_handlers();

$pdo = register_db();
register_require_login($pdo);

$action = $_GET['action'] ?? '';

if ($action === 'probe') {
    $result = ['ok' => true];

    // ---- 1. Raw shape of a few real companies (no condition at all) ----
    try {
        $companies = register_cw_request('/company/companies', [
            'pageSize' => '3',
            'fields' => 'id,identifier,name,status,type,types,addressLine1,addressLine2,city,state,zip,country,phoneNumber,website,site',
        ]);
        $result['sample_companies'] = $companies;
    } catch (Throwable $e) {
        $result['sample_companies_error'] = $e->getMessage();
        $companies = [];
    }

    // ---- 2. Raw shape of a few real contacts (no condition at all) ----
    try {
        $contacts = register_cw_request('/company/contacts', [
            'pageSize' => '3',
            'fields' => 'id,firstName,lastName,title,company,communicationItems,inactiveFlag',
        ]);
        $result['sample_contacts'] = $contacts;
    } catch (Throwable $e) {
        $result['sample_contacts_error'] = $e->getMessage();
        $contacts = [];
    }

    // ---- 3. Self-verify a guessed LIKE condition on company name ----
    // Uses a real company's own name from step 1 -- if this doesn't find
    // that same company back, the condition syntax is wrong, not the data.
    if (!empty($companies[0]['name'])) {
        $fullName = (string) $companies[0]['name'];
        $needle = trim(substr($fullName, 0, (int) min(5, strlen($fullName))));
        $expectedId = (int) $companies[0]['id'];
        try {
            $matches = register_cw_request('/company/companies', [
                'conditions' => 'name like "%' . str_replace('"', '', $needle) . '%"',
                'fields' => 'id,name',
                'pageSize' => '25',
            ]);
            $foundIds = array_column($matches, 'id');
            $result['company_like_search_probe'] = [
                'tried_needle' => $needle,
                'expected_company' => ['id' => $expectedId, 'name' => $fullName],
                'result_count' => count($matches),
                'found_expected_company' => in_array($expectedId, $foundIds, true),
                'results' => $matches,
            ];
        } catch (Throwable $e) {
            $result['company_like_search_probe'] = ['error' => $e->getMessage(), 'tried_needle' => $needle];
        }
    }

    // ---- 4. Self-verify a guessed LIKE condition on contact last name ----
    if (!empty($contacts[0]['lastName'])) {
        $lastName = (string) $contacts[0]['lastName'];
        $expectedId = (int) $contacts[0]['id'];
        try {
            $matches = register_cw_request('/company/contacts', [
                'conditions' => 'lastName like "%' . str_replace('"', '', $lastName) . '%"',
                'fields' => 'id,firstName,lastName,company',
                'pageSize' => '25',
            ]);
            $foundIds = array_column($matches, 'id');
            $result['contact_like_search_probe'] = [
                'tried_last_name' => $lastName,
                'expected_contact' => ['id' => $expectedId, 'lastName' => $lastName],
                'result_count' => count($matches),
                'found_expected_contact' => in_array($expectedId, $foundIds, true),
                'results' => $matches,
            ];
        } catch (Throwable $e) {
            $result['contact_like_search_probe'] = ['error' => $e->getMessage(), 'tried_last_name' => $lastName];
        }
    }

    // ---- 5. Self-verify filtering contacts by their parent company id ----
    if (!empty($contacts[0]['company']['id'])) {
        $companyId = (int) $contacts[0]['company']['id'];
        $expectedId = (int) $contacts[0]['id'];
        try {
            $matches = register_cw_request('/company/contacts', [
                'conditions' => 'company/id = ' . $companyId,
                'fields' => 'id,firstName,lastName,company',
                'pageSize' => '50',
            ]);
            $foundIds = array_column($matches, 'id');
            $result['contact_by_company_probe'] = [
                'tried_company_id' => $companyId,
                'expected_contact' => ['id' => $expectedId],
                'result_count' => count($matches),
                'found_expected_contact' => in_array($expectedId, $foundIds, true),
                'results' => $matches,
            ];
        } catch (Throwable $e) {
            $result['contact_by_company_probe'] = ['error' => $e->getMessage(), 'tried_company_id' => $companyId];
        }
    }

    register_respond(200, $result);
}

register_respond(400, ['ok' => false, 'error' => 'Unknown action.']);
