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
 * requires `type` and `company` on that payload. Round 2 added a
 * best-effort invoice-type lookup and retries invoice creation with both
 * fields included.
 *
 * Round 3 fixed a real bug Michael caught: `$today` was computed in UTC,
 * not CBT's own Eastern timezone, so the round-1/2 agreement/addition came
 * back dated for the NEXT calendar day whenever the script ran after 8pm
 * Eastern. Per Michael: "Both need to start at the moment of sale or CWM
 * won't be able to generate an invoice."
 *
 * Round 4 (this version) fixes two problems round 3's own run surfaced:
 *   1. The agreement-lookup (and invoice-lookup) reuse checks used a
 *      COMPOUND "and" condition, which silently came back empty even
 *      though a matching real Agreement (3328) existed -- compound
 *      conditions were never confirmed to work on this ConnectWise
 *      instance (see connectwise.php's register_cw_condition_escape()
 *      docblock). That false-empty result is why round 3 created a
 *      DUPLICATE agreement (3329) instead of reusing 3328. Fixed by
 *      switching both lookups to a single-field condition with
 *      client-side filtering.
 *   2. `billStartDate` was left for ConnectWise to auto-default on create
 *      and drifted one day ahead of the (now-corrected) `startDate` --
 *      which then made the Addition create fail with "effectiveDate
 *      cannot be less than the agreement billing start date." Fixed by
 *      sending `billStartDate` explicitly on create, and by extending the
 *      date-fix step to also correct it on an existing agreement.
 *   3. The guessed `/finance/invoices/types` reference-list endpoint came
 *      back HTTP 404 (doesn't exist on this instance, like
 *      /finance/agreements/statuses). Fixed by sampling real invoices'
 *      own `type` field instead.
 * Two real Agreements now exist on Bergamo Test Account from these rounds
 * (3328, 3329) -- this version picks the higher id as canonical and
 * reports the other as a known leftover (`duplicate_agreement_ids_found`)
 * rather than deleting anything.
 *
 * Round 4's run (worked!) still created a THIRD agreement (3330, now
 * KNOWN_GOOD_AGREEMENT_ID) rather than reusing 3329 -- the search-based
 * lookup came back completely empty even though 3329 existed. Round 5
 * (this version):
 *   1. Adds a direct GET-by-id fallback (KNOWN_GOOD_AGREEMENT_ID) for when
 *      the search comes back empty, instead of creating yet another
 *      duplicate -- working theory is ConnectWise's conditions/search
 *      index on this instance is eventually consistent and lags behind a
 *      just-written record, separate from the already-known compound-
 *      condition issue.
 *   2. Switches the Addition reuse lookup to an unconditioned fetch of
 *      the agreement's whole (small) additions list + client-side filter,
 *      for the same reason.
 *   3. Replaces the `fields=id,type,date` invoice-type sample (came back
 *      HTTP 200 with zero rows despite ~123,761 real invoices -- "type"
 *      is most likely not a valid field name for this endpoint's `fields`
 *      param) with a full-default-shape sample, the same technique
 *      already proven to work in the very first read-only probing round.
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

// Round 5 finding: `conditions=company/id=...` against /finance/agreements
// came back completely empty in rounds 3 AND 4, even moments after 3328/
// 3329 were confirmed to exist via a direct GET by id -- this looks like
// ConnectWise's conditions/search index on this instance is eventually
// consistent (lags behind a just-written record), separate from this
// project's already-known "compound conditions aren't reliable" issue.
// Rather than keep creating a new duplicate Agreement every round while
// that index catches up, this constant names the last one confirmed good
// (correct date, Active, has its test Addition) so a direct GET-by-id
// fallback can reuse it even when the search comes back empty. Update
// this if a later round creates a new canonical one.
const KNOWN_GOOD_AGREEMENT_ID = 3330;

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

// Round 3 fix: this MUST be computed in CBT's own local timezone (Eastern
// -- Richmond, VA), not UTC. Round 1/2 used UTC's "today", which is why
// the agreement/addition Michael reviewed came back dated for the next
// calendar day: any register sale made after 8pm Eastern (7pm during EST)
// has already rolled over to tomorrow in UTC, even though it's still
// today for the customer and for CBT's own books. Per Michael: "Both need
// to start at the moment of sale or CWM won't be able to generate an
// invoice." The real checkout-flow write code must make this same fix --
// flagged in the findings doc so it isn't reintroduced there.
$today = (new DateTimeImmutable('today', new DateTimeZone('America/New_York')))->format('Y-m-d') . 'T00:00:00Z';

/**
 * Full-record PUT (fetch already done by caller, changes already merged
 * in) -- the only confirmed-working ConnectWise update mechanism in this
 * codebase. PATCH is confirmed broken for the Company entity elsewhere in
 * this project (see register-app.md's Company PATCH quirk); this project
 * has never updated an Agreement or Addition before, so PUT is used here
 * on that same precedent rather than guessing PATCH behaves differently.
 * Strips `_info` (read-only HATEOAS metadata ConnectWise includes on GET
 * but doesn't need back) before sending.
 */
function register_cw_put_full(string $path, array $record): array
{
    unset($record['_info']);
    return register_cw_request($path, [], 'PUT', $record, 25, 8);
}

$report = [];

// 1. Reuse an existing IT Services Agreement on the test company if one
//    already exists (e.g. from a prior run of this script), instead of
//    creating a duplicate. Round 4 fix: this used to send a COMPOUND
//    "company/id=X and type/id=Y" condition, which silently came back
//    empty in round 3 even though a matching agreement (3328) really
//    existed -- compound and/or conditions were never confirmed to work
//    on this ConnectWise instance (see register_cw_condition_escape()'s
//    own docblock in connectwise.php), and round 3 is now direct proof
//    they don't. That false-empty result caused round 3 to create a
//    duplicate agreement (3329) instead of reusing 3328. Fixed here by
//    using a single-field condition and filtering client-side in PHP --
//    the same technique this project already uses elsewhere for this
//    exact reason.
$report['agreement_lookup'] = step_safe(function () {
    $rows = register_cw_request(
        '/finance/agreements',
        ['conditions' => 'company/id=' . TEST_COMPANY_ID, 'pageSize' => '50', 'page' => '1'],
        'GET',
        null,
        20,
        8
    );
    return array_values(array_filter($rows, function ($row) {
        return (int) ($row['type']['id'] ?? 0) === AGREEMENT_TYPE_ID;
    }));
});

$agreementId = null;
$agreementReused = false;
$duplicateAgreementIds = [];
if ($report['agreement_lookup']['ok'] && !empty($report['agreement_lookup']['data'])) {
    // If prior rounds' bugs already created more than one real IT Services
    // Agreement on this test company, deterministically pick the most
    // recently created one (highest id) as canonical and just report the
    // rest as known leftovers -- no deletes are ever attempted, per this
    // file's own safety rules.
    $matches = $report['agreement_lookup']['data'];
    usort($matches, function ($a, $b) {
        return ((int) ($b['id'] ?? 0)) <=> ((int) ($a['id'] ?? 0));
    });
    $agreementId = (int) $matches[0]['id'];
    $agreementReused = true;
    foreach (array_slice($matches, 1) as $extra) {
        $duplicateAgreementIds[] = $extra['id'] ?? null;
    }
}
$report['duplicate_agreement_ids_found'] = $duplicateAgreementIds;

// Round 5 fix: the search above has come back empty two rounds running
// even though matching agreements exist (see KNOWN_GOOD_AGREEMENT_ID's
// comment) -- before falling through to creating yet another duplicate,
// try a direct GET by id on the last confirmed-good one. A direct fetch
// by primary key isn't subject to whatever indexing lag affects the
// conditions-based search.
if ($agreementId === null) {
    $report['agreement_direct_fallback'] = step_safe(function () {
        return register_cw_request('/finance/agreements/' . KNOWN_GOOD_AGREEMENT_ID, [], 'GET', null, 20, 8);
    });
    if (
        $report['agreement_direct_fallback']['ok']
        && (int) ($report['agreement_direct_fallback']['data']['company']['id'] ?? 0) === TEST_COMPANY_ID
        && (int) ($report['agreement_direct_fallback']['data']['type']['id'] ?? 0) === AGREEMENT_TYPE_ID
    ) {
        $agreementId = KNOWN_GOOD_AGREEMENT_ID;
        $agreementReused = true;
    }
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
                // Round 4 fix: send billStartDate explicitly too. Round 3
                // omitted it and ConnectWise server-defaulted it using its
                // OWN "today" (apparently not Eastern-corrected the way
                // this script's $today now is), landing one day ahead of
                // startDate -- which then made the Addition create fail
                // with "effectiveDate cannot be less than the agreement
                // billing start date." Sending the same corrected $today
                // for both fields keeps them aligned from the start.
                'billStartDate' => $today,
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

// 3b. Round 3/4 fix: if the stored startDate OR billStartDate doesn't
//     match today's correct local (Eastern) date, correct both via a
//     full-record PUT rather than leaving a wrongly-dated test agreement
//     in place. Round 4 added the billStartDate half of this check after
//     finding it can drift from startDate on its own (see the create
//     payload comment above) -- and a stale billStartDate is exactly what
//     blocked the Addition create in round 3 ("effectiveDate cannot be
//     less than the agreement billing start date").
if ($agreementId !== null && !empty($report['agreement_readback']['ok'])) {
    $agreementRecord = $report['agreement_readback']['data'];
    $correctDate = substr($today, 0, 10);
    $storedStart = substr((string) ($agreementRecord['startDate'] ?? ''), 0, 10);
    $storedBillStart = substr((string) ($agreementRecord['billStartDate'] ?? ''), 0, 10);
    if ($storedStart !== $correctDate || $storedBillStart !== $correctDate) {
        $report['agreement_date_fix'] = step_safe(function () use ($agreementId, $agreementRecord, $today) {
            $updated = $agreementRecord;
            $updated['startDate'] = $today;
            $updated['billStartDate'] = $today;
            return register_cw_put_full('/finance/agreements/' . $agreementId, $updated);
        });
        if ($report['agreement_date_fix']['ok']) {
            $report['agreement_readback'] = step_safe(function () use ($agreementId) {
                return register_cw_request('/finance/agreements/' . $agreementId, [], 'GET', null, 20, 8);
            });
        }
    } else {
        $report['agreement_date_fix'] = ['ok' => true, 'data' => 'Skipped -- stored startDate and billStartDate (' . $storedStart . ') already match today\'s correct local date.'];
    }
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
//    already there, instead of creating a duplicate. Round 5 fix: fetch
//    the agreement's whole (small, bounded) additions list unconditioned
//    and filter client-side, rather than trusting a `conditions=` search
//    -- the same eventual-consistency risk found for the agreement/
//    invoice lookups above could apply here too, and an agreement's own
//    additions list is small enough that skipping server-side filtering
//    entirely is cheap and safe.
$additionId = null;
$additionReused = false;
if ($agreementId !== null && $testProductId !== null) {
    $report['addition_lookup'] = step_safe(function () use ($agreementId, $testProductId) {
        $rows = register_cw_request(
            '/finance/agreements/' . $agreementId . '/additions',
            ['pageSize' => '50', 'page' => '1'],
            'GET',
            null,
            20,
            8
        );
        return array_values(array_filter($rows, function ($row) use ($testProductId) {
            return (int) ($row['product']['id'] ?? 0) === $testProductId;
        }));
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

// 7b. Round 3 fix: same date correction as 3b, for the Addition's
//     effectiveDate (its own "start date" field -- see
//     register-agreement-billing-probe-findings.md).
if ($additionId !== null && $agreementId !== null && !empty($report['addition_readback']['ok'])) {
    $additionRecord = $report['addition_readback']['data'];
    $storedDate = substr((string) ($additionRecord['effectiveDate'] ?? ''), 0, 10);
    $correctDate = substr($today, 0, 10);
    if ($storedDate !== '' && $storedDate !== $correctDate) {
        $report['addition_date_fix'] = step_safe(function () use ($agreementId, $additionId, $additionRecord, $today) {
            $updated = $additionRecord;
            $updated['effectiveDate'] = $today;
            return register_cw_put_full('/finance/agreements/' . $agreementId . '/additions/' . $additionId, $updated);
        });
        if ($report['addition_date_fix']['ok']) {
            $report['addition_readback'] = step_safe(function () use ($agreementId, $additionId) {
                return register_cw_request('/finance/agreements/' . $agreementId . '/additions/' . $additionId, [], 'GET', null, 20, 8);
            });
        }
    } else {
        $report['addition_date_fix'] = ['ok' => true, 'data' => 'Skipped -- stored effectiveDate (' . $storedDate . ') already matches today\'s correct local date.'];
    }
}

// 8. Reuse an existing invoice linked to this agreement if one's already
//    there, instead of creating a duplicate. Round 4 fix: same compound-
//    condition problem as step 1 above -- use a single-field condition
//    and filter applyToType client-side rather than trusting a compound
//    "and" condition on this instance.
$invoiceId = null;
$invoiceReused = false;
if ($agreementId !== null) {
    $report['invoice_lookup'] = step_safe(function () use ($agreementId) {
        $rows = register_cw_request(
            '/finance/invoices',
            ['conditions' => 'applyToId=' . $agreementId, 'pageSize' => '10', 'page' => '1'],
            'GET',
            null,
            20,
            8
        );
        return array_values(array_filter($rows, function ($row) {
            return ($row['applyToType'] ?? null) === 'Agreement';
        }));
    });
    if ($report['invoice_lookup']['ok'] && !empty($report['invoice_lookup']['data'][0]['id'])) {
        $invoiceId = (int) $report['invoice_lookup']['data'][0]['id'];
        $invoiceReused = true;
    }
}

// 9. Round 2 found invoice-create needs `type` and `company`. Round 3's
//    guessed reference-list endpoint (`/finance/invoices/types`) came back
//    HTTP 404. Round 4's fallback -- requesting `fields=id,type,date`
//    explicitly -- came back HTTP 200 but with a completely EMPTY row
//    list, despite this instance having ~123,761 invoices: a strong sign
//    "type" isn't actually a valid field name to request via `fields` for
//    this endpoint (ConnectWise appears to silently return zero rows
//    rather than error on an invalid requested field name here, rather
//    than 400ing the way an invalid `conditions` field usually does).
//    Round 5 fix: go back to the technique already proven to work in the
//    very first read-only probing round -- fetch a few real invoices with
//    NO `fields` restriction at all (full default shape) and read
//    whatever field actually represents "type" straight off the raw
//    response, instead of guessing a field name to request.
$invoiceTypeId = null;
if ($agreementId !== null && $invoiceId === null) {
    $report['invoice_full_sample'] = step_safe(function () {
        return register_cw_request(
            '/finance/invoices',
            ['pageSize' => '3', 'page' => '1'],
            'GET',
            null,
            25,
            8
        );
    });
    if ($report['invoice_full_sample']['ok']) {
        foreach ($report['invoice_full_sample']['data'] as $row) {
            $type = $row['type'] ?? null;
            if (is_array($type) && isset($type['id'])) {
                $invoiceTypeId = (int) $type['id'];
                break;
            }
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
