<?php
/**
 * register/api/agreement_create_test.php
 *
 * TEMPORARY, WRITE diagnostic for the "Protection Plan items as recurring
 * ConnectWise Agreement Additions" feature (task: upsold/standalone
 * Protection Plan items at the register need to become Agreement Additions
 * on an "IT Services Agreement", billed monthly or annually, followed by
 * creating + closing the first invoice).
 *
 * UNLIKE agreements_probe.php (fully read-only), THIS FILE CREATES REAL
 * CONNECTWISE RECORDS: one Agreement, one Addition, and one Invoice. Michael
 * explicitly approved running this ("Yes, run it now") after read-only
 * probing (see register-agreement-billing-probe-findings.md) hit a genuine
 * dead end on one open question -- where ConnectWise's "Billing Status"
 * field (New -> Approved, a concept Michael described from the invoice
 * creation UI) actually lives. Two hypotheses (a populated invoice
 * customFields entry; a registered system UDF captioned "Billing Status")
 * both came back negative on read-only probing, and there is no reference-
 * list endpoint (/finance/invoices/statuses 404s) to enumerate configured-
 * but-currently-unoccupied status options. The only way left to resolve it
 * is to create a real invoice and read back exactly what ConnectWise
 * actually did.
 *
 * Everything this script creates is scoped to the real "Bergamo Test
 * Account" company (id 7918) that Michael named specifically as a safe
 * place to do this -- never a real customer's billing record. This script
 * does NOT close, approve, or otherwise finalize the invoice it creates --
 * per my own explicit commitment to Michael, nothing gets closed/finalized
 * without checking with him first, once the real field shapes this test
 * reveals are in hand.
 *
 * Safety / idempotency:
 *   - Requires ?confirm=yes-create-real-records in the query string, so a
 *     stray/accidental visit to this URL can't trigger a write by mistake.
 *   - Before creating the Agreement, checks whether an IT Services
 *     Agreement already exists on company 7918 and reuses it instead of
 *     creating a duplicate if this script is run more than once.
 *   - Before creating the Addition, checks the (possibly reused)
 *     agreement's existing additions for the same test product and reuses
 *     it instead of creating a duplicate.
 *   - Before creating the Invoice, checks for an existing invoice already
 *     linked to the (possibly reused) agreement via applyToId and reuses
 *     it instead of creating a duplicate.
 *   - No DELETE calls anywhere in this file. Cleanup, if Michael wants it,
 *     is a separate, explicit step -- this script only ever creates or
 *     reuses, never removes.
 *
 * DELETE THIS FILE once its findings have been used to write the real
 * checkout-flow Agreement/Addition/Invoice creation code -- it has no
 * place in the shipped app (no UI links to it, but it's reachable by URL
 * to anyone signed in, and creates real ConnectWise records on every
 * confirmed visit).
 *
 * Usage: sign in to the Register app as normal, then visit:
 *
 *   /register/api/agreement_create_test.php?confirm=yes-create-real-records
 *
 * Returns one JSON report of exactly what was created/reused at each step,
 * plus the full real record ConnectWise handed back after each write (no
 * `fields` param sent on the read-backs, so whatever ConnectWise actually
 * populated -- including, hopefully, whatever field "Billing Status"
 * really is -- shows up in full).
 *
 * Round 1 successfully created a real Agreement (id 3328) and Addition (id
 * 11004) against Bergamo Test Account, but the invoice-create attempt
 * (applyToType/applyToId only) came back HTTP 400: ConnectWise also
 * requires `type` and `company` on that payload. Round 2 (this version)
 * adds a best-effort invoice-type lookup and retries invoice creation with
 * both fields included -- the idempotency guards above mean the already-
 * created Agreement/Addition are reused, not duplicated, on this next run.
 */

declare(strict_types=1);

require_once __DIR__ . '/_util.php';
require_once __DIR__ . '/connectwise.php';

register_install_error_handlers();

@ini_set('max_execution_time', '55');

$pdo = register_db();
register_require_login($pdo);

if (($_GET['confirm'] ?? '') !== 'yes-create-real-records') {
    register_respond(400, [
        'ok' => false,
        'error' => 'Refusing to run: this script creates real ConnectWise records. ' .
            'Visit it with ?confirm=yes-create-real-records to proceed.',
    ]);
}

/**
 * Same shape as agreements_probe.php's probe_safe() -- catches errors so a
 * failed step doesn't hide the results of steps that already succeeded,
 * but here the wrapped calls include real writes, not just reads.
 */
function step_safe(callable $fn): array
{
    try {
        return ['ok' => true, 'data' => $fn()];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

// Real ids confirmed via agreements_probe.php round 6 -- see
// register-agreement-billing-probe-findings.md.
const TEST_COMPANY_ID = 7918;   // "Bergamo Test Account"
const TEST_SITE_ID = 10383;     // "Main"
const TEST_CONTACT_ID = 16692;  // "Michael Bergamo"
const AGREEMENT_TYPE_ID = 65;   // "IT Services Agreement"

// One real Protection Plan catalog identifier (from the Computer upsell
// builder's confirmed PROTECTION_PLAN_ITEMS_DEF -- see
// register-computer-upsell-builder.md) used as this test's single Addition.
// Not a special choice -- any of the 5 would exercise the same code path.
const TEST_PRODUCT_IDENTIFIER = 'SENT-ONE-CTRL';

// Michael did not specify monthly vs. annual for this test -- defaulting to
// Monthly ({id:2, name:"Monthly"}, confirmed real in agreements_probe.php
// round 1) is an arbitrary choice for THIS test only. The real checkout
// flow will set billingCycle based on what's selected at the register.
const TEST_BILLING_CYCLE_ID = 2; // "Monthly"

$today = (new DateTimeImmutable('today', new DateTimeZone('UTC')))->format('Y-m-d') . 'T00:00:00Z';

$report = [];

// 1. Reuse an existing IT Services Agreement on the test company if one
//    already exists (e.g. from a prior run of this script), instead of
//    creating a duplicate.
$report['agreement_lookup'] = step_safe(function () {
    return register_cw_request(
        '/finance/agreements',
        ['conditions' => 'company/id=' . TEST_COMPANY_ID . ' and type/id=' . AGREEMENT_TYPE_ID, 'pageSize' => '5', 'page' => '1'],
        'GET',
        null,
        20,
        8
    );
});

$agreementId = null;
$agreementReused = false;
if ($report['agreement_lookup']['ok'] && !empty($report['agreement_lookup']['data'][0]['id'])) {
    $agreementId = (int) $report['agreement_lookup']['data'][0]['id'];
    $agreementReused = true;
}

// 2. Create the Agreement, only if step 1 didn't find one to reuse.
if ($agreementId === null) {
    $report['agreement_create'] = step_safe(function () use ($today) {
        return register_cw_request(
            '/finance/agreements',
            [],
            'POST',
            [
                'name' => 'IT Services Agreement',
                'type' => ['id' => AGREEMENT_TYPE_ID],
                'company' => ['id' => TEST_COMPANY_ID],
                'contact' => ['id' => TEST_CONTACT_ID],
                'site' => ['id' => TEST_SITE_ID],
                'startDate' => $today,
                'noEndingDateFlag' => true,
                'billingCycle' => ['id' => TEST_BILLING_CYCLE_ID],
                'billingTerms' => ['id' => 3], // "Due Upon Receipt"
            ],
            25,
            8
        );
    });
    if ($report['agreement_create']['ok'] && !empty($report['agreement_create']['data']['id'])) {
        $agreementId = (int) $report['agreement_create']['data']['id'];
    }
} else {
    $report['agreement_create'] = ['ok' => true, 'data' => 'Skipped -- reused existing agreement id ' . $agreementId . ' from step 1.'];
}

$report['agreement_id_used'] = $agreementId;
$report['agreement_reused'] = $agreementReused;

// 3. Read back the full agreement record (no `fields` param -- full
//    default shape) so we see exactly what ConnectWise actually stored,
//    not just what we sent.
if ($agreementId !== null) {
    $report['agreement_readback'] = step_safe(function () use ($agreementId) {
        return register_cw_request('/finance/agreements/' . $agreementId, [], 'GET', null, 20, 8);
    });
}

// 4. Look up the real catalog product id for the test Addition.
$testProductId = null;
if ($agreementId !== null) {
    $report['test_product_lookup'] = step_safe(function () {
        return register_cw_request(
            '/procurement/catalog',
            ['conditions' => 'identifier="' . register_cw_condition_escape(TEST_PRODUCT_IDENTIFIER) . '"', 'pageSize' => '5', 'page' => '1'],
            'GET',
            null,
            20,
            8
        );
    });
    if ($report['test_product_lookup']['ok'] && !empty($report['test_product_lookup']['data'][0]['id'])) {
        $testProductId = (int) $report['test_product_lookup']['data'][0]['id'];
    }
}

// 5. Reuse an existing Addition for this product on the agreement if one's
//    already there, instead of creating a duplicate.
$additionId = null;
$additionReused = false;
if ($agreementId !== null && $testProductId !== null) {
    $report['addition_lookup'] = step_safe(function () use ($agreementId, $testProductId) {
        return register_cw_request(
            '/finance/agreements/' . $agreementId . '/additions',
            ['conditions' => 'product/id=' . $testProductId, 'pageSize' => '5', 'page' => '1'],
            'GET',
            null,
            20,
            8
        );
    });
    if ($report['addition_lookup']['ok'] && !empty($report['addition_lookup']['data'][0]['id'])) {
        $additionId = (int) $report['addition_lookup']['data'][0]['id'];
        $additionReused = true;
    }
}

// 6. Create the Addition, only if step 5 didn't find one to reuse.
if ($agreementId !== null && $testProductId !== null && $additionId === null) {
    $report['addition_create'] = step_safe(function () use ($agreementId, $testProductId, $today) {
        return register_cw_request(
            '/finance/agreements/' . $agreementId . '/additions',
            [],
            'POST',
            [
                'product' => ['id' => $testProductId],
                'quantity' => 1,
                'billCustomer' => 'Billable',
                'effectiveDate' => $today,
                // cancelledDate deliberately omitted -- its absence is what
                // "no end date" means for an Addition (see
                // register-agreement-billing-probe-findings.md).
            ],
            25,
            8
        );
    });
    if ($report['addition_create']['ok'] && !empty($report['addition_create']['data']['id'])) {
        $additionId = (int) $report['addition_create']['data']['id'];
    }
} elseif ($additionReused) {
    $report['addition_create'] = ['ok' => true, 'data' => 'Skipped -- reused existing addition id ' . $additionId . ' from step 5.'];
} elseif ($testProductId === null) {
    $report['addition_create'] = ['ok' => false, 'error' => 'Skipped -- test_product_lookup did not find identifier "' . TEST_PRODUCT_IDENTIFIER . '" in the catalog.'];
}

$report['addition_id_used'] = $additionId;
$report['addition_reused'] = $additionReused;

// 7. Read back the full addition record.
if ($additionId !== null && $agreementId !== null) {
    $report['addition_readback'] = step_safe(function () use ($agreementId, $additionId) {
        return register_cw_request('/finance/agreements/' . $agreementId . '/additions/' . $additionId, [], 'GET', null, 20, 8);
    });
}

// 8. Reuse an existing invoice linked to this agreement if one's already
//    there, instead of creating a duplicate.
$invoiceId = null;
$invoiceReused = false;
if ($agreementId !== null) {
    $report['invoice_lookup'] = step_safe(function () use ($agreementId) {
        return register_cw_request(
            '/finance/invoices',
            ['conditions' => 'applyToId=' . $agreementId . ' and applyToType="Agreement"', 'pageSize' => '5', 'page' => '1'],
            'GET',
            null,
            20,
            8
        );
    });
    if ($report['invoice_lookup']['ok'] && !empty($report['invoice_lookup']['data'][0]['id'])) {
        $invoiceId = (int) $report['invoice_lookup']['data'][0]['id'];
        $invoiceReused = true;
    }
}

// 9. Round 2: the first attempt (applyToType/applyToId only) came back
//    HTTP 400 -- ConnectWise requires `type` and `company` on the invoice
//    create payload too. Look up this instance's real invoice types before
//    retrying, same "probe before guessing" discipline as everywhere else.
$invoiceTypeId = null;
if ($agreementId !== null && $invoiceId === null) {
    $report['invoice_type_lookup'] = step_safe(function () {
        return register_cw_request('/finance/invoices/types', ['pageSize' => '50', 'page' => '1'], 'GET', null, 20, 8);
    });
    if ($report['invoice_type_lookup']['ok']) {
        $types = $report['invoice_type_lookup']['data'];
        $preferredNames = ['Agreement', 'Standard'];
        foreach ($preferredNames as $preferredName) {
            foreach ($types as $t) {
                if (($t['name'] ?? null) === $preferredName && empty($t['inactiveFlag'])) {
                    $invoiceTypeId = (int) $t['id'];
                    break 2;
                }
            }
        }
        if ($invoiceTypeId === null) {
            foreach ($types as $t) {
                if (empty($t['inactiveFlag'])) {
                    $invoiceTypeId = (int) $t['id'];
                    break;
                }
            }
        }
        if ($invoiceTypeId === null && !empty($types[0]['id'])) {
            $invoiceTypeId = (int) $types[0]['id'];
        }
    }
    $report['invoice_type_id_chosen'] = $invoiceTypeId;
}

// 10. Create the Invoice, only if step 8 didn't find one to reuse. Payload
//     is otherwise still minimal on purpose -- the whole point of this
//     step is to observe what ConnectWise actually defaults `status` (and
//     hopefully "Billing Status") to on a freshly created invoice, not to
//     guess and force a value. Nothing here closes or approves anything.
if ($agreementId !== null && $invoiceId === null) {
    $report['invoice_create'] = step_safe(function () use ($agreementId, $invoiceTypeId) {
        $payload = [
            'applyToType' => 'Agreement',
            'applyToId' => $agreementId,
            'company' => ['id' => TEST_COMPANY_ID],
        ];
        if ($invoiceTypeId !== null) {
            $payload['type'] = ['id' => $invoiceTypeId];
        }
        return register_cw_request('/finance/invoices', [], 'POST', $payload, 25, 8);
    });
    if ($report['invoice_create']['ok'] && !empty($report['invoice_create']['data']['id'])) {
        $invoiceId = (int) $report['invoice_create']['data']['id'];
    }
} else {
    $report['invoice_create'] = ['ok' => true, 'data' => 'Skipped -- reused existing invoice id ' . $invoiceId . ' from step 8.'];
}

$report['invoice_id_used'] = $invoiceId;
$report['invoice_reused'] = $invoiceReused;

// 10. Read back the full invoice record -- no `fields` param, so whatever
//     ConnectWise actually populated (including, hopefully, whatever field
//     "Billing Status" really corresponds to) shows up in full.
if ($invoiceId !== null) {
    $report['invoice_readback'] = step_safe(function () use ($invoiceId) {
        return register_cw_request('/finance/invoices/' . $invoiceId, [], 'GET', null, 20, 8);
    });
}

register_respond(200, [
    'ok' => true,
    'note' => 'WRITE diagnostic. This request may have created real ConnectWise records against ' .
        'company id ' . TEST_COMPANY_ID . ' ("Bergamo Test Account"). Nothing was closed or approved.',
    'report' => $report,
]);
