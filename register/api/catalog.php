<?php
/**
 * register/api/catalog.php
 *
 * The Register app's local product catalog, synced from ConnectWise's
 * Procurement Catalog module. Confirmed via live diagnostics (see
 * relationships/api/cw-catalog-probe.php's history, and this file's own
 * now-removed ?action=probe-agreement-class) that:
 *
 *   - /procurement/catalog items have a real `productClass` field. Two
 *     values are synced here:
 *       - "Inventory" -- a physical item CBT stocks. Michael's "On Hand"
 *         distinction -- real stocked products vs. "products we've ever
 *         sold including recurring services found on agreements" -- maps
 *         directly onto this value.
 *       - "Agreement" -- confirmed 2026-09-13 via a live probe (real rows:
 *         "Basic Managed Anti-Spam", "Managed Backup - Standard Server",
 *         etc.) -- recurring-protection/managed-service products retail
 *         staff can ring up at time of sale. Per Michael, adding one of
 *         these at checkout is a PLAIN LINE ITEM on the receipt only --
 *         it does not create or attach a real ConnectWise Agreement, so
 *         these items have no on-hand quantity to track at all.
 *   - On-hand QUANTITY (Inventory items only) is NOT a field on the
 *     catalog item itself (fields=onHand / fields=quantityOnHand both
 *     silently return nothing -- ConnectWise drops unrecognized field
 *     names rather than erroring). It lives in a separate sub-resource,
 *     /procurement/catalog/{id}/inventory, as rows of { warehouse,
 *     warehouseBin, onHand, ... } -- one row per warehouse/bin the item is
 *     stocked in. A catalog item's total on-hand quantity is the SUM of
 *     `onHand` across those rows. Agreement-class items never have this
 *     sub-resource queried -- catalog_items.track_inventory distinguishes
 *     the two (1 = enforce on_hand at checkout, 0 = always sellable).
 *
 * The sync is a queue-based start()/step() pair (added 2026-09-12 after a
 * real "Sync failed — check your connection" error on the first live
 * attempt, which ran ~4 minutes before failing) -- same shape as
 * relationships/api/sync.php's much larger agreement sync, for the same
 * reason: doing a ConnectWise round-trip per catalog item synchronously in
 * a single request was always going to risk Bluehost's execution-time
 * limit once there's more than a handful of items. app.js's "Sync from
 * ConnectWise" calls sync-start once and then sync-step repeatedly until
 * it reports done: true.
 *
 * GET  /register/api/catalog.php?action=list[&q=search+text][&in_stock_only=1]
 *   -> { ok: true, items: [ { id, identifier, description, category_name,
 *          subcategory_name, unit_of_measure, price, on_hand,
 *          product_class, track_inventory, ... }, ... ] }
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

if ($action === 'list') {
    $q = trim((string) ($_GET['q'] ?? ''));
    $inStockOnly = ($_GET['in_stock_only'] ?? '') === '1';

    $sql = 'SELECT id, cw_catalog_id, identifier, description, customer_description,
                   category_name, subcategory_name, unit_of_measure, price, on_hand,
                   product_class, track_inventory, synced_at
            FROM catalog_items
            WHERE inactive_flag = 0';
    $params = [];
    if ($q !== '') {
        $sql .= ' AND (identifier LIKE :q OR description LIKE :q OR customer_description LIKE :q)';
        $params[':q'] = '%' . $q . '%';
    }
    if ($inStockOnly) {
        // Agreement-class items (track_inventory = 0) have no physical
        // stock to be "out of" -- always included regardless of this
        // toggle. Only Inventory-class items get filtered by on_hand.
        $sql .= ' AND (track_inventory = 0 OR on_hand > 0)';
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
        $item['track_inventory'] = (int) $item['track_inventory'];
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

// TEMPORARY read-only diagnostic (2026-09-13) -- the real sync-start just
// queued 15,000+ items instead of the ~1,053 Michael confirmed (603
// Inventory "On Hand" + 450 active Agreement products) via ConnectWise's own
// UI. Rather than guess why the conditions filter isn't narrowing things
// down, this hits ConnectWise's /count endpoint with several candidate
// condition strings so we can see, from real data, which one actually
// filters and which don't. Read-only, no DB writes, login-gated. Delete
// this action once the real filter is confirmed and fixed.
if ($action === 'probe-catalog-filter') {
    $candidates = [
        'no_filter_baseline' => '',
        'inactive_false_only' => 'inactiveFlag=false',
        'product_class_inventory' => "productClass='Inventory'",
        'product_class_agreement' => "productClass='Agreement'",
        'product_class_inventory_and_active' => "productClass='Inventory' and inactiveFlag=false",
        'product_class_agreement_and_active' => "productClass='Agreement' and inactiveFlag=false",
        'product_class_or_no_active_filter' => "(productClass='Inventory' or productClass='Agreement')",
        'current_sync_start_conditions' => "(productClass='Inventory' or productClass='Agreement') and inactiveFlag=false",
    ];

    $results = [];
    foreach ($candidates as $label => $conditions) {
        $query = $conditions !== '' ? ['conditions' => $conditions] : [];
        try {
            $resp = register_cw_request('/procurement/catalog/count', $query);
            $results[$label] = ['conditions' => $conditions, 'count' => $resp['count'] ?? $resp];
        } catch (Throwable $e) {
            $results[$label] = ['conditions' => $conditions, 'error' => $e->getMessage()];
        }
    }

    register_respond(200, ['ok' => true, 'results' => $results]);
}

// TEMPORARY read-only diagnostic (2026-09-13, round 2) -- the count probe
// above confirmed productClass='Inventory' and inactiveFlag=false matches
// exactly what conditions say (14,573), but that's nowhere near the 603
// items ConnectWise's own "Inventory Management" screen shows. So
// productClass isn't the field that screen filters on -- something else is.
// This compares the full raw field set of an already-synced Inventory item
// with on_hand=0 against one with on_hand>0, to spot whatever field
// distinguishes "actually tracked/stocked" items from the rest of the
// catalog. Read-only, no writes. Delete once the real filter is found.
if ($action === 'probe-onhand-diff') {
    $zero = $pdo->query("SELECT cw_catalog_id, identifier, on_hand FROM catalog_items WHERE product_class = 'Inventory' AND on_hand = 0 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $nonzero = $pdo->query("SELECT cw_catalog_id, identifier, on_hand FROM catalog_items WHERE product_class = 'Inventory' AND on_hand > 0 LIMIT 1")->fetch(PDO::FETCH_ASSOC);

    $out = ['zero_on_hand_sample' => $zero, 'nonzero_on_hand_sample' => $nonzero];
    foreach (['zero_on_hand_sample' => $zero, 'nonzero_on_hand_sample' => $nonzero] as $label => $row) {
        if ($row) {
            try {
                $out[$label . '_raw'] = register_cw_request('/procurement/catalog/' . (int) $row['cw_catalog_id']);
            } catch (Throwable $e) {
                $out[$label . '_raw_error'] = $e->getMessage();
            }
        } else {
            $out[$label . '_raw'] = null;
        }
    }

    register_respond(200, ['ok' => true, 'data' => $out]);
}

// TEMPORARY read-only diagnostic (2026-09-13, round 2) -- probes for a
// possible dedicated "which items currently have on-hand inventory"
// endpoint, in case that's a cleaner way to get the 603-item set than
// filtering the full catalog. Read-only, no writes. Delete once resolved.
if ($action === 'probe-inventory-list-endpoint') {
    $attempts = [];
    $paths = [
        '/procurement/catalog/inventory',
        '/procurement/warehouses',
        '/procurement/warehousebins',
        '/procurement/adjustments',
    ];
    foreach ($paths as $path) {
        try {
            $resp = register_cw_request($path, ['pageSize' => '2']);
            $attempts[$path] = ['ok' => true, 'sample_count' => count($resp), 'sample' => $resp];
        } catch (Throwable $e) {
            $attempts[$path] = ['ok' => false, 'error' => $e->getMessage()];
        }
    }
    register_respond(200, ['ok' => true, 'attempts' => $attempts]);
}

// TEMPORARY read-only diagnostic (2026-09-13, round 3) -- round 2 showed
// productClass='Inventory' vs 'Agreement' isn't the "On-Hand" distinction
// Michael means: both a zero-stock item (Patch Cable) and a stocked item
// (UK703E, an HP warranty SKU CBT tracks like inventory) are
// productClass='Inventory' -- the only real difference is their actual
// summed on-hand quantity, which lives in the per-item /inventory
// sub-resource, not on the catalog item itself. This tests whether
// ConnectWise's `childconditions` param can filter the catalog list by that
// child collection directly (avoiding a per-item call for all 14,573
// candidates just to find the ~603 that are actually stocked). Read-only,
// no writes. Delete once resolved.
if ($action === 'probe-childconditions') {
    $candidates = [
        'childcondition_onhand_only' => ['childconditions' => 'inventory/onHand>0'],
        'childcondition_onhand_plus_class' => [
            'conditions' => "productClass='Inventory' and inactiveFlag=false",
            'childconditions' => 'inventory/onHand>0',
        ],
    ];
    $results = [];
    foreach ($candidates as $label => $query) {
        try {
            $resp = register_cw_request('/procurement/catalog/count', $query);
            $results[$label] = ['query' => $query, 'count' => $resp['count'] ?? $resp];
        } catch (Throwable $e) {
            $results[$label] = ['query' => $query, 'error' => $e->getMessage()];
        }
    }
    register_respond(200, ['ok' => true, 'results' => $results]);
}

// TEMPORARY read-only diagnostic (2026-09-13, round 3) -- alternative to
// childconditions: CBT only has 2 warehouses/bins (per round 2's
// /procurement/warehouses probe), so if a bin-level "list what's stocked in
// this bin" endpoint exists, walking 2 bins is far cheaper than checking
// 14,573 catalog items one at a time. Read-only, no writes. Delete once
// resolved.
if ($action === 'probe-bin-inventory') {
    $attempts = [];
    $paths = [
        '/procurement/warehousebins/1/inventory',
        '/procurement/warehouses/1/inventory',
        '/procurement/warehouses/1/bins/1/inventory',
    ];
    foreach ($paths as $path) {
        try {
            $resp = register_cw_request($path, ['pageSize' => '3']);
            $attempts[$path] = ['ok' => true, 'sample_count' => count($resp), 'sample' => $resp];
        } catch (Throwable $e) {
            $attempts[$path] = ['ok' => false, 'error' => $e->getMessage()];
        }
    }
    register_respond(200, ['ok' => true, 'attempts' => $attempts]);
}

// TEMPORARY read-only diagnostic (2026-09-13, round 4) -- neither
// childconditions nor a bin-level inventory endpoint exist, so ConnectWise
// gives us no server-side way to filter the catalog list by on-hand status
// directly -- ruling that out. Before committing to a two-phase sync (a
// cheap /inventory-only scan of all 14,573 Inventory-class items to build a
// short list, then a full sync of just that short list), this settles one
// remaining question: does a zero-on-hand item like "Patch Cable" return an
// EMPTY /inventory array (never assigned to a warehouse/bin at all), or a
// non-empty array whose onHand rows just sum to zero (assigned, but
// currently out of stock)? That distinguishes "item has a warehouse/bin
// assignment at all" from "current on-hand happens to be > 0" -- either
// could be what Michael's ConnectWise "Inventory Management" screen (whose
// own saved view uses a trivial "On Hand >= 0" filter -- true for any
// non-negative amount) is really keying off of. Read-only, no writes.
// Delete once resolved.
if ($action === 'probe-raw-inventory-rows') {
    $out = [];
    foreach (['zero_on_hand_743_patch_cable' => 743, 'nonzero_on_hand_833_uk703e' => 833] as $label => $cwId) {
        try {
            $out[$label] = register_cw_request('/procurement/catalog/' . $cwId . '/inventory');
        } catch (Throwable $e) {
            $out[$label . '_error'] = $e->getMessage();
        }
    }
    register_respond(200, ['ok' => true, 'data' => $out]);
}

// TEMPORARY read-only diagnostic (2026-09-13, round 4) -- sanity-checks the
// ~4% (603 of 14,573) stocked-item ratio Michael's numbers imply, against
// what's already landed locally from the (oversized, not-yet-fixed) sync
// that ran partway before being stopped. Read-only, no writes to
// ConnectWise; only reads this app's own already-synced local data. Delete
// once resolved.
if ($action === 'probe-local-onhand-stats') {
    $stats = $pdo->query(
        "SELECT
            COUNT(*) FILTER (WHERE product_class = 'Inventory') AS inventory_total,
            COUNT(*) FILTER (WHERE product_class = 'Inventory' AND on_hand > 0) AS inventory_nonzero,
            COUNT(*) FILTER (WHERE product_class = 'Inventory' AND on_hand = 0) AS inventory_zero,
            COUNT(*) FILTER (WHERE product_class = 'Agreement') AS agreement_total
         FROM catalog_items"
    )->fetch(PDO::FETCH_ASSOC);
    register_respond(200, ['ok' => true, 'stats' => $stats]);
}

register_respond(400, ['ok' => false, 'error' => 'Unknown action.']);

/**
 * Rebuilds the catalog sync queue: every active item with
 * productClass='Inventory' OR productClass='Agreement' -- a cheap
 * id/identifier-only list call (no per-item detail or inventory lookups
 * yet), so this alone should never risk the execution-time limit even
 * with a large catalog.
 */
function register_catalog_sync_start(PDO $pdo): array
{
    $items = register_cw_list(
        '/procurement/catalog',
        "(productClass='Inventory' or productClass='Agreement') and inactiveFlag=false",
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
 * full catalog item detail; for Inventory-class items only, also fetches
 * its /inventory rows (summed into one on_hand total) -- Agreement-class
 * items skip that call entirely, since they carry no warehouse/bin stock.
 * Upserts into catalog_items. One item's failure is caught and recorded on
 * its own queue row rather than aborting the batch -- same "don't let one
 * bad item sink the whole sync" approach as connectwise-activity-create.php's
 * member lookup.
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
             subcategory_name, unit_of_measure, price, cost, on_hand, taxable_flag, inactive_flag,
             product_class, track_inventory, synced_at)
         VALUES
            (:cw_id, :identifier, :description, :customer_description, :category_name,
             :subcategory_name, :unit_of_measure, :price, :cost, :on_hand, :taxable_flag, :inactive_flag,
             :product_class, :track_inventory, datetime(\'now\'))
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
            product_class = excluded.product_class,
            track_inventory = excluded.track_inventory,
            synced_at = excluded.synced_at'
    );

    $processed = 0;
    $errors = [];

    foreach ($rows as $row) {
        $cwId = (int) $row['cw_catalog_id'];
        try {
            $item = register_cw_request('/procurement/catalog/' . $cwId);
            $productClass = (string) ($item['productClass'] ?? 'Inventory');
            $trackInventory = $productClass === 'Inventory';

            $onHand = 0.0;
            if ($trackInventory) {
                $inventoryRows = register_cw_request('/procurement/catalog/' . $cwId . '/inventory');
                foreach ($inventoryRows as $invRow) {
                    $onHand += (float) ($invRow['onHand'] ?? 0);
                }
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
                ':product_class' => $productClass,
                ':track_inventory' => $trackInventory ? 1 : 0,
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
