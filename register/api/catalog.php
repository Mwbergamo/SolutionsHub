<?php
/**
 * register/api/catalog.php
 *
 * The Register app's local product catalog, synced from ConnectWise's
 * Procurement Catalog module. Confirmed 2026-09-12 via a live diagnostic
 * (relationships/api/cw-catalog-probe.php -- see that file's header for the
 * full probe history) that:
 *
 *   - /procurement/catalog items have a real `productClass` field, either
 *     "Inventory" (a physical item CBT stocks) or "NonInventory" (services,
 *     including recurring agreement line items). Michael's "On Hand"
 *     distinction -- real stocked products vs. "products we've ever sold
 *     including recurring services found on agreements" -- maps directly
 *     onto productClass='Inventory', so that's the sync's primary filter.
 *   - On-hand QUANTITY is NOT a field on the catalog item itself (fields=
 *     onHand / fields=quantityOnHand both silently return nothing --
 *     ConnectWise drops unrecognized field names rather than erroring).
 *     It lives in a separate sub-resource, /procurement/catalog/{id}/
 *     inventory, as rows of { warehouse, warehouseBin, onHand, ... } --
 *     one row per warehouse/bin the item is stocked in. A catalog item's
 *     total on-hand quantity is the SUM of `onHand` across those rows.
 *
 * GET  /register/api/catalog.php?action=list[&q=search+text][&in_stock_only=1]
 *   -> { ok: true, items: [ { id, identifier, description, category_name,
 *          subcategory_name, unit_of_measure, price, on_hand, ... }, ... ] }
 *
 * POST /register/api/catalog.php?action=sync
 *   -> { ok: true, synced: N, skipped: M }  (staff-triggered; see
 *       register_sync_catalog() below for what "skipped" means)
 *
 * Every action requires a signed-in retail staff account
 * (register_require_login()).
 */

declare(strict_types=1);

require_once __DIR__ . '/_util.php';
require_once __DIR__ . '/connectwise.php';

$pdo = register_db();
register_require_login($pdo);

$action = $_GET['action'] ?? '';

if ($action === 'list') {
    $q = trim((string) ($_GET['q'] ?? ''));
    $inStockOnly = ($_GET['in_stock_only'] ?? '') === '1';

    $sql = 'SELECT id, cw_catalog_id, identifier, description, customer_description,
                   category_name, subcategory_name, unit_of_measure, price, on_hand, synced_at
            FROM catalog_items
            WHERE inactive_flag = 0';
    $params = [];
    if ($q !== '') {
        $sql .= ' AND (identifier LIKE :q OR description LIKE :q OR customer_description LIKE :q)';
        $params[':q'] = '%' . $q . '%';
    }
    if ($inStockOnly) {
        $sql .= ' AND on_hand > 0';
    }
    $sql .= ' ORDER BY identifier COLLATE NOCASE';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($items as &$item) {
        $item['id'] = (int) $item['id'];
        $item['cw_catalog_id'] = (int) $item['cw_catalog_id'];
        $item['price'] = (float) $item['price'];
        $item['on_hand'] = (float) $item['on_hand'];
    }
    unset($item);

    register_respond(200, ['ok' => true, 'items' => $items]);
}

if ($action === 'sync') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        register_respond(405, ['ok' => false, 'error' => 'Method not allowed.']);
    }
    try {
        $result = register_sync_catalog($pdo);
        register_respond(200, ['ok' => true] + $result);
    } catch (Throwable $e) {
        register_respond(200, ['ok' => false, 'error' => $e->getMessage()]);
    }
}

register_respond(400, ['ok' => false, 'error' => 'Unknown action.']);

/**
 * Pulls every active, productClass='Inventory' Procurement Catalog item
 * from ConnectWise, sums each one's on-hand quantity across
 * /procurement/catalog/{id}/inventory, and upserts into catalog_items.
 *
 * Runs synchronously in one request -- fine for CodeBlue's current
 * inventory-item count (the vast majority of the catalog is NonInventory
 * services, per the probe's sample page). If the Inventory-only item count
 * grows enough to risk Bluehost's execution-time limit, convert this to
 * the same start()/step()-queue pattern relationships/api/sync.php already
 * uses for the much larger agreement sync -- the queue table and
 * bounded-batch loop there are a ready template.
 *
 * Returns ['synced' => N, 'skipped' => M] -- skipped counts inventory-fetch
 * failures for individual items (logged as a warning-level error string,
 * not fatal to the whole sync) so one bad item can't sink the rest.
 */
function register_sync_catalog(PDO $pdo): array
{
    $items = register_cw_list(
        '/procurement/catalog',
        "productClass='Inventory' and inactiveFlag=false",
        [
            'id', 'identifier', 'description', 'customerDescription',
            'category', 'subcategory', 'unitOfMeasure', 'price', 'cost',
            'taxableFlag', 'inactiveFlag', 'productClass',
        ]
    );

    $upsert = $pdo->prepare(
        'INSERT INTO catalog_items
            (cw_catalog_id, identifier, description, customer_description, category_name,
             subcategory_name, unit_of_measure, price, cost, on_hand, taxable_flag, inactive_flag, synced_at)
         VALUES
            (:cw_id, :identifier, :description, :customer_description, :category_name,
             :subcategory_name, :unit_of_measure, :price, :cost, :on_hand, :taxable_flag, :inactive_flag, datetime(\'now\'))
         ON CONFLICT(cw_catalog_id) DO UPDATE SET
            identifier = excluded.identifier,
            description = excluded.description,
            customer_description = excluded.customer_description,
            category_name = excluded.category_name,
            subcategory_name = excluded.subcategory_name,
            unit_of_measure = excluded.unit_of_measure,
            price = excluded.price,
            cost = excluded.cost,
            on_hand = excluded.on_hand,
            taxable_flag = excluded.taxable_flag,
            inactive_flag = excluded.inactive_flag,
            synced_at = excluded.synced_at'
    );

    $synced = 0;
    $skipped = 0;

    foreach ($items as $item) {
        $cwId = (int) ($item['id'] ?? 0);
        if ($cwId === 0) {
            $skipped++;
            continue;
        }

        try {
            $inventoryRows = register_cw_request('/procurement/catalog/' . $cwId . '/inventory');
        } catch (RegisterConnectWiseError $e) {
            // Non-fatal, same "don't let one item sink the whole sync"
            // approach as connectwise-activity-create.php's member lookup.
            $skipped++;
            continue;
        }

        $onHand = 0.0;
        foreach ($inventoryRows as $row) {
            $onHand += (float) ($row['onHand'] ?? 0);
        }

        $upsert->execute([
            ':cw_id' => $cwId,
            ':identifier' => (string) ($item['identifier'] ?? ''),
            ':description' => (string) ($item['description'] ?? ''),
            ':customer_description' => (string) ($item['customerDescription'] ?? ''),
            ':category_name' => $item['category']['name'] ?? null,
            ':subcategory_name' => $item['subcategory']['name'] ?? null,
            ':unit_of_measure' => $item['unitOfMeasure']['name'] ?? null,
            ':price' => (float) ($item['price'] ?? 0),
            ':cost' => (float) ($item['cost'] ?? 0),
            ':on_hand' => $onHand,
            ':taxable_flag' => !empty($item['taxableFlag']) ? 1 : 0,
            ':inactive_flag' => !empty($item['inactiveFlag']) ? 1 : 0,
        ]);
        $synced++;
    }

    return ['synced' => $synced, 'skipped' => $skipped];
}
