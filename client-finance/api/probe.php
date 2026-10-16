<?php
/**
 * client-finance/api/probe.php
 *
 * TEMPORARY, READ-ONLY diagnostic for the new "Client Finance" card
 * Michael asked for on 2026-10-16 (a per-customer financial dashboard
 * pulled live from ConnectWise for in-meeting use: Standard/T&M invoices,
 * Product Sales / Sales Orders with line-level drill-down, Agreement
 * Invoicing with addition-level detail, Credit Memos, and an "Opportunity"
 * scan for 3+ year old Computer/Networking hardware sales). Makes ZERO
 * writes to ConnectWise -- it only reads real records and reports their
 * raw shape, same discipline as every other ConnectWise unknown in this
 * codebase (register/api/ticket_invoice_probe.php, the agreement-billing
 * probe behind register-agreement-billing-probe-findings.md). Delete this
 * file once its findings are folded into the real sync code.
 *
 * This build environment has no network path to
 * connect.codebluetechnology.com, so Claude cannot run this itself --
 * Michael needs to open each action below (signed in as
 * mbergamo@codebluetechnology.com -- this probe is locked to that one
 * account, see the guard below) and paste back the JSON, especially any
 * error text.
 *
 * What this is trying to confirm (see claude/relationships-connectwise-
 * sync.md and register-agreement-billing-probe-findings.md for what's
 * ALREADY confirmed and not re-probed here: /finance/invoices itself
 * works and returns `type` + `applyToType` as two separate fields;
 * `/finance/agreements?conditions=type/id=65` returns real IT Services
 * Agreements; agreement type ids 64-67 are confirmed):
 *
 *   1. How to tell a genuine Standard/T&M SERVICE invoice (ticket time +
 *      maybe parts, billed at T&M) apart from a Standard invoice that's
 *      actually a Sales-Order-sourced product sale, and apart from an
 *      Agreement invoice or a Credit Memo -- `type` and `applyToType`
 *      values, tallied across a real recent sample.
 *   2. The real string ConnectWise uses for a Credit Memo invoice's
 *      `type` (never seen anywhere in this codebase before).
 *   3. A Sales Order's real line-item shape: product identifier, cost,
 *      price, date, and whatever field (if any) ties a line back to the
 *      service/project ticket it was sold against.
 *   4. An Agreement's addition-level fields: price, cost, start date,
 *      cancelled date (customer_services in relationships/ only stores
 *      pillar/service classification today -- no financial fields at
 *      all, see relationships/api/db.php).
 *   5. Whether the Procurement Catalog's category/subcategory fields (or
 *      only the identifier text, like the already-confirmed `PE-` prefix
 *      rule for Provided Equipment) reliably distinguish Computer
 *      (Desktop/Laptop/Server) and Networking (Firewall/Switch/Wireless)
 *      hardware, for the Opportunity scan.
 *
 * GET ?action=probe-invoice-type-tally&company_id=N (company_id optional
 *     -- omit for a company-agnostic recent sample)
 *   -> pulls up to 300 recent invoices (id, invoiceNumber, type,
 *      applyToType, total, date, company, applyToId), tallies them by
 *      (type, applyToType) pair, and re-fetches ONE full-field example
 *      invoice per distinct pair seen (capped at 10 pairs) so the real
 *      shape is visible alongside the real values. This is the
 *      centerpiece -- answers #1 and #2 above in one run.
 * GET ?action=probe-invoice-reference-lists
 *   -> GET /finance/invoices/types and /finance/invoices/statuses -- the
 *      authoritative list of every type/status string ConnectWise has,
 *      Credit Memo included if it exists.
 * GET ?action=probe-sales-orders&company_id=N (optional)
 *   -> tries a few candidate endpoints/shapes for actual Sales Order
 *      records (not just invoices with applyToType=SalesOrder) and, for
 *      the first order found, tries candidate line-item sub-resources.
 * GET ?action=probe-agreement-additions&agreement_id=N (optional -- looks
 *     up a real IT Services Agreement itself if omitted)
 *   -> full-field /finance/agreements/{id}/additions for one real
 *      agreement.
 * GET ?action=probe-catalog-category-sample
 *   -> a sample of Procurement Catalog items (same productClass=Inventory
 *      scope already confirmed working in register/) with category/
 *      subcategory + identifier, to eyeball Computer/Networking
 *      classification options.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../relationships/api/_util.php';
require_once __DIR__ . '/../../relationships/api/connectwise.php';
require_once __DIR__ . '/../../auth/commissions-access.php';

$pdo = relationships_db();
$user = relationships_require_login($pdo);

// Locked to Michael only -- this is a raw-data diagnostic for an app that
// doesn't have its own access list yet (Client Finance's real access
// control is a separate, non-probe piece of work).
if (!commissions_is_manager($user['email'] ?? null)) {
    relationships_respond(403, ['ok' => false, 'error' => 'This diagnostic is restricted to Michael Bergamo while Client Finance access control is still being built.']);
}

$action = $_GET['action'] ?? '';

/** Same "never let one bad guess kill the others" wrapper every probe in this codebase uses. */
function cf_probe_try(string $label, callable $fn): array
{
    try {
        return ['label' => $label, 'ok' => true, 'result' => $fn()];
    } catch (Throwable $e) {
        return ['label' => $label, 'ok' => false, 'error' => $e->getMessage()];
    }
}

if ($action === 'probe-invoice-type-tally') {
    $companyId = (int) ($_GET['company_id'] ?? 0);
    $out = cf_probe_try('recent invoices, type/applyToType tally', function () use ($companyId) {
        $conditions = $companyId > 0 ? "company/id=$companyId" : '';
        $rows = relationships_cw_list(
            '/finance/invoices',
            $conditions,
            ['id', 'invoiceNumber', 'type', 'applyToType', 'applyToId', 'total', 'date', 'company'],
            300
        );
        $tally = [];      // "type|applyToType" => count
        $exampleId = [];  // "type|applyToType" => first invoice id seen
        foreach ($rows as $r) {
            $type = is_array($r['type'] ?? null) ? (string) ($r['type']['name'] ?? '') : (string) ($r['type'] ?? '');
            $applyTo = (string) ($r['applyToType'] ?? '');
            $key = $type . '|' . $applyTo;
            $tally[$key] = ($tally[$key] ?? 0) + 1;
            if (!isset($exampleId[$key])) {
                $exampleId[$key] = (int) ($r['id'] ?? 0);
            }
        }
        // Full-field example per distinct pair, capped so one odd invoice
        // mix can't turn this into dozens of extra requests.
        $examples = [];
        $pairs = array_slice(array_keys($exampleId), 0, 10);
        foreach ($pairs as $key) {
            $id = $exampleId[$key];
            try {
                $examples[$key] = relationships_cw_request("/finance/invoices/$id");
            } catch (Throwable $e) {
                $examples[$key] = ['_fetch_error' => $e->getMessage()];
            }
        }
        return [
            'sampled_count' => count($rows),
            'tally_by_type_and_applyToType' => $tally,
            'full_example_per_pair' => $examples,
        ];
    });
    relationships_respond(200, ['ok' => true, 'probe' => $out]);
}

if ($action === 'probe-invoice-reference-lists') {
    $results = [
        cf_probe_try('GET /finance/invoices/types', function () {
            return relationships_cw_request('/finance/invoices/types');
        }),
        cf_probe_try('GET /finance/invoices/statuses', function () {
            return relationships_cw_request('/finance/invoices/statuses');
        }),
    ];
    relationships_respond(200, ['ok' => true, 'probe' => $results]);
}

if ($action === 'probe-sales-orders') {
    $companyId = (int) ($_GET['company_id'] ?? 0);
    $condition = $companyId > 0 ? "company/id=$companyId" : '';

    $orderAttempts = [
        '/sales/orders' => $condition,
        '/procurement/orders' => $condition,
    ];
    $results = [];
    $firstOrderId = null;
    $firstOrderPath = null;
    foreach ($orderAttempts as $path => $cond) {
        $r = cf_probe_try("GET $path" . ($cond !== '' ? "?conditions=$cond" : ' (no conditions)'), function () use ($path, $cond) {
            return relationships_cw_request($path, array_filter(['conditions' => $cond, 'pageSize' => 5]));
        });
        $results[] = $r;
        if ($r['ok'] && $firstOrderId === null && is_array($r['result']) && count($r['result']) > 0) {
            $firstOrderId = (int) ($r['result'][0]['id'] ?? 0);
            $firstOrderPath = $path;
        }
    }

    if ($firstOrderId !== null) {
        $results[] = cf_probe_try("GET $firstOrderPath/$firstOrderId, full fields", function () use ($firstOrderPath, $firstOrderId) {
            return relationships_cw_request("$firstOrderPath/$firstOrderId");
        });
        $lineItemAttempts = ["$firstOrderPath/$firstOrderId/lineitems", "$firstOrderPath/$firstOrderId/items"];
        foreach ($lineItemAttempts as $p) {
            $results[] = cf_probe_try("GET $p", function () use ($p) {
                return relationships_cw_request($p, ['pageSize' => 25]);
            });
        }
    } else {
        $results[] = ['label' => 'line-item sub-resource attempts', 'ok' => false, 'error' => 'No order id found from the list attempts above -- pass &company_id=<a real ConnectWise company id known to have product sales> and re-run.'];
    }

    relationships_respond(200, ['ok' => true, 'probe' => $results]);
}

if ($action === 'probe-agreement-additions') {
    $agreementId = (int) ($_GET['agreement_id'] ?? 0);
    if ($agreementId <= 0) {
        $lookup = cf_probe_try('find one real IT Services Agreement (type/id=65)', function () {
            return relationships_cw_list('/finance/agreements', 'type/id=65', ['id', 'name', 'company'], 1);
        });
        if ($lookup['ok'] && count($lookup['result']) > 0) {
            $agreementId = (int) $lookup['result'][0]['id'];
        }
        $results = [$lookup];
    } else {
        $results = [];
    }

    if ($agreementId > 0) {
        $results[] = cf_probe_try("GET /finance/agreements/$agreementId/additions, full fields", function () use ($agreementId) {
            return relationships_cw_request("/finance/agreements/$agreementId/additions", ['pageSize' => 25]);
        });
    } else {
        $results[] = ['label' => 'additions fetch', 'ok' => false, 'error' => 'Could not find an agreement id to probe -- pass &agreement_id=N explicitly.'];
    }

    relationships_respond(200, ['ok' => true, 'probe' => $results]);
}

if ($action === 'probe-catalog-category-sample') {
    $out = cf_probe_try('Procurement Catalog sample, productClass=Inventory', function () {
        return relationships_cw_list(
            '/procurement/catalog',
            "productClass='Inventory' and inactiveFlag=false",
            ['id', 'identifier', 'description', 'category', 'subcategory', 'productClass'],
            150
        );
    });
    relationships_respond(200, ['ok' => true, 'probe' => $out]);
}

relationships_respond(400, ['ok' => false, 'error' => 'Unknown or missing action. See the docblock at the top of this file for the list.']);
