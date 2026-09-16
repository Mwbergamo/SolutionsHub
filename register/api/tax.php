<?php
/**
 * register/api/tax.php
 *
 * ConnectWise Tax Code sync for the register (added 2026-09-16, per
 * Michael: "keep existing customer's tax preferences the same in the
 * register... VA-STATE would be default with a Tax Exempt option
 * selectable for customers that can prove tax exemption at the register").
 * The actual helper functions/schema logic live in tax-core.php (shared
 * with customers.php/checkout.php); this file is just the GET/POST
 * endpoints, same split as this codebase's other *-core.php files.
 *
 * ---- Research trail (2026-09-16) ----
 * Confirmed against real ConnectWise data via a temporary read-only
 * ?action=probe diagnostic (since removed), per this project's established
 * "diagnose before guessing" discipline:
 *   - GET /finance/taxCodes (list, unconditioned) returns real Tax Code
 *     records: id, identifier (e.g. "VA", "Exem", "Out", "VA-Hamp",
 *     "VA-NOVA" -- matches ConnectWise's own "Tax Code" column), description
 *     (e.g. "State-New", "Tax Exempt" -- matches the "Name" column),
 *     defaultFlag (true only for id 15, "VA"/State-New -- the currently
 *     active default), and levelOneRate through levelSixRate (each a
 *     fraction, e.g. 0.06 = 6%) -- ConnectWise splits a combined state+
 *     local rate across two levels (VA-Hamp/VA-NOVA: levelOneRate 0.007 +
 *     levelTwoRate 0.053 = 0.06 total), so the real per-code rate is the
 *     SUM of all six levels -- see tax-core.php's register_tax_code_total_rate().
 *   - The real ConnectWise instance also still has one OLD, superseded "VA"
 *     code on file (id 11, "State-Old", cancelDate 2020-09-30) alongside
 *     the current active one (id 15, "State-New", no cancelDate) -- both
 *     share identifier "VA". register_sync_tax_codes() only ever keeps
 *     codes with a null cancelDate, which is exactly the 5 active codes
 *     Michael's own screenshot shows (Exem, Out, VA, VA-Hamp, VA-NOVA).
 *   - A real Company record carries its assigned tax code as
 *     `taxCode: {id, name}` (confirmed live on 3 real companies) -- `name`
 *     there is the TaxCode's own `description` field (e.g. "State-New"),
 *     not its `identifier` ("VA"). This is the field
 *     register_cw_set_company_tax_code() (customers.php) writes and
 *     action=company-tax (customers.php) reads.
 *
 * GET  /register/api/tax.php?action=list
 *   -> { ok: true, tax_codes: [ { id, identifier, name, rate, is_default }, ... ] }
 *   Locally synced codes only -- no live ConnectWise call.
 *
 * POST /register/api/tax.php?action=sync
 *   -> { ok: true, synced: int, tax_codes: [...] }
 *   Pulls /finance/taxCodes fresh and replaces the local table. Small
 *   (5-6 rows) -- runs synchronously in one request, no queue/step needed
 *   unlike the ~14,600-item catalog sync.
 */

declare(strict_types=1);

require_once __DIR__ . '/_util.php';
require_once __DIR__ . '/connectwise.php';
require_once __DIR__ . '/tax-core.php';

register_install_error_handlers();

$pdo = register_db();
register_require_login($pdo);

$action = $_GET['action'] ?? '';

if ($action === 'list') {
    register_respond(200, ['ok' => true, 'tax_codes' => register_list_tax_codes($pdo)]);
}

if ($action === 'sync') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        register_respond(405, ['ok' => false, 'error' => 'Method not allowed.']);
    }
    try {
        $taxCodes = register_sync_tax_codes($pdo);
        register_respond(200, ['ok' => true, 'synced' => count($taxCodes), 'tax_codes' => $taxCodes]);
    } catch (Throwable $e) {
        register_respond(502, ['ok' => false, 'error' => 'ConnectWise tax code sync failed: ' . $e->getMessage()]);
    }
}

register_respond(400, ['ok' => false, 'error' => 'Unknown action.']);
