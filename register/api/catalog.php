<?php
/**
 * register/api/catalog.php
 *
 * The Register app's local product catalog, synced from ConnectWise's
 * Procurement Catalog module. Confirmed via live diagnostics that:
 *
 *   - /procurement/catalog items have a real `productClass` field. Two
 *     values are synced here:
 *       - "Inventory" -- a physical item CBT stocks. NOT, on its own, the
 *         same thing as "currently has on-hand stock" -- see below.
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
 *     `onHand` across those rows.
 *   - productClass='Inventory' is NOT the same set of items ConnectWise's
 *     own "Inventory Management" screen shows as "On-Hand" -- confirmed
 *     2026-09-13 via several live diagnostics, after a first sync queued
 *     15,000+ items instead of Michael's expected ~1,053 (603 On-Hand +
 *     450 active Agreement): productClass='Inventory' matches ~14,600
 *     active items total, but a zero-stock item (e.g. a legacy "Patch
 *     Cable" entry) and a genuinely stocked item (e.g. "UK703E", an HP
 *     warranty SKU CBT tracks like inventory) are BOTH productClass=
 *     'Inventory' -- there's no catalog-level field, and no ConnectWise
 *     list filter (childconditions and every warehouse/bin inventory-list
 *     endpoint tried came back unsupported or 404), that separates them.
 *     The only real signal is each item's own summed on-hand quantity.
 *     Agreement-class items never have this sub-resource queried --
 *     catalog_items.track_inventory distinguishes the two (1 = enforce
 *     on_hand at checkout, 0 = always sellable).
 *
 * Because of that last point, the sync is a TWO-PHASE queue (added
 * 2026-09-13, replacing a single-phase version from 2026-09-12): phase
 * 'filter' does one cheap /inventory-only check per Inventory candidate to
 * find the ones with actual stock (confirmed-zero results are cached in
 * catalog_no_stock_cache so later syncs skip re-checking them -- see
 * REGISTER_NO_STOCK_RECHECK_DAYS); phase 'sync' then does one full-detail
 * fetch per Agreement item and per Inventory item the filter phase
 * promoted. Both phases are bounded-batch queues (same shape as
 * relationships/api/sync.php's much larger agreement sync) for the same
 * reason the original 2026-09-12 fix existed: doing a ConnectWise
 * round-trip per item synchronously in a single request risks Bluehost's
 * execution-time limit. app.js's "Sync from ConnectWise" calls sync-start
 * once and then sync-step repeatedly until it reports done: true, draining
 * the filter phase before the sync phase ever starts.
 *
 * GET  /register/api/catalog.php?action=list[&q=search+text][&in_stock_only=1]
 *   -> { ok: true, items: [ { id, identifier, description, category_name,
 *          subcategory_name, unit_of_measure, price, on_hand,
 *          product_class, track_inventory, ... }, ... ] }
 *
 * GET  /register/api/catalog.php?action=sync-status
 *   -> { ok: true, filter_totals: { pending, error },
 *          sync_totals: { pending, done, error }, done, started_at }
 *
 * POST /register/api/catalog.php?action=sync-start
 *   -> { ok: true, total_agreement, total_inventory_candidates,
 *          total_filter_queued, total_skipped_cached_no_stock }
 *
 * POST /register/api/catalog.php?action=sync-step
 *   { batch_size?: int (default 20, max 50) }
 *   -> { ok: true, phase: 'filter' | 'sync', processed_this_batch,
 *          filter_totals: { pending, error },
 *          sync_totals: { pending, done, error }, done, errors: [...] }
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
    register_respond(200, array_merge(['ok' => true], register_catalog_sync_totals($pdo), [
        'started_at' => (function () use ($pdo) {
            $meta = $pdo->query("SELECT value FROM register_sync_meta WHERE key = 'catalog_started_at'")->fetchColumn();
            return $meta !== false ? $meta : null;
        })(),
    ]));
}

if ($action === 'sync-start') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        register_respond(405, ['ok' => false, 'error' => 'Method not allowed.']);
    }
    try {
        $result = register_catalog_sync_start($pdo);
        register_respond(200, array_merge(['ok' => true], $result));
    } catch (RegisterConnectWiseError $e) {
        register_respond(502, ['ok' => false, 'error' => $e->getMessage()]);
    }
}

if ($action === 'sync-step') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        register_respond(405, ['ok' => false, 'error' => 'Method not allowed.']);
    }
    $data = register_read_json_body();
    $batchSize = (int) ($data['batch_size'] ?? 20);
    $batchSize = max(1, min(50, $batchSize));
    $result = register_catalog_sync_step($pdo, $batchSize);
    register_respond(200, array_merge(['ok' => true], $result));
}

register_respond(400, ['ok' => false, 'error' => 'Unknown action.']);

/**
 * How long a confirmed-zero-stock Inventory item is trusted before it gets
 * re-checked. Bounds the ongoing cost of re-scanning ~14,000 legacy
 * catalog entries on every sync, while still catching an item that gets
 * restocked later.
 */
const REGISTER_NO_STOCK_RECHECK_DAYS = 3;

/**
 * Rebuilds the catalog sync queue for a fresh sync run. Two independent,
 * cheap id/identifier-only list calls (confirmed 2026-09-13 against real
 * ConnectWise data):
 *
 *   - productClass='Agreement' and inactiveFlag=false -- matches Michael's
 *     ConnectWise count (450) exactly. Queued straight to phase='sync' --
 *     no stock check needed, these never carry on-hand quantity.
 *   - productClass='Inventory' and inactiveFlag=false -- matches ~14,600
 *     active items, but only ~603 of those actually have on-hand stock
 *     right now (confirmed via a live diagnostic: a zero-stock item and a
 *     stocked item are both productClass='Inventory' -- there's no
 *     catalog-level field or ConnectWise list filter, including
 *     childconditions and every warehouse/bin inventory-list endpoint
 *     tried, that can tell them apart without checking each item's own
 *     /inventory sub-resource). Queued to phase='filter' UNLESS the item
 *     is already in catalog_no_stock_cache from a check within the last
 *     REGISTER_NO_STOCK_RECHECK_DAYS days, in which case it's skipped
 *     entirely -- this is what keeps every sync after the first one fast.
 */
function register_catalog_sync_start(PDO $pdo): array
{
    $pdo->exec('DELETE FROM cw_catalog_sync_queue');

    $agreementItems = register_cw_list(
        '/procurement/catalog',
        "productClass='Agreement' and inactiveFlag=false",
        ['id', 'identifier']
    );
    $insertSync = $pdo->prepare(
        "INSERT INTO cw_catalog_sync_queue (cw_catalog_id, identifier, phase, status) VALUES (:id, :identifier, 'sync', 'pending')"
    );
    foreach ($agreementItems as $item) {
        $cwId = (int) ($item['id'] ?? 0);
        if ($cwId === 0) {
            continue;
        }
        $insertSync->execute([':id' => $cwId, ':identifier' => (string) ($item['identifier'] ?? '')]);
    }

    $inventoryItems = register_cw_list(
        '/procurement/catalog',
        "productClass='Inventory' and inactiveFlag=false",
        ['id', 'identifier']
    );
    $noStockCache = $pdo->query('SELECT cw_catalog_id, checked_at FROM catalog_no_stock_cache')
        ->fetchAll(PDO::FETCH_KEY_PAIR);
    $staleBefore = date('c', strtotime('-' . REGISTER_NO_STOCK_RECHECK_DAYS . ' days'));

    $insertFilter = $pdo->prepare(
        "INSERT INTO cw_catalog_sync_queue (cw_catalog_id, identifier, phase, status) VALUES (:id, :identifier, 'filter', 'pending')"
    );
    $filterQueued = 0;
    $skippedCached = 0;
    foreach ($inventoryItems as $item) {
        $cwId = (int) ($item['id'] ?? 0);
        if ($cwId === 0) {
            continue;
        }
        $checkedAt = $noStockCache[$cwId] ?? null;
        if ($checkedAt !== null && $checkedAt > $staleBefore) {
            $skippedCached++;
            continue;
        }
        $insertFilter->execute([':id' => $cwId, ':identifier' => (string) ($item['identifier'] ?? '')]);
        $filterQueued++;
    }

    $pdo->prepare("INSERT OR REPLACE INTO register_sync_meta (key, value) VALUES ('catalog_started_at', :v)")
        ->execute([':v' => date('c')]);

    return [
        'total_agreement' => count($agreementItems),
        'total_inventory_candidates' => count($inventoryItems),
        'total_filter_queued' => $filterQueued,
        'total_skipped_cached_no_stock' => $skippedCached,
    ];
}

/**
 * Processes up to $batchSize queue rows for whichever phase still has
 * pending work -- filter-phase rows are always drained first, so a
 * caller looping this until done:true sees a clean "checking stock" phase
 * followed by a "syncing catalog" phase, never an interleaved mix.
 *
 * Filter phase: one cheap /inventory-only call per Inventory candidate.
 * Zero on-hand -> recorded in catalog_no_stock_cache and dropped from the
 * queue entirely (excluded from catalog_items, same as before). Nonzero ->
 * promoted to phase='sync' with the summed total cached on the row, so it's
 * never fetched twice.
 *
 * Sync phase: one full item-detail call per row (Agreement items, plus
 * whatever the filter phase promoted). Upserts into catalog_items using the
 * cached on-hand total when present; Agreement-class items never track
 * on-hand at all. One item's failure is caught and recorded on its own
 * queue row rather than aborting the batch -- same "don't let one bad item
 * sink the whole sync" approach as connectwise-activity-create.php's member
 * lookup.
 */
function register_catalog_sync_step(PDO $pdo, int $batchSize = 20): array
{
    $pendingFilter = $pdo->prepare("SELECT * FROM cw_catalog_sync_queue WHERE phase = 'filter' AND status = 'pending' ORDER BY cw_catalog_id LIMIT :n");
    $pendingFilter->bindValue(':n', $batchSize, PDO::PARAM_INT);
    $pendingFilter->execute();
    $filterRows = $pendingFilter->fetchAll(PDO::FETCH_ASSOC);

    $errors = [];
    $processed = 0;

    if ($filterRows !== []) {
        $promote = $pdo->prepare("UPDATE cw_catalog_sync_queue SET phase = 'sync', status = 'pending', cached_on_hand = :oh, error_message = NULL WHERE cw_catalog_id = :id");
        $markError = $pdo->prepare("UPDATE cw_catalog_sync_queue SET status = 'error', processed_at = datetime('now'), error_message = :msg WHERE cw_catalog_id = :id");
        $deleteRow = $pdo->prepare('DELETE FROM cw_catalog_sync_queue WHERE cw_catalog_id = :id');
        $cacheNoStock = $pdo->prepare(
            "INSERT INTO catalog_no_stock_cache (cw_catalog_id, identifier, checked_at) VALUES (:id, :identifier, datetime('now'))
             ON CONFLICT(cw_catalog_id) DO UPDATE SET checked_at = excluded.checked_at, identifier = excluded.identifier"
        );

        foreach ($filterRows as $row) {
            $cwId = (int) $row['cw_catalog_id'];
            try {
                $inventoryRows = register_cw_request('/procurement/catalog/' . $cwId . '/inventory');
                $onHand = 0.0;
                foreach ($inventoryRows as $invRow) {
                    $onHand += (float) ($invRow['onHand'] ?? 0);
                }
                if ($onHand > 0) {
                    $promote->execute([':oh' => $onHand, ':id' => $cwId]);
                } else {
                    $cacheNoStock->execute([':id' => $cwId, ':identifier' => $row['identifier']]);
                    $deleteRow->execute([':id' => $cwId]);
                }
            } catch (Throwable $e) {
                $markError->execute([':id' => $cwId, ':msg' => substr($e->getMessage(), 0, 500)]);
                $errors[] = ['cw_catalog_id' => $cwId, 'identifier' => $row['identifier'], 'phase' => 'filter', 'error' => $e->getMessage()];
            }
            $processed++;
        }

        return array_merge(['phase' => 'filter', 'processed_this_batch' => $processed, 'errors' => $errors], register_catalog_sync_totals($pdo));
    }

    $pendingSync = $pdo->prepare("SELECT * FROM cw_catalog_sync_queue WHERE phase = 'sync' AND status = 'pending' ORDER BY cw_catalog_id LIMIT :n");
    $pendingSync->bindValue(':n', $batchSize, PDO::PARAM_INT);
    $pendingSync->execute();
    $syncRows = $pendingSync->fetchAll(PDO::FETCH_ASSOC);

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

    foreach ($syncRows as $row) {
        $cwId = (int) $row['cw_catalog_id'];
        try {
            $item = register_cw_request('/procurement/catalog/' . $cwId);
            $productClass = (string) ($item['productClass'] ?? 'Inventory');
            $trackInventory = $productClass === 'Inventory';
            $onHand = $trackInventory ? (float) ($row['cached_on_hand'] ?? 0) : 0.0;

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

            $pdo->prepare("UPDATE cw_catalog_sync_queue SET status = 'done', processed_at = datetime('now'), error_message = NULL WHERE cw_catalog_id = :id")
                ->execute([':id' => $cwId]);
        } catch (Throwable $e) {
            $pdo->prepare("UPDATE cw_catalog_sync_queue SET status = 'error', processed_at = datetime('now'), error_message = :msg WHERE cw_catalog_id = :id")
                ->execute([':id' => $cwId, ':msg' => substr($e->getMessage(), 0, 500)]);
            $errors[] = ['cw_catalog_id' => $cwId, 'identifier' => $row['identifier'], 'phase' => 'sync', 'error' => $e->getMessage()];
        }
        $processed++;
    }

    return array_merge(['phase' => 'sync', 'processed_this_batch' => $processed, 'errors' => $errors], register_catalog_sync_totals($pdo));
}

/**
 * Current queue state, broken out by phase, plus an overall `done` flag
 * (true only once both phases have nothing left pending).
 */
function register_catalog_sync_totals(PDO $pdo): array
{
    $filterCounts = $pdo->query("SELECT status, COUNT(*) AS n FROM cw_catalog_sync_queue WHERE phase = 'filter' GROUP BY status")
        ->fetchAll(PDO::FETCH_KEY_PAIR);
    $syncCounts = $pdo->query("SELECT status, COUNT(*) AS n FROM cw_catalog_sync_queue WHERE phase = 'sync' GROUP BY status")
        ->fetchAll(PDO::FETCH_KEY_PAIR);

    $filterTotals = [
        'pending' => (int) ($filterCounts['pending'] ?? 0),
        'error' => (int) ($filterCounts['error'] ?? 0),
    ];
    $syncTotals = [
        'pending' => (int) ($syncCounts['pending'] ?? 0),
        'done' => (int) ($syncCounts['done'] ?? 0),
        'error' => (int) ($syncCounts['error'] ?? 0),
    ];

    return [
        'filter_totals' => $filterTotals,
        'sync_totals' => $syncTotals,
        'done' => $filterTotals['pending'] === 0 && $syncTotals['pending'] === 0,
    ];
}
