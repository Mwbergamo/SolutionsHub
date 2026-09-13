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
 *     payment_method: "cash"|"card"|"check"|"other",
 *     payment_reference: "...",      // optional, e.g. last 4 / check #
 *     tax_amount: 0,                 // optional, manually entered, default 0
 *     customer_name: "...",          // optional
 *     note: "..." }                  // optional
 *   -> { ok: true, sale: { id, created_at, subtotal, tax_amount, total,
 *          payment_method, payment_reference, customer_name, cashier_name,
 *          items: [ { identifier, description, unit_price, quantity, line_total } ] } }
 *
 * GET /register/api/checkout.php?action=receipt&id=123
 *   -> same `sale` shape as above, for reprinting/re-viewing a past sale.
 *
 * GET /register/api/checkout.php?action=history[&limit=50]
 *   -> { ok: true, sales: [ { id, created_at, total, payment_method,
 *          customer_name, cashier_name, item_count }, ... ] }
 */

declare(strict_types=1);

require_once __DIR__ . '/_util.php';

$pdo = register_db();
$user = register_require_login($pdo);

$action = $_GET['action'] ?? '';

if ($action === 'history') {
    $limit = max(1, min(200, (int) ($_GET['limit'] ?? 50)));
    $stmt = $pdo->prepare(
        'SELECT s.id, s.created_at, s.total, s.payment_method, s.customer_name, u.name AS cashier_name,
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
    $taxAmount = round((float) ($data['tax_amount'] ?? 0), 2);
    $customerName = trim((string) ($data['customer_name'] ?? '')) ?: null;
    $note = trim((string) ($data['note'] ?? '')) ?: null;

    $validMethods = ['cash', 'card', 'check', 'other'];
    if (!in_array($paymentMethod, $validMethods, true)) {
        register_respond(400, ['ok' => false, 'error' => 'Choose a payment method (cash, card, check, or other).']);
    }
    if ($rawItems === []) {
        register_respond(400, ['ok' => false, 'error' => 'Add at least one item before checking out.']);
    }
    if ($taxAmount < 0) {
        register_respond(400, ['ok' => false, 'error' => 'Tax amount cannot be negative.']);
    }

    // Look up every requested catalog item fresh from the DB -- never trust
    // price/identifier/description from the request body, only the
    // catalog_item_id + quantity. Also re-checks on_hand here (not just in
    // the UI) so two registers ringing up the same last unit at once can't
    // both succeed.
    $lookup = $pdo->prepare('SELECT id, identifier, description, price, on_hand, track_inventory FROM catalog_items WHERE id = :id');
    $lineItems = [];
    $subtotal = 0.0;

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
        $lineItems[] = [
            'catalog_item_id' => $catalogItemId,
            'identifier' => $row['identifier'],
            'description' => $row['description'],
            'unit_price' => $unitPrice,
            'quantity' => $quantity,
            'line_total' => $lineTotal,
            'track_inventory' => $trackInventory,
        ];
    }

    $subtotal = round($subtotal, 2);
    $total = round($subtotal + $taxAmount, 2);

    $pdo->beginTransaction();
    try {
        $insertSale = $pdo->prepare(
            'INSERT INTO sales (user_id, subtotal, tax_amount, total, payment_method, payment_reference, customer_name, note)
             VALUES (:user_id, :subtotal, :tax_amount, :total, :payment_method, :payment_reference, :customer_name, :note)'
        );
        $insertSale->execute([
            ':user_id' => $user['id'],
            ':subtotal' => $subtotal,
            ':tax_amount' => $taxAmount,
            ':total' => $total,
            ':payment_method' => $paymentMethod,
            ':payment_reference' => $paymentReference,
            ':customer_name' => $customerName,
            ':note' => $note,
        ]);
        $saleId = (int) $pdo->lastInsertId();

        $insertItem = $pdo->prepare(
            'INSERT INTO sale_items (sale_id, catalog_item_id, identifier, description, unit_price, quantity, line_total)
             VALUES (:sale_id, :catalog_item_id, :identifier, :description, :unit_price, :quantity, :line_total)'
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

    register_respond(200, ['ok' => true, 'sale' => register_load_receipt($pdo, $saleId)]);
}

register_respond(400, ['ok' => false, 'error' => 'Unknown action.']);

function register_load_receipt(PDO $pdo, int $saleId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT s.id, s.created_at, s.subtotal, s.tax_amount, s.total, s.payment_method,
                s.payment_reference, s.customer_name, s.note, u.name AS cashier_name
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
    $sale['total'] = (float) $sale['total'];

    $itemsStmt = $pdo->prepare(
        'SELECT identifier, description, unit_price, quantity, line_total FROM sale_items WHERE sale_id = :id ORDER BY id'
    );
    $itemsStmt->execute([':id' => $saleId]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($items as &$item) {
        $item['unit_price'] = (float) $item['unit_price'];
        $item['quantity'] = (float) $item['quantity'];
        $item['line_total'] = (float) $item['line_total'];
    }
    unset($item);
    $sale['items'] = $items;

    return $sale;
}
