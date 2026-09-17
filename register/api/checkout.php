<?php
/**
 * register/api/checkout.php
 *
 * Records a completed sale: ring up items, record how payment was taken
 * (manually -- no live card processing per Michael's explicit choice),
 * and return the data needed to print/display a receipt. Also decrements
 * this app's own locally-synced on_hand counts so the register's displayed
 * stock stays roughly accurate between syncs.
 *
 * KNOWN LIMITATION: this does NOT write the sale back to ConnectWise as an
 * inventory adjustment -- that's a real ConnectWise write (like the
 * Activity-creation feature) that would need its own real-field-name
 * discovery pass (almost certainly /procurement/adjustments, unconfirmed)
 * before it could be built safely. Today, a register sale only affects
 * this app's local on_hand; the next catalog sync overwrites it back to
 * whatever ConnectWise reports. Flagged for Michael to decide whether/when
 * to build that.
 *
 * POST /register/api/checkout.php?action=create
 *   { items: [ { catalog_item_id, quantity } ],
 *     payment_method: "card"|"check"|"other", // "cash" removed 2026-09-16 per Michael
 *     payment_reference: "...",      // optional, e.g. last 4 / check #
 *     cw_company_id, cw_company_name,  // REQUIRED -- see below
 *     cw_contact_id, cw_contact_name,  // REQUIRED -- see below
 *     billing_cycle: "monthly"|"annual", // optional, default "monthly" -- see below
 *     note: "..." }                  // optional
 *   -> { ok: true, sale: { id, created_at, subtotal, tax_amount, tax_rate,
 *          tax_code_id, tax_code_identifier, total, payment_method,
 *          payment_reference, cw_company_id, cw_company_name, cw_contact_id,
 *          cw_contact_name, customer_name, cashier_name, cw_agreement_id,
 *          cw_billing_cycle, agreement_warning,
 *          items: [ { identifier, description, unit_price, quantity,
 *            line_total, is_protection_plan, cw_addition_id } ] } }
 *
 * Sales tax (added 2026-09-16) is computed server-side, never trusted from
 * the client: only catalog_items.taxable_flag=1 line items count toward the
 * taxable subtotal, and the rate comes from a LIVE lookup of the Company's
 * assigned ConnectWise Tax Code (register_cw_company_tax_code_id(),
 * tax-core.php), joined against the locally-synced tax_codes table for the
 * identifier/rate. If that live lookup fails (network error) or returns a
 * code ConnectWise has that hasn't been synced locally yet, this falls back
 * to the register's default tax code's rate (VA-STATE, 6%) rather than $0 --
 * safer to over-collect than under-collect for tax compliance, consistent
 * with this app's existing fail-open-with-a-warning pattern elsewhere. Any
 * fallback is flagged in the response as `tax_warning`.
 *
 * cw_company_id/cw_contact_id (both a real ConnectWise Company id and a
 * real ConnectWise Contact id) are REQUIRED, per Michael's explicit
 * choice (2026-09-14 AskUserQuestion): no free-text/walk-in fallback --
 * every sale must resolve to a real Company AND Contact via
 * api/customers.php's search/create/finalize-invoicing endpoints before
 * checkout can complete. The old free-text customer_name column still
 * exists (for pre-2026-09-14 sale history) but is no longer written.
 *
 * Protection Plan items (task #91, added 2026-09-17 -- see
 * agreement_sync.php and register-agreement-billing-probe-findings.md in
 * the attached Claude Project for the full field-shape discovery process):
 * any line item that is Agreement-class (catalog_items.track_inventory=0,
 * `is_protection_plan` below -- the same flag the Metrics screen's
 * "Protection Plan Sales" count already uses) gets added as a real
 * ConnectWise Agreement Addition once this sale completes, on the
 * customer's "IT Services Agreement" (created if they don't already have
 * one, reused if they do -- see agreement_sync.php). `billing_cycle`
 * ('monthly' or 'annual', chosen by the cashier once per sale -- a
 * ConnectWise Agreement has only one billing cycle for everything on it,
 * never per-item) ONLY affects a brand-new Agreement's cycle; it has no
 * effect when reusing an existing one, which keeps whatever cycle it was
 * originally created with. Per Michael's explicit pricing decision: when
 * `billing_cycle` is "annual", each Protection Plan line item's
 * `line_total` charged today is 12x its catalog price x quantity (a full
 * year collected upfront at the register); "monthly" charges the plain
 * catalog price, same as any other item, with future months billed
 * through the Agreement itself. This multiplier is computed server-side,
 * same "never trust the client's math" rule as tax -- see the
 * `$cycleMultiplier` line below. This ConnectWise sync step NEVER blocks
 * or rolls back the sale (money has already changed hands by the time it
 * runs) -- any failure is recorded as `agreement_warning` on the sale row
 * and in the response, for manual follow-up in ConnectWise, exactly like
 * `tax_warning` already works for a failed tax lookup.
 *
 * OUT OF SCOPE, per Michael (2026-09-17): this does NOT create or close an
 * invoice. Live testing proved ConnectWise's REST API explicitly refuses
 * to create Agreement invoices at all ("Cannot create agreement invoices
 * through the Invoicing API") -- see the findings doc. Per Michael, CBT's
 * own existing process handles this downstream: a human runs Agreement
 * Invoicing, and card/ACH payments are batched and run at the end of each
 * day, applied to invoices once they exist.
 *
 * Service Class items (revised 2026-09-17, superseding this file's earlier
 * REGISTER_FLAT_FEE_OVERRIDES hardcoded-price approach): ConnectWise's real
 * `productClass` field has three values -- 'Inventory' (physical stock),
 * 'Agreement' (recurring managed services), and 'Service' (one-time-fee
 * items, e.g. '9999' "System Prep"). All three now sync into catalog_items
 * (see catalog.php's 'list_service' sync stage), each tagged with its real
 * `product_class`. Classification here is now a direct
 * `product_class === 'Agreement'` check instead of the old `!track_inventory`
 * proxy, which wrongly treated every non-inventoried item (both Agreement
 * AND Service class) as a Protection Plan. Per Michael ("we have many
 * Service Class Items... that we should be able to add"), Service items are
 * priced from the real synced catalog_items.price like any other item --
 * no more per-identifier fixed-price override -- and are excluded from
 * `is_protection_plan`, the annual/monthly cycle multiplier, and the
 * Agreement/Addition sync (agreement_sync.php), same as before.
 *
 * GET /register/api/checkout.php?action=receipt&id=123
 *   -> same `sale` shape as above, for reprinting/re-viewing a past sale.
 *
 * GET /register/api/checkout.php?action=history[&limit=50]
 *   -> { ok: true, sales: [ { id, created_at, total, payment_method,
 *          cw_company_name, cw_contact_name, customer_name, cashier_name,
 *          item_count }, ... ] }
 */

declare(strict_types=1);

require_once __DIR__ . '/_util.php';
require_once __DIR__ . '/connectwise.php';
require_once __DIR__ . '/tax-core.php';
require_once __DIR__ . '/agreement_sync.php';

register_install_error_handlers();

$pdo = register_db();
$user = register_require_login($pdo);

$action = $_GET['action'] ?? '';

if ($action === 'history') {
    $limit = max(1, min(200, (int) ($_GET['limit'] ?? 50)));
    $stmt = $pdo->prepare(
        'SELECT s.id, s.created_at, s.total, s.payment_method, s.customer_name,
                s.cw_company_name, s.cw_contact_name, u.name AS cashier_name,
                (SELECT COUNT(*) FROM sale_items si WHERE si.sale_id = s.id) AS item_count
         FROM sales s
         JOIN register_users u ON u.id = s.user_id
         ORDER BY s.id DESC
         LIMIT :limit'
    );
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $sales = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($sales as &$sale) {
        $sale['id'] = (int) $sale['id'];
        $sale['total'] = (float) $sale['total'];
        $sale['item_count'] = (int) $sale['item_count'];
    }
    unset($sale);
    register_respond(200, ['ok' => true, 'sales' => $sales]);
}

if ($action === 'receipt') {
    $id = (int) ($_GET['id'] ?? 0);
    $sale = register_load_receipt($pdo, $id);
    if ($sale === null) {
        register_respond(404, ['ok' => false, 'error' => 'Sale not found.']);
    }
    register_respond(200, ['ok' => true, 'sale' => $sale]);
}

if ($action === 'create') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        register_respond(405, ['ok' => false, 'error' => 'Method not allowed.']);
    }

    $data = register_read_json_body();
    $rawItems = is_array($data['items'] ?? null) ? $data['items'] : [];
    $paymentMethod = strtolower(trim((string) ($data['payment_method'] ?? '')));
    $paymentReference = trim((string) ($data['payment_reference'] ?? '')) ?: null;
    $note = trim((string) ($data['note'] ?? '')) ?: null;

    // No free-text/walk-in fallback (Michael's explicit choice,
    // 2026-09-14 AskUserQuestion) -- every sale must resolve to a real
    // ConnectWise Company AND Contact via api/customers.php before
    // checkout can complete.
    $cwCompanyId = isset($data['cw_company_id']) ? (int) $data['cw_company_id'] : 0;
    $cwCompanyName = trim((string) ($data['cw_company_name'] ?? ''));
    $cwContactId = isset($data['cw_contact_id']) ? (int) $data['cw_contact_id'] : 0;
    $cwContactName = trim((string) ($data['cw_contact_name'] ?? ''));

    // Task #91 -- one Monthly/Annual choice for the whole sale (a
    // ConnectWise Agreement has a single billing cycle, never per-item --
    // see this file's docblock). Any unrecognized/missing value quietly
    // defaults to monthly rather than failing the sale over a bad value;
    // it's meaningless anyway unless this sale actually has a Protection
    // Plan item in it.
    $billingCycle = strtolower(trim((string) ($data['billing_cycle'] ?? 'monthly')));
    if (!in_array($billingCycle, ['monthly', 'annual'], true)) {
        $billingCycle = 'monthly';
    }

    $validMethods = ['card', 'check', 'other'];
    if (!in_array($paymentMethod, $validMethods, true)) {
        register_respond(400, ['ok' => false, 'error' => 'Choose a payment method (card, check, or other).']);
    }
    if ($rawItems === []) {
        register_respond(400, ['ok' => false, 'error' => 'Add at least one item before checking out.']);
    }
    if ($cwCompanyId <= 0 || $cwCompanyName === '' || $cwContactId <= 0 || $cwContactName === '') {
        register_respond(400, ['ok' => false, 'error' => 'Select or create a Company and Contact before completing this sale.']);
    }

    // Look up every requested catalog item fresh from the DB -- never trust
    // price/identifier/description from the request body, only the
    // catalog_item_id + quantity. Also re-checks on_hand here (not just in
    // the UI) so two registers ringing up the same last unit at once can't
    // both succeed.
    $lookup = $pdo->prepare('SELECT id, cw_catalog_id, identifier, description, price, on_hand, track_inventory, taxable_flag, product_class FROM catalog_items WHERE id = :id');
    $lineItems = [];
    $subtotal = 0.0;
    $taxableSubtotal = 0.0;
    // Task #91 -- every Protection Plan (Agreement-class) line item in
    // this sale, collected as we go so the ConnectWise Agreement/Addition
    // sync (after the sale is committed, below) has exactly what it needs
    // without a second pass over the catalog.
    $protectionPlanLines = [];

    foreach ($rawItems as $raw) {
        $catalogItemId = (int) ($raw['catalog_item_id'] ?? 0);
        $quantity = (float) ($raw['quantity'] ?? 0);
        if ($catalogItemId <= 0 || $quantity <= 0) {
            register_respond(400, ['ok' => false, 'error' => 'Each item needs a valid catalog_item_id and a positive quantity.']);
        }

        $lookup->execute([':id' => $catalogItemId]);
        $row = $lookup->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            register_respond(400, ['ok' => false, 'error' => 'One of the items in this sale is no longer in the catalog — remove it and try again.']);
        }
        $trackInventory = (int) $row['track_inventory'] === 1;
        // Agreement-class items (recurring-protection products rung up as
        // a plain line item, per Michael -- no real ConnectWise Agreement
        // is created) have no physical stock, so there's nothing to check
        // or decrement for them.
        if ($trackInventory && $quantity > (float) $row['on_hand']) {
            register_respond(400, [
                'ok' => false,
                'error' => $row['identifier'] . ' only has ' . rtrim(rtrim((string) $row['on_hand'], '0'), '.') . ' on hand.',
            ]);
        }

        // Classification (revised 2026-09-17): the real ConnectWise
        // productClass, synced into catalog_items.product_class, is what
        // actually determines "is this a recurring Protection Plan item" --
        // NOT the `!track_inventory` proxy this used to use, which wrongly
        // caught Service-class (one-time-fee) items too, since neither
        // Agreement nor Service items track physical stock. See this file's
        // header docblock ("Service Class items").
        $isProtectionPlan = ($row['product_class'] ?? null) === 'Agreement';
        // Task #91: an "annual" sale charges a Protection Plan item's full
        // year upfront at the register (12x catalog price x quantity)
        // instead of the plain catalog price a "monthly" sale charges --
        // Michael's explicit pricing decision. Every other item (including
        // a "monthly" Protection Plan item, and any Service-class item) is
        // unaffected by billing_cycle. Computed server-side, never trusted
        // from the client, same as the rest of this pricing block.
        $cycleMultiplier = ($isProtectionPlan && $billingCycle === 'annual') ? 12 : 1;
        // Always the real synced catalog price -- no per-identifier
        // fixed-price override any more (see header docblock).
        $unitPrice = (float) $row['price'];
        $lineTotal = round($unitPrice * $quantity * $cycleMultiplier, 2);
        $subtotal += $lineTotal;
        $taxable = (int) ($row['taxable_flag'] ?? 0) === 1;
        if ($taxable) {
            $taxableSubtotal += $lineTotal;
        }
        $lineItems[] = [
            'catalog_item_id' => $catalogItemId,
            'identifier' => $row['identifier'],
            'description' => $row['description'],
            'unit_price' => $unitPrice,
            'quantity' => $quantity,
            'line_total' => $lineTotal,
            'track_inventory' => $trackInventory,
            // Agreement-class items (track_inventory=0) are CBT's recurring-
            // protection/managed-service products -- copied onto the row now
            // so the Metrics screen's "Protection Plan Sales" count never
            // depends on catalog_items still classifying this item the same
            // way later. See db.php's sale_items migration comment.
            'is_protection_plan' => $isProtectionPlan,
        ];

        if ($isProtectionPlan) {
            $protectionPlanLines[] = [
                'catalog_item_id' => $catalogItemId,
                'cw_catalog_id' => (int) $row['cw_catalog_id'],
                'quantity' => $quantity,
                'identifier' => $row['identifier'],
            ];
        }
    }

    $subtotal = round($subtotal, 2);
    $taxableSubtotal = round($taxableSubtotal, 2);

    // Resolve the tax rate to charge -- LIVE from ConnectWise's Company
    // record, never trusted from the client. Falls back to the register's
    // default tax code (VA-STATE, 6%) on any failure: a failed live lookup
    // (network error) or a Company tax code that ConnectWise has but this
    // app hasn't synced locally yet. See this file's docblock and
    // tax-core.php for the full rationale.
    $taxWarning = null;
    $taxCodeRow = null;
    try {
        $liveTaxCode = register_cw_company_tax_code_id($cwCompanyId);
        if ($liveTaxCode !== null) {
            $taxCodeRow = register_tax_code_by_id($pdo, $liveTaxCode['id']);
            if ($taxCodeRow === null) {
                $taxWarning = 'ConnectWise has a tax code for this customer that has not been synced locally yet -- used the default rate instead. Run a tax code sync and re-check this sale.';
            }
        }
    } catch (Throwable $e) {
        $taxWarning = 'Could not reach ConnectWise to look up this customer\'s tax status -- used the default rate instead. Verify this sale\'s tax once ConnectWise is reachable.';
    }
    if ($taxCodeRow === null) {
        $taxCodeRow = register_default_tax_code($pdo);
        if ($taxCodeRow === null && $taxWarning === null) {
            $taxWarning = 'No tax codes have been synced from ConnectWise yet -- this sale was recorded with $0 tax. Sync tax codes and re-check this sale.';
        }
    }
    $taxRate = $taxCodeRow['rate'] ?? 0.0;
    $taxAmount = round($taxableSubtotal * $taxRate, 2);
    $total = round($subtotal + $taxAmount, 2);

    // Task #91: cw_billing_cycle is recorded on every sale that has at
    // least one Protection Plan item, whether or not the ConnectWise sync
    // below succeeds -- it reflects what the cashier chose and what the
    // customer was actually charged, independent of that sync's outcome.
    // Null when there's nothing for it to describe.
    $saleBillingCycle = $protectionPlanLines !== [] ? $billingCycle : null;

    $pdo->beginTransaction();
    try {
        $insertSale = $pdo->prepare(
            'INSERT INTO sales (user_id, subtotal, tax_amount, tax_code_id, tax_code_identifier, tax_rate, total, payment_method, payment_reference, cw_company_id, cw_company_name, cw_contact_id, cw_contact_name, cw_billing_cycle, note)
             VALUES (:user_id, :subtotal, :tax_amount, :tax_code_id, :tax_code_identifier, :tax_rate, :total, :payment_method, :payment_reference, :cw_company_id, :cw_company_name, :cw_contact_id, :cw_contact_name, :cw_billing_cycle, :note)'
        );
        $insertSale->execute([
            ':user_id' => $user['id'],
            ':subtotal' => $subtotal,
            ':tax_amount' => $taxAmount,
            ':tax_code_id' => $taxCodeRow['id'] ?? null,
            ':tax_code_identifier' => $taxCodeRow['identifier'] ?? null,
            ':tax_rate' => $taxRate,
            ':total' => $total,
            ':payment_method' => $paymentMethod,
            ':payment_reference' => $paymentReference,
            ':cw_company_id' => $cwCompanyId,
            ':cw_company_name' => $cwCompanyName,
            ':cw_contact_id' => $cwContactId,
            ':cw_contact_name' => $cwContactName,
            ':cw_billing_cycle' => $saleBillingCycle,
            ':note' => $note,
        ]);
        $saleId = (int) $pdo->lastInsertId();

        $insertItem = $pdo->prepare(
            'INSERT INTO sale_items (sale_id, catalog_item_id, identifier, description, unit_price, quantity, line_total, is_protection_plan)
             VALUES (:sale_id, :catalog_item_id, :identifier, :description, :unit_price, :quantity, :line_total, :is_protection_plan)'
        );
        $decrement = $pdo->prepare('UPDATE catalog_items SET on_hand = on_hand - :qty WHERE id = :id');

        foreach ($lineItems as $li) {
            $insertItem->execute([
                ':sale_id' => $saleId,
                ':catalog_item_id' => $li['catalog_item_id'],
                ':identifier' => $li['identifier'],
                ':description' => $li['description'],
                ':unit_price' => $li['unit_price'],
                ':quantity' => $li['quantity'],
                ':line_total' => $li['line_total'],
                ':is_protection_plan' => $li['is_protection_plan'] ? 1 : 0,
            ]);
            if ($li['track_inventory']) {
                $decrement->execute([':qty' => $li['quantity'], ':id' => $li['catalog_item_id']]);
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        register_respond(500, ['ok' => false, 'error' => 'Could not save this sale — nothing was charged or recorded. Try again.']);
    }

    // Task #91: the ConnectWise Agreement/Addition sync happens here, AFTER
    // the sale is already committed -- deliberately outside the DB
    // transaction above and never able to undo it. The customer has
    // already been charged by this point, so a ConnectWise failure here
    // (network error, a real validation error, anything) must only ever
    // produce a warning for a human to follow up on, never fail or roll
    // back a sale that already happened. See agreement_sync.php's own
    // docblock for the full reasoning and the confirmed field shapes this
    // is built from.
    $agreementWarning = null;
    if ($protectionPlanLines !== []) {
        $agreementResult = register_sync_protection_plan_agreement($cwCompanyId, $cwContactId, $billingCycle, $protectionPlanLines);
        if (!$agreementResult['ok']) {
            $agreementWarning = 'Could not sync this sale\'s Protection Plan item(s) to ConnectWise'
                . ($agreementResult['agreement_id'] !== null ? ' (Agreement #' . $agreementResult['agreement_id'] . ')' : '')
                . ' -- ' . $agreementResult['error'] . '. Add the item(s) to the customer\'s IT Services Agreement manually in ConnectWise.';
        }

        $updateSale = $pdo->prepare('UPDATE sales SET cw_agreement_id = :aid, agreement_warning = :warn WHERE id = :id');
        $updateSale->execute([
            ':aid' => $agreementResult['agreement_id'],
            ':warn' => $agreementWarning,
            ':id' => $saleId,
        ]);

        if ($agreementResult['addition_ids'] !== []) {
            $updateAddition = $pdo->prepare('UPDATE sale_items SET cw_addition_id = :addition_id WHERE sale_id = :sale_id AND catalog_item_id = :catalog_item_id');
            foreach ($agreementResult['addition_ids'] as $catalogItemId => $additionId) {
                $updateAddition->execute([
                    ':addition_id' => $additionId,
                    ':sale_id' => $saleId,
                    ':catalog_item_id' => $catalogItemId,
                ]);
            }
        }
    }

    $response = ['ok' => true, 'sale' => register_load_receipt($pdo, $saleId)];
    if ($taxWarning !== null) {
        $response['tax_warning'] = $taxWarning;
    }
    if ($agreementWarning !== null) {
        $response['agreement_warning'] = $agreementWarning;
    }
    register_respond(200, $response);
}

register_respond(400, ['ok' => false, 'error' => 'Unknown action.']);

function register_load_receipt(PDO $pdo, int $saleId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT s.id, s.created_at, s.subtotal, s.tax_amount, s.tax_code_id, s.tax_code_identifier,
                s.tax_rate, s.total, s.payment_method,
                s.payment_reference, s.customer_name, s.cw_company_id, s.cw_company_name,
                s.cw_contact_id, s.cw_contact_name, s.cw_agreement_id, s.cw_billing_cycle,
                s.agreement_warning, s.note, u.name AS cashier_name
         FROM sales s
         JOIN register_users u ON u.id = s.user_id
         WHERE s.id = :id'
    );
    $stmt->execute([':id' => $saleId]);
    $sale = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($sale === false) {
        return null;
    }
    $sale['id'] = (int) $sale['id'];
    $sale['subtotal'] = (float) $sale['subtotal'];
    $sale['tax_amount'] = (float) $sale['tax_amount'];
    $sale['tax_code_id'] = $sale['tax_code_id'] !== null ? (int) $sale['tax_code_id'] : null;
    $sale['tax_rate'] = $sale['tax_rate'] !== null ? (float) $sale['tax_rate'] : null;
    $sale['total'] = (float) $sale['total'];
    $sale['cw_company_id'] = $sale['cw_company_id'] !== null ? (int) $sale['cw_company_id'] : null;
    $sale['cw_contact_id'] = $sale['cw_contact_id'] !== null ? (int) $sale['cw_contact_id'] : null;
    $sale['cw_agreement_id'] = $sale['cw_agreement_id'] !== null ? (int) $sale['cw_agreement_id'] : null;

    $itemsStmt = $pdo->prepare(
        'SELECT id AS sale_item_id, identifier, description, unit_price, quantity, line_total, is_protection_plan, cw_addition_id
         FROM sale_items WHERE sale_id = :id ORDER BY id'
    );
    $itemsStmt->execute([':id' => $saleId]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($items as &$item) {
        $item['sale_item_id'] = (int) $item['sale_item_id'];
        $item['unit_price'] = (float) $item['unit_price'];
        $item['quantity'] = (float) $item['quantity'];
        $item['line_total'] = (float) $item['line_total'];
        $item['is_protection_plan'] = (int) $item['is_protection_plan'] === 1;
        $item['cw_addition_id'] = $item['cw_addition_id'] !== null ? (int) $item['cw_addition_id'] : null;
    }
    unset($item);
    $sale['items'] = $items;

    return $sale;
}
