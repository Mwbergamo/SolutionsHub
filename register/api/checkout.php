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
 *     note: "..." }                  // optional
 *   -> { ok: true, sale: { id, created_at, subtotal, tax_amount, tax_rate,
 *          tax_code_id, tax_code_identifier, total, payment_method,
 *          payment_reference, cw_company_id, cw_company_name, cw_contact_id,
 *          cw_contact_name, customer_name, cashier_name,
 *          items: [ { identifier, description, unit_price, quantity, line_total } ] } }
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
    $lookup = $pdo->prepare('SELECT id, identifier, description, price, on_hand, track_inventory, taxable_flag FROM catalog_items WHERE id = :id');
    $lineItems = [];
    $subtotal = 0.0;
    $taxableSubtotal = 0.0;

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

        $unitPrice = (float) $row['price'];
        $lineTotal = round($unitPrice * $quantity, 2);
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
            'is_protection_plan' => !$trackInventory,
        ];
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

    $pdo->beginTransaction();
    try {
        $insertSale = $pdo->prepare(
            'INSERT INTO sales (user_id, subtotal, tax_amount, tax_code_id, tax_code_identifier, tax_rate, total, payment_method, payment_reference, cw_company_id, cw_company_name, cw_contact_id, cw_contact_name, note)
             VALUES (:user_id, :subtotal, :tax_amount, :tax_code_id, :tax_code_identifier, :tax_rate, :total, :payment_method, :payment_reference, :cw_company_id, :cw_company_name, :cw_contact_id, :cw_contact_name, :note)'
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

    $response = ['ok' => true, 'sale' => register_load_receipt($pdo, $saleId)];
    if ($taxWarning !== null) {
        $response['tax_warning'] = $taxWarning;
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
                s.cw_contact_id, s.cw_contact_name, s.note, u.name AS cashier_name
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

    $itemsStmt = $pdo->prepare(
        'SELECT id AS sale_item_id, identifier, description, unit_price, quantity, line_total, is_protection_plan
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
    }
    unset($item);
    $sale['items'] = $items;

    return $sale;
}
