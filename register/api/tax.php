<?php
/**
 * register/api/tax.php
 *
 * TEMPORARY, READ-ONLY, login-gated diagnostic for the 2026-09-16 Sales Tax
 * sync feature. Nothing about ConnectWise's Tax Code module or which field
 * on a Company record carries a customer's assigned tax code has been
 * confirmed against this ConnectWise instance yet -- per this project's
 * established "diagnose before guessing" discipline (see
 * relationships-connectwise-sync.md's board-name saga and
 * register-app.md's Company create/invoicing research), nothing here is
 * assumed from ConnectWise's general documentation.
 *
 * GET /register/api/tax.php?action=probe
 *   -> tries several candidate paths for the Tax Code list and reports
 *      which one(s) actually work (with their real response shape), plus
 *      the full raw field set of a couple of real Company records so the
 *      tax-code field name on a Company can be identified by inspection.
 *
 * This whole file gets replaced with the real, confirmed sync once the
 * probe's output is reviewed -- same lifecycle as every other probe-*
 * action in this codebase (removed once its findings are folded into real,
 * tested code).
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

    // Candidate paths for the Tax Code list module. ConnectWise's Finance
    // module already has one confirmed-working resource in this codebase
    // (/finance/billingTerms, per customers.php's invoicing-setup research)
    // -- taxCodes is the most likely sibling, but every candidate is tried
    // and reported rather than assumed.
    $candidates = ['/finance/taxCodes', '/finance/taxcodes', '/finance/taxCodeCategories'];
    $result['tax_code_candidates'] = [];
    foreach ($candidates as $path) {
        try {
            $rows = register_cw_request($path, ['pageSize' => '10', 'page' => '1']);
            $result['tax_code_candidates'][$path] = ['ok' => true, 'count' => count($rows), 'rows' => $rows];
        } catch (RegisterConnectWiseError $e) {
            $result['tax_code_candidates'][$path] = ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    // A few real Company records, full default field set (no `fields`
    // restriction) -- so whatever field carries a Company's assigned tax
    // code can be spotted by inspection rather than guessed.
    try {
        $companies = register_cw_request('/company/companies', ['pageSize' => '3', 'page' => '1']);
        $result['sample_companies'] = $companies;
    } catch (RegisterConnectWiseError $e) {
        $result['sample_companies_error'] = $e->getMessage();
    }

    register_respond(200, $result);
}

register_respond(400, ['ok' => false, 'error' => 'Unknown action.']);
