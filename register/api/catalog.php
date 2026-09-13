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
 * The sync is a queue-based start()/step() pair (added 2026-09-12 after a
 * real "Sync failed — check your connection" error on the first live
 * attempt, which ran ~4 minutes before failing) -- same shape as
 * relationships/api/sync.php's much larger agreement sync, for the same
 * reason: one full-item GET + one /inventory GET per catalog item, done
 * synchronously in a single request, was always going to risk Bluehost's
 * execution-time limit once there's more than a handful of Inventory
 * items, exactly as this file's original version warned in its own
 * comments. app.js's "Sync from ConnectWise" now calls sync-start once and
 * then sync-step repeatedly until it reports done: true.
 *
 * GET  /register/api/catalog.php?action=list[&q=search+text][&in_stock_only=1]
 *   -> { ok: true, items: [ { id, identifier, description, category_name,
 *          subcategory_name, unit_of_measure, price, on_hand, ... }, ... ] }
 *
 * GET  /register/api/catalog.php?action=sync-status
 *   -> { ok: true, totals: { pending, done, error }, started_at }
 *
 * POST /register/api/catalog.php?action=sync-start
 *   -> { ok: true, total }
 *
 * POST /register/api/catalog.php?action=sync-step
 *   { batch_size?: int (default 15, max 30) }
 *   -> { ok: true, processed_this_batch, remaining, done, totals, errors: [...] }
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

// TEMPORARY, READ-ONLY diagnostic -- added 2026-09-13 per Michael: he wants
// the Register catalog search to also include "Agreement Class products
// that retail assistants can add to customers who need recurring
// protection at the time of sale" (e.g. extended warranty / protection
// plans), alongside the existing productClass='Inventory' items. Same
// discipline as every other ConnectWise integration in this project: don't
// guess whether "Agreement" is a real productClass value or what fields
// these items carry -- fetch real rows first. DELETE once confirmed and
// the real sync logic is written against it (same lifecycle as the
// now-removed cw-catalog-probe.php / cw_date_probe).
if ($action === 'probe-agreement-class') {
    try {
        $rows = register_cw_request('/procurement/catalog', [
            'conditions' => "productClass='Agreement'",
            'pageSize' => 10,
        ]);
        register_respond(200, ['ok' => true, 'rows' => $rows]);
    } catch (Throwable $e) {
        register_respond(200, ['ok' => false, 'error' => $e->getMessage()]);
    }
}

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

if ($action === 'sync-status') {
    $counts = $pdo->query('SELECT status, COUNT(*) AS n FROM cw_catalog_sync_queue GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
    $meta = $pdo->query("SELECT value FROM register_sync_meta WHERE key = 'catalog_started_at'")->fetchColumn();
    register_respond(200, [
        'ok' => true,
        'totals' => [
            'pending' => (int) ($counts['pending'] ?? 0),
            'done' => (int) ($counts['done'] ?? 0),
            'error' => (int) ($counts['error'] ?? 0),
        ],
        'started_at' => $meta !== false ? $meta : null,
    ]);
}

if ($action === 'sync-start') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        register_respond(405, ['ok' => false, 'error' => 'Method not allowed.']);
    }
    try {
        $result = register_catalog_sync_start($pdo);
        register_respond(200, ['ok' => true, 'total' => $result['total']]);
    } catch (RegisterConnectWiseError $e) {
        register_respond(502, ['ok' => false, 'error' => $e->getMessage()]);
    }
}

if ($action === 'sync-step') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        register_respond(405, ['ok' => false, 'error' => 'Method not allowed.']);
    }
    $data = register_read_json_body();
    $batchSize = (int) ($data['batch_size'] ?? 15);
    $batchSize = max(1, min(30, $batchSize));
    $result = register_catalog_sync_step($pdo, $batchSize);
    register_respond(200, array_merge(['ok' => true], $result));
}

register_respond(400, ['ok' => false, 'error' => 'Unknown action.']);

/**
 * Rebuilds the catalog sync queue: every active, productClass='Inventory'
 * Procurement Catalog item's id/identifier -- a cheap list-only call (no
 * per-item detail or inventory lookups yet), so this alone should never
 * risk the execution-time limit even with a large catalog.
 */
function register_catalog_sync_start(PDO $pdo): array
{
    $items = register_cw_list(
        '/procurement/catalog',
        "productClass='Inventory' and inactiveFlag=false",
        ['id', 'identifier']
    );

    $pdo->exec('DELETE FROM cw_catalog_sync_queue');
    $insert = $pdo->prepare(
        'INSERT INTO cw_catalog_sync_queue (cw_catalog_id, identifier, status) VALUES (:id, :identifier, \'pending\')'
    );
    foreach ($items as $item) {
        $cwId = (int) ($item['id'] ?? 0);
        if ($cwId === 0) {
            continue;
        }
        $insert->execute([':id' => $cwId, ':identifier' => (string) ($item['identifier'] ?? '')]);
    }

    $total = count($items);
    $pdo->prepare('INSERT OR REPLACE INTO register_sync_meta (key, value) VALUES (\'catalog_started_at\', :v)')
        ->execute([':v' => date('c')]);

    return ['total' => $total];
}

/**
 * Processes up to $batchSize pending queue rows: for each, fetches the
 * full catalog item detail plus its /inventory rows (summed into one
 * on_hand total), and upserts into catalog_items. One item's failure is
 * caught and recorded on its own queue row rather than aborting the batch
 * -- same "don't let one bad item sink the whole sync" approach as
 * connectwise-activity-create.php's member lookup.
 */
function register_catalog_sync_step(PDO $pdo, int $batchSize = 15): array
{
    $pending = $pdo->prepare('SELECT * FROM cw_catalog_sync_queue WHERE status = \'pending\' ORDER BY cw_catalog_id LIMIT :n');
    $pending->bindValue(':n', $batchSize, PDO::PARAM_INT);
    $pending->execute();
    $rows = $pending->fetchAll(PDO::FETCH_ASSOC);

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

    $processed = 0;
    $errors = [];

    foreach ($rows as $row) {
        $cwId = (int) $row['cw_catalog_id'];
        try {
            $item = register_cw_request('/procurement/catalog/' . $cwId);
            $inventoryRows = register_cw_request('/procurement/catalog/' . $cwId . '/inventory');

            $onHand = 0.0;
            foreach ($inventoryRows as $invRow) {
                $onHand += (float) ($invRow['onHand'] ?? 0);
            }

            $upsert->execute([
                ':cw_id' => $cwId,
                ':identifier' => (string) ($item['identifier'] ?? $row['identifier']),
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

            $pdo->prepare('UPDATE cw_catalog_sync_queue SET status = \'done\', processed_at = datetime(\'now\'), error_message = NULL WHERE cw_catalog_id = :id')
                ->execute([':id' => $cwId]);
        } catch (Throwable $e) {
            $pdo->prepare('UPDATE cw_catalog_sync_queue SET status = \'error\', processed_at = datetime(\'now\'), error_message = :msg WHERE cw_catalog_id = :id')
                ->execute([':id' => $cwId, ':msg' => substr($e->getMessage(), 0, 500)]);
            $errors[] = ['cw_catalog_id' => $cwId, 'identifier' => $row['identifier'], 'error' => $e->getMessage()];
        }
        $processed++;
    }

    $counts = $pdo->query('SELECT status, COUNT(*) AS n FROM cw_catalog_sync_queue GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
    $remaining = (int) ($counts['pending'] ?? 0);

    return [
        'processed_this_batch' => $processed,
        'remaining' => $remaining,
        'done' => $remaining === 0,
        'totals' => [
            'pending' => (int) ($counts['pending'] ?? 0),
            'done' => (int) ($counts['done'] ?? 0),
            'error' => (int) ($counts['error'] ?? 0),
        ],
        'errors' => $errors,
    ];
}
