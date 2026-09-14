<?php
/**
 * register/api/returns.php
 *
 * Backs the Past Sales screen's Returns/RMA flow (added 2026-09-14): staff
 * pick parts off a past sale and start a return request.
 *
 * IMPORTANT -- this does NOT create anything in ConnectWise. Per Michael's
 * explicit decision (2026-09-14 AskUserQuestion), after a live probe of
 * ConnectWise Manage's actual REST API surface confirmed it exposes only
 * read-only RMA reference lists (RmaActions, RmaDispositions, RmaStatuses,
 * RmaTags) -- there is NO endpoint anywhere in ConnectWise's public v3.0
 * API to create or write an actual RMA record. (Company/Contact create
 * -- see customers.php -- works fine; RMA does not, at the platform level,
 * not as something this codebase could work around.) So a return request is
 * recorded HERE ONLY, queued (status='pending') for CBT's RMA team to
 * manually create the real ConnectWise RMA -- see action=mark-complete.
 *
 * Return stipulations (Michael's stated rules, shown to staff before they
 * can submit a return -- see app.js's RETURN_STIPULATIONS, kept in sync
 * with this file's docblock by hand since there's no shared-constants file
 * in this vanilla-JS/PHP codebase):
 *   1. Original packaging required.
 *   2. Within 15 days of the purchase date.
 *   3. Manufacturer defects must be listed.
 *   4. 20% restocking fee applies.
 * Nothing here enforces #1/#2/#3 programmatically (packaging condition and
 * defect description aren't things this app can verify) -- staff must
 * confirm them with the customer; the UI requires an explicit
 * acknowledgement checkbox before submitting. #4 (restocking fee) IS
 * computed and stored (20% of the returned items' total, at their original
 * sale price) so the number staff hand off matches what the RMA team enters.
 *
 * A return can never claim more of a sale_item's quantity than was
 * actually sold, minus whatever's already been claimed by an earlier
 * return request against that same sale_item (pending or completed both
 * count -- once requested, those units are spoken for until someone
 * deletes/reduces that request, which this app doesn't support; call
 * Michael if that's ever needed).
 *
 * GET /register/api/returns.php?action=returnable&sale_id=123
 *   -> { ok: true, sale: { id, created_at, cw_company_name, cw_contact_name },
 *        items: [ { sale_item_id, identifier, description, unit_price,
 *                    quantity, already_requested, returnable_qty } ] }
 *
 * POST /register/api/returns.php?action=create
 *   body: { sale_id, items: [ { sale_item_id, quantity } ], reason? }
 *   -> creates a pending return request. Every quantity is re-validated
 *      server-side against the real remaining returnable amount -- never
 *      trusts the client. items_total/restocking_fee_amount are computed
 *      from each sale_item's real stored unit_price.
 *   -> { ok: true, return: { id, sale_id, items_total,
 *          restocking_fee_amount, status, created_at, items: [...] } }
 *
 * GET /register/api/returns.php?action=list[&status=pending|completed]
 *   -> { ok: true, returns: [ { id, sale_id, created_at, cw_company_name,
 *          cw_contact_name, reason, items_total, restocking_fee_amount,
 *          status, cw_rma_number, completed_at, item_count,
 *          requested_by_name }, ... ] }   // newest first
 *
 * POST /register/api/returns.php?action=mark-complete
 *   body: { id, cw_rma_number? }
 *   -> marks a pending return 'completed' (the RMA team has now created the
 *      real RMA in ConnectWise manually) and records who/when/what RMA #.
 *   -> { ok: true, return: {...} }
 */

declare(strict_types=1);

require_once __DIR__ . '/_util.php';

register_install_error_handlers();

$pdo = register_db();
$user = register_require_login($pdo);

$action = $_GET['action'] ?? '';

if ($action === 'returnable') {
    $saleId = (int) ($_GET['sale_id'] ?? 0);
    if ($saleId <= 0) {
        register_respond(400, ['ok' => false, 'error' => 'sale_id is required.']);
    }

    $saleStmt = $pdo->prepare('SELECT id, created_at, cw_company_name, cw_contact_name FROM sales WHERE id = :id');
    $saleStmt->execute([':id' => $saleId]);
    $sale = $saleStmt->fetch(PDO::FETCH_ASSOC);
    if ($sale === false) {
        register_respond(404, ['ok' => false, 'error' => 'Sale not found.']);
    }
    $sale['id'] = (int) $sale['id'];

    register_respond(200, ['ok' => true, 'sale' => $sale, 'items' => register_returnable_items($pdo, $saleId)]);
}

if ($action === 'create') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        register_respond(405, ['ok' => false, 'error' => 'Method not allowed.']);
    }

    $data = register_read_json_body();
    $saleId = (int) ($data['sale_id'] ?? 0);
    $reason = trim((string) ($data['reason'] ?? '')) ?: null;
    $rawItems = is_array($data['items'] ?? null) ? $data['items'] : [];

    if ($saleId <= 0) {
        register_respond(400, ['ok' => false, 'error' => 'sale_id is required.']);
    }
    if ($rawItems === []) {
        register_respond(400, ['ok' => false, 'error' => 'Select at least one item to return.']);
    }

    $saleStmt = $pdo->prepare('SELECT id, cw_company_id, cw_company_name, cw_contact_id, cw_contact_name FROM sales WHERE id = :id');
    $saleStmt->execute([':id' => $saleId]);
    $sale = $saleStmt->fetch(PDO::FETCH_ASSOC);
    if ($sale === false) {
        register_respond(404, ['ok' => false, 'error' => 'Sale not found.']);
    }

    // Real returnable quantities, re-derived server-side -- never trust the
    // client's own count of what's already been requested.
    $returnable = [];
    foreach (register_returnable_items($pdo, $saleId) as $it) {
        $returnable[$it['sale_item_id']] = $it;
    }

    $toInsert = [];
    $itemsTotal = 0.0;
    foreach ($rawItems as $raw) {
        $saleItemId = (int) ($raw['sale_item_id'] ?? 0);
        $qty = (float) ($raw['quantity'] ?? 0);
        if ($saleItemId <= 0 || $qty <= 0) {
            register_respond(400, ['ok' => false, 'error' => 'Each item needs a valid sale_item_id and a positive quantity.']);
        }
        if (!isset($returnable[$saleItemId])) {
            register_respond(400, ['ok' => false, 'error' => 'One of the selected items is not part of this sale.']);
        }
        $avail = $returnable[$saleItemId];
        if ($qty > $avail['returnable_qty'] + 1e-9) {
            register_respond(400, [
                'ok' => false,
                'error' => $avail['identifier'] . ': only ' . rtrim(rtrim((string) $avail['returnable_qty'], '0'), '.') . ' still returnable.',
            ]);
        }
        $lineTotal = round($avail['unit_price'] * $qty, 2);
        $itemsTotal += $lineTotal;
        $toInsert[] = [
            'sale_item_id' => $saleItemId,
            'identifier' => $avail['identifier'],
            'description' => $avail['description'],
            'unit_price' => $avail['unit_price'],
            'quantity' => $qty,
            'line_total' => $lineTotal,
        ];
    }

    $itemsTotal = round($itemsTotal, 2);
    $restockingFee = round($itemsTotal * 0.20, 2); // 20% restocking fee, per Michael's stipulations

    $pdo->beginTransaction();
    try {
        $insertReturn = $pdo->prepare(
            'INSERT INTO returns (sale_id, user_id, cw_company_id, cw_company_name, cw_contact_id, cw_contact_name, reason, items_total, restocking_fee_amount, status)
             VALUES (:sale_id, :user_id, :cw_company_id, :cw_company_name, :cw_contact_id, :cw_contact_name, :reason, :items_total, :restocking_fee_amount, \'pending\')'
        );
        $insertReturn->execute([
            ':sale_id' => $saleId,
            ':user_id' => $user['id'],
            ':cw_company_id' => $sale['cw_company_id'],
            ':cw_company_name' => $sale['cw_company_name'],
            ':cw_contact_id' => $sale['cw_contact_id'],
            ':cw_contact_name' => $sale['cw_contact_name'],
            ':reason' => $reason,
            ':items_total' => $itemsTotal,
            ':restocking_fee_amount' => $restockingFee,
        ]);
        $returnId = (int) $pdo->lastInsertId();

        $insertItem = $pdo->prepare(
            'INSERT INTO return_items (return_id, sale_item_id, identifier, description, unit_price, quantity, line_total)
             VALUES (:return_id, :sale_item_id, :identifier, :description, :unit_price, :quantity, :line_total)'
        );
        foreach ($toInsert as $li) {
            $insertItem->execute([
                ':return_id' => $returnId,
                ':sale_item_id' => $li['sale_item_id'],
                ':identifier' => $li['identifier'],
                ':description' => $li['description'],
                ':unit_price' => $li['unit_price'],
                ':quantity' => $li['quantity'],
                ':line_total' => $li['line_total'],
            ]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        register_respond(500, ['ok' => false, 'error' => 'Could not save this return request — nothing was recorded. Try again.']);
    }

    register_respond(200, ['ok' => true, 'return' => register_load_return($pdo, $returnId)]);
}

if ($action === 'list') {
    $status = trim((string) ($_GET['status'] ?? ''));
    $sql = 'SELECT r.id, r.sale_id, r.created_at, r.cw_company_name, r.cw_contact_name, r.reason,
                   r.items_total, r.restocking_fee_amount, r.status, r.cw_rma_number, r.completed_at,
                   u.name AS requested_by_name,
                   (SELECT COUNT(*) FROM return_items ri WHERE ri.return_id = r.id) AS item_count
            FROM returns r
            JOIN register_users u ON u.id = r.user_id';
    $params = [];
    if ($status !== '') {
        $sql .= ' WHERE r.status = :status';
        $params[':status'] = $status;
    }
    $sql .= ' ORDER BY r.id DESC LIMIT 200';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $returns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($returns as &$r) {
        $r['id'] = (int) $r['id'];
        $r['sale_id'] = (int) $r['sale_id'];
        $r['items_total'] = (float) $r['items_total'];
        $r['restocking_fee_amount'] = (float) $r['restocking_fee_amount'];
        $r['item_count'] = (int) $r['item_count'];
    }
    unset($r);
    register_respond(200, ['ok' => true, 'returns' => $returns]);
}

if ($action === 'mark-complete') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        register_respond(405, ['ok' => false, 'error' => 'Method not allowed.']);
    }
    $data = register_read_json_body();
    $id = (int) ($data['id'] ?? 0);
    $rmaNumber = trim((string) ($data['cw_rma_number'] ?? '')) ?: null;
    if ($id <= 0) {
        register_respond(400, ['ok' => false, 'error' => 'id is required.']);
    }

    $check = $pdo->prepare('SELECT id FROM returns WHERE id = :id');
    $check->execute([':id' => $id]);
    if ($check->fetch() === false) {
        register_respond(404, ['ok' => false, 'error' => 'Return request not found.']);
    }

    $update = $pdo->prepare(
        "UPDATE returns SET status = 'completed', cw_rma_number = :rma, completed_at = datetime('now'), completed_by_user_id = :user_id WHERE id = :id"
    );
    $update->execute([':rma' => $rmaNumber, ':user_id' => $user['id'], ':id' => $id]);

    register_respond(200, ['ok' => true, 'return' => register_load_return($pdo, $id)]);
}

register_respond(400, ['ok' => false, 'error' => 'Unknown action.']);

/**
 * Every sale_item for $saleId, with how much of it is still returnable:
 * original quantity minus whatever's already been claimed by ANY earlier
 * return request (pending or completed) against that same sale_item.
 */
function register_returnable_items(PDO $pdo, int $saleId): array
{
    $stmt = $pdo->prepare(
        'SELECT si.id AS sale_item_id, si.identifier, si.description, si.unit_price, si.quantity,
                COALESCE((SELECT SUM(ri.quantity) FROM return_items ri WHERE ri.sale_item_id = si.id), 0) AS already_requested
         FROM sale_items si
         WHERE si.sale_id = :sale_id
         ORDER BY si.id'
    );
    $stmt->execute([':sale_id' => $saleId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$row) {
        $row['sale_item_id'] = (int) $row['sale_item_id'];
        $row['unit_price'] = (float) $row['unit_price'];
        $row['quantity'] = (float) $row['quantity'];
        $row['already_requested'] = (float) $row['already_requested'];
        $row['returnable_qty'] = max(0.0, $row['quantity'] - $row['already_requested']);
    }
    unset($row);
    return $rows;
}

function register_load_return(PDO $pdo, int $returnId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT r.id, r.sale_id, r.cw_company_id, r.cw_company_name, r.cw_contact_id, r.cw_contact_name,
                r.reason, r.items_total, r.restocking_fee_amount, r.status, r.cw_rma_number,
                r.completed_at, r.created_at, u.name AS requested_by_name
         FROM returns r JOIN register_users u ON u.id = r.user_id
         WHERE r.id = :id'
    );
    $stmt->execute([':id' => $returnId]);
    $return = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($return === false) {
        return null;
    }
    $return['id'] = (int) $return['id'];
    $return['sale_id'] = (int) $return['sale_id'];
    $return['cw_company_id'] = $return['cw_company_id'] !== null ? (int) $return['cw_company_id'] : null;
    $return['cw_contact_id'] = $return['cw_contact_id'] !== null ? (int) $return['cw_contact_id'] : null;
    $return['items_total'] = (float) $return['items_total'];
    $return['restocking_fee_amount'] = (float) $return['restocking_fee_amount'];

    $itemsStmt = $pdo->prepare('SELECT identifier, description, unit_price, quantity, line_total FROM return_items WHERE return_id = :id ORDER BY id');
    $itemsStmt->execute([':id' => $returnId]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($items as &$item) {
        $item['unit_price'] = (float) $item['unit_price'];
        $item['quantity'] = (float) $item['quantity'];
        $item['line_total'] = (float) $item['line_total'];
    }
    unset($item);
    $return['items'] = $items;

    return $return;
}
