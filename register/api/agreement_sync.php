<?php
/**
 * register/api/agreement_sync.php
 *
 * Task #91: "When upsold or standalone Protection plan items are sold, the
 * cashier should have the option to make those items invoiced monthly or
 * annually... when the sale is complete, the customer agreement in
 * ConnectWise will need to be created and the products should be added to
 * the agreement additions." (Michael, verbatim requirement.)
 *
 * This file is the production implementation, written from real, confirmed
 * ConnectWise field shapes and behavior -- see
 * register-agreement-billing-probe-findings.md in the attached Claude
 * Project for the full discovery process (6 rounds of a live create-test
 * against the real "Bergamo Test Account" company, id 7918, run with
 * Michael's explicit approval). Confirmed there and relied on here:
 *
 *   - Agreement Type 65 = "IT Services Agreement" (Michael's requirement:
 *     every created Agreement is always named exactly "IT Services
 *     Agreement" and uses this type).
 *   - `startDate`/`billStartDate` (Agreement) and `effectiveDate`
 *     (Addition) must be computed in CBT's own local Eastern timezone, not
 *     UTC, and billStartDate must be sent explicitly equal to startDate --
 *     see register_agreement_today_eastern() below.
 *   - `agreementStatus` auto-computes to "Active" from a correct
 *     (today-or-past) date -- no separate activation step needed.
 *   - A repeat customer's new Protection Plan purchase must be added to
 *     their EXISTING IT Services Agreement (Michael, verbatim: "If a
 *     customer has an existing IT Services Agreement, you would add the
 *     products to the existing agreement with that days date as the start
 *     date at the addition level. You would only create a new agreement if
 *     they have no prior existing IT Services Agreement.") -- see
 *     register_find_existing_it_services_agreement() below.
 *   - Agreement/Addition search conditions on this ConnectWise instance
 *     are single-field only -- compound "and" conditions silently return
 *     empty (see connectwise.php's register_cw_condition_escape()
 *     docblock) -- so the existing-agreement lookup below filters
 *     client-side in PHP rather than using a compound condition.
 *   - KNOWN, UNRESOLVED RISK (flagged prominently in the findings doc):
 *     ConnectWise's `conditions=` search index on this instance was
 *     observed to lag behind a just-written Agreement by several minutes
 *     during testing. This is very likely a non-issue for realistic
 *     repeat-purchase timing (days/weeks apart, not minutes) but has not
 *     been proven. If a customer's second-ever Protection Plan purchase
 *     ends up creating a SECOND "IT Services Agreement" instead of adding
 *     to their first, this is the first thing to check.
 *
 * OUT OF SCOPE, per Michael (2026-09-17): creating and closing the first
 * invoice. Live testing proved ConnectWise's REST API explicitly refuses
 * this ("Cannot create agreement invoices through the Invoicing API") --
 * see the findings doc's top section. Per Michael, invoicing already
 * happens through CBT's own existing process: Agreement Invoicing is run
 * by a human, payments (card/ACH) are batched and run at the end of each
 * day, and any payment collected before its invoice exists shows as a
 * positive balance on the company account until a human applies it to the
 * invoice once created. So this file's job ends at "Agreement + Addition
 * exist, correctly dated" -- it never touches invoices.
 *
 * Called from checkout.php's action=create, AFTER the sale itself has
 * already been committed to the local database -- never before, and never
 * inside that DB transaction. The money has already changed hands by the
 * time a sale reaches this step, so a ConnectWise failure here must never
 * roll back or block a completed sale -- see
 * register_sync_protection_plan_agreement()'s try/catch below, which
 * always returns normally (never throws) and reports failure via its
 * return value instead, exactly like checkout.php's own existing tax-
 * lookup fallback (`$taxWarning`).
 */

declare(strict_types=1);

require_once __DIR__ . '/connectwise.php';

const REGISTER_IT_SERVICES_AGREEMENT_TYPE_ID = 65;
const REGISTER_IT_SERVICES_AGREEMENT_NAME = 'IT Services Agreement';
const REGISTER_AGREEMENT_BILLING_TERMS_ID = 3; // "Due Upon Receipt" -- confirmed on every real IT Services Agreement sample.

// Confirmed real, live values on this ConnectWise instance (see findings
// doc's "Confirmed: real Agreement field shapes" section). Keyed by the
// same 'monthly'/'annual' strings used in checkout.php's request body and
// app.js's billing-cycle picker.
const REGISTER_BILLING_CYCLE_IDS = [
    'monthly' => 2,
    'annual' => 6,
];

/**
 * Today's date in CBT's own local timezone (Eastern -- Richmond, VA), NOT
 * UTC, formatted the way ConnectWise's Agreement/Addition date fields
 * expect. Confirmed critical during probing: a UTC "today" computed after
 * ~8pm Eastern (7pm during EST) lands on the NEXT calendar day, which then
 * blocks ConnectWise from being able to generate an invoice against that
 * Agreement/Addition later. Per Michael: "Both need to start at the moment
 * of sale or CWM won't be able to generate an invoice."
 */
function register_agreement_today_eastern(): string
{
    return (new DateTimeImmutable('today', new DateTimeZone('America/New_York')))->format('Y-m-d') . 'T00:00:00Z';
}

/**
 * The ConnectWise "Main" site id for a Company -- required as a plain
 * {id:N} reference on Agreement create. Every Company this app creates
 * gets exactly one site, always named "Main" (see customers.php's
 * confirmed `site => ['name' => 'Main']` create payload), but this looks
 * it up live rather than assuming a specific id, since an existing
 * ConnectWise Company (created outside this app, e.g. by CBT staff
 * directly in ConnectWise) is not guaranteed to follow that same
 * convention. Falls back to the first site returned if none is literally
 * named "Main".
 */
function register_company_main_site_id(int $companyId): int
{
    $sites = register_cw_request(
        '/company/companies/' . $companyId . '/sites',
        ['pageSize' => '10', 'page' => '1'],
        'GET',
        null,
        20,
        8
    );
    foreach ($sites as $site) {
        if (strcasecmp((string) ($site['name'] ?? ''), 'Main') === 0 && !empty($site['id'])) {
            return (int) $site['id'];
        }
    }
    if (!empty($sites[0]['id'])) {
        return (int) $sites[0]['id'];
    }
    throw new RegisterConnectWiseError('Company ' . $companyId . ' has no ConnectWise site to attach the Agreement to.');
}

/**
 * Looks for an existing "IT Services Agreement" (type id 65) on this
 * Company. Single-field condition + client-side filtering, per this
 * project's established "compound conditions aren't reliable on this
 * ConnectWise instance" rule (see connectwise.php). If more than one
 * somehow exists (e.g. leftover test data, or a past bug), picks the
 * highest id -- the most recently created -- as canonical, same
 * deterministic tie-break used throughout this feature's own test script.
 * Returns null if none found.
 */
function register_find_existing_it_services_agreement(int $companyId): ?int
{
    $rows = register_cw_request(
        '/finance/agreements',
        ['conditions' => 'company/id=' . $companyId, 'pageSize' => '50', 'page' => '1'],
        'GET',
        null,
        20,
        8
    );
    $matches = array_values(array_filter($rows, function ($row) {
        return (int) ($row['type']['id'] ?? 0) === REGISTER_IT_SERVICES_AGREEMENT_TYPE_ID;
    }));
    if ($matches === []) {
        return null;
    }
    usort($matches, function ($a, $b) {
        return ((int) ($b['id'] ?? 0)) <=> ((int) ($a['id'] ?? 0));
    });
    return (int) $matches[0]['id'];
}

/**
 * Creates a brand-new "IT Services Agreement" -- only called when
 * register_find_existing_it_services_agreement() found none. Payload
 * confirmed end-to-end across two independent clean live-test runs (see
 * findings doc). $billingCycleChoice is 'monthly' or 'annual' as chosen by
 * the cashier at checkout; an unrecognized value defaults to monthly
 * rather than failing the whole sale over a bad value.
 */
function register_create_it_services_agreement(int $companyId, int $contactId, string $billingCycleChoice, string $today): array
{
    $cycleId = REGISTER_BILLING_CYCLE_IDS[$billingCycleChoice] ?? REGISTER_BILLING_CYCLE_IDS['monthly'];
    $siteId = register_company_main_site_id($companyId);
    return register_cw_request(
        '/finance/agreements',
        [],
        'POST',
        [
            'name' => REGISTER_IT_SERVICES_AGREEMENT_NAME,
            'type' => ['id' => REGISTER_IT_SERVICES_AGREEMENT_TYPE_ID],
            'company' => ['id' => $companyId],
            'contact' => ['id' => $contactId],
            'site' => ['id' => $siteId],
            'startDate' => $today,
            // Confirmed required explicitly -- ConnectWise auto-defaults
            // this using its own (not Eastern-corrected) "today" if left
            // off, which can drift a day ahead of a correctly-computed
            // startDate and then block the Addition create below.
            'billStartDate' => $today,
            'noEndingDateFlag' => true,
            'billingCycle' => ['id' => $cycleId],
            'billingTerms' => ['id' => REGISTER_AGREEMENT_BILLING_TERMS_ID],
        ],
        25,
        8
    );
}

/**
 * Adds one sold Protection Plan item as an Agreement Addition.
 * `effectiveDate` is always the date of THIS sale (today, Eastern) -- even
 * when adding to an older, pre-existing Agreement, per Michael's
 * confirmed design. `cancelledDate` is deliberately never sent: its
 * absence is what "no end date" means for an Addition (confirmed live --
 * there's no separate noEndingDateFlag the way Agreements have).
 */
function register_add_agreement_addition(int $agreementId, int $catalogProductId, float $quantity, string $today): array
{
    return register_cw_request(
        '/finance/agreements/' . $agreementId . '/additions',
        [],
        'POST',
        [
            'product' => ['id' => $catalogProductId],
            'quantity' => $quantity,
            'billCustomer' => 'Billable',
            'effectiveDate' => $today,
        ],
        25,
        8
    );
}

/**
 * Top-level entry point, called once per checkout from checkout.php's
 * action=create -- AFTER the sale is already committed to the local
 * database. $protectionPlanLines is a list of
 * ['catalog_item_id' => int (this app's own id, for matching the result
 * back to a sale_item row), 'cw_catalog_id' => int (the real ConnectWise
 * Procurement Catalog product id -- what the Addition's `product.id`
 * needs), 'quantity' => float, 'identifier' => string (for error
 * messages)] -- one entry per distinct Protection Plan catalog item in
 * this sale (checkout.php's cart is already deduped by catalog_item_id, so
 * there's exactly one entry per item regardless of how the quantity got
 * there).
 *
 * NEVER throws. Every ConnectWise call is wrapped in one try/catch, and
 * any failure -- at any step -- is reported back via the return value's
 * `error`, not an exception, so a ConnectWise outage or a real validation
 * error never undoes or blocks the sale that already completed. This
 * mirrors checkout.php's own existing tax-lookup fallback pattern
 * ($taxWarning), just for a step that needs an actual human follow-up in
 * ConnectWise rather than only a heads-up on the receipt.
 *
 * Returns:
 *   ok: bool -- true only if every step (agreement + every addition)
 *       succeeded.
 *   agreement_id: ?int -- the Agreement additions were added to, or null
 *       if nothing was attempted ($protectionPlanLines was empty) or the
 *       very first step (find-or-create) failed.
 *   agreement_reused: ?bool -- true if an existing Agreement was reused,
 *       false if a new one was created, null if nothing was attempted.
 *   addition_ids: array<int, int> -- catalog_item_id => real ConnectWise
 *       Addition id, for every item that succeeded (may be a partial list
 *       if an earlier item in the same sale succeeded before a later one
 *       failed).
 *   error: ?string -- human-readable description of what failed, or null
 *       on full success.
 */
function register_sync_protection_plan_agreement(int $companyId, int $contactId, string $billingCycleChoice, array $protectionPlanLines): array
{
    if ($protectionPlanLines === []) {
        return ['ok' => true, 'agreement_id' => null, 'agreement_reused' => null, 'addition_ids' => [], 'error' => null];
    }

    $today = register_agreement_today_eastern();
    $additionIds = [];

    try {
        $agreementId = register_find_existing_it_services_agreement($companyId);
        $reused = $agreementId !== null;
        if ($agreementId === null) {
            $created = register_create_it_services_agreement($companyId, $contactId, $billingCycleChoice, $today);
            if (empty($created['id'])) {
                throw new RegisterConnectWiseError('ConnectWise did not return an id for the new Agreement.');
            }
            $agreementId = (int) $created['id'];
        }

        foreach ($protectionPlanLines as $line) {
            $addition = register_add_agreement_addition($agreementId, (int) $line['cw_catalog_id'], (float) $line['quantity'], $today);
            if (empty($addition['id'])) {
                throw new RegisterConnectWiseError('ConnectWise did not return an id for the Addition for "' . $line['identifier'] . '".');
            }
            $additionIds[(int) $line['catalog_item_id']] = (int) $addition['id'];
        }

        return ['ok' => true, 'agreement_id' => $agreementId, 'agreement_reused' => $reused, 'addition_ids' => $additionIds, 'error' => null];
    } catch (Throwable $e) {
        return [
            'ok' => false,
            'agreement_id' => $agreementId ?? null,
            'agreement_reused' => $reused ?? null,
            'addition_ids' => $additionIds,
            'error' => $e->getMessage(),
        ];
    }
}
