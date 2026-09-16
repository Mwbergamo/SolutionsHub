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
 * Because of that last point, the sync is a FOUR-STAGE queue (revised
 * 2026-09-13 after the two-phase version above STILL failed to start --
 * "Could not start the sync — check your connection and try again" turned
 * out to mean sync-start itself was timing out, not any per-item work: even
 * the cheap id/identifier-only catalog list calls take ~73 sequential
 * ConnectWise pages at the confirmed ~14,600-Inventory-candidate scale, and
 * doing all of that synchronously inside one request hit the exact same
 * Bluehost execution-time limit the 2026-09-12 fix was meant to rule out
 * everywhere). Every stage below now does AT MOST ONE ConnectWise request
 * per sync-step call, no matter how large the catalog grows:
 *
 *   1. 'list_agreement' -- one page (200 items) of productClass='Agreement'
 *      per call, queued straight to phase='sync' (matches Michael's live
 *      ConnectWise count exactly -- no stock check needed).
 *   2. 'list_inventory' -- one page of productClass='Inventory' per call.
 *      Each item is queued to phase='filter' UNLESS it's already in
 *      catalog_no_stock_cache within REGISTER_NO_STOCK_RECHECK_DAYS, in
 *      which case it's skipped entirely -- this is what keeps every sync
 *      after the first one fast.
 *   3. 'filter' -- one cheap /inventory-only check per Inventory candidate
 *      from stage 2, in bounded batches. Confirmed-zero results are cached
 *      in catalog_no_stock_cache; nonzero results are promoted to
 *      phase='sync' with the summed total cached on the row.
 *   4. 'sync' -- one full-detail fetch per row now in phase='sync' (all
 *      Agreement items from stage 1, plus whatever stage 3 promoted),
 *      upserted into catalog_items using the cached on-hand total.
 *
 * register_sync_meta tracks which stage/page a run is on (namespaced
 * 'catalog_sync_*' keys) so sync-step can resume the right stage on each
 * call. app.js's "Sync from ConnectWise" calls sync-start once (now a
 * near-instant reset, no ConnectWise calls at all) and then sync-step
 * repeatedly until it reports done: true, moving through the four stages
 * in order, never interleaved.
 *
 * GET  /register/api/catalog.php?action=list[&q=search+text][&in_stock_only=1]
 *        [&type=Voice+over+IP][&category=...][&subcategory=...]
 *   -> { ok: true, items: [ { id, identifier, description, category_name,
 *          subcategory_name, type_name, manufacturer_part_number, vendor_sku,
 *          unit_of_measure, price, on_hand, product_class, track_inventory,
 *          ... }, ... ] }
 *
 * `type_name` groups ConnectWise's own category_name into one of the 5
 * business lines Michael wants the front page organized by (added
 * 2026-09-14, see register_catalog_type_for_category()'s docblock for the
 * full mapping and why it exists as its own layer rather than a real
 * ConnectWise field). `q` matches identifier, description,
 * customer_description, manufacturer_part_number, AND vendor_sku --
 * confirmed live (a since-removed ?action=probe-fields diagnostic) that
 * ConnectWise has no barcode/UPC field on these items at all, so a rep
 * scanning a part's box label is almost always scanning one of those two,
 * not a separate UPC.
 *
 * GET  /register/api/catalog.php?action=sync-status
 * POST /register/api/catalog.php?action=sync-start
 * POST /register/api/catalog.php?action=sync-step
 *   { batch_size?: int (default 10, max 25) -- ignored during the listing
 *     stages, which always process exactly one page per call }
 *   -> { ok: true, phase?: 'list_agreement' | 'list_inventory' | 'filter' | 'sync',
 *          processed_this_batch?, list_stage, listing_totals: { agreement_listed,
 *          inventory_listed, filter_queued, skipped_cached }, filter_totals:
 *          { pending, error }, sync_totals: { pending, done, error }, done,
 *          errors?: [...], started_at?, elapsed_ms }
 *
 * Every action requires a signed-in retail staff account
 * (register_require_login()).
 */

declare(strict_types=1);

require_once __DIR__ . '/_util.php';
require_once __DIR__ . '/connectwise.php';

register_install_error_handlers();

// These MUST be declared before the action-dispatch block below runs --
// added 2026-09-13 after "Undefined constant" surfaced (via the new error
// handler above) as the real cause of what had looked like two separate
// sync timeouts. A top-level `const` outside a class is NOT hoisted the
// way function declarations are: it's only defined once the script's
// execution reaches that line. These constants used to sit down by the
// functions that use them, well after this dispatch block -- so the very
// first call into register_catalog_sync_step() (itself reached from the
// dispatch block, before execution ever got to the old `const` lines)
// failed immediately. Function declarations ARE hoisted regardless of
// where they appear, so only the constants needed to move.

/**
 * How long a confirmed-zero-stock Inventory item is trusted before it gets
 * re-checked. Bounds the ongoing cost of re-scanning ~14,000 legacy
 * catalog entries on every sync, while still catching an item that gets
 * restocked later.
 */
const REGISTER_NO_STOCK_RECHECK_DAYS = 3;

/**
 * How many items ConnectWise returns per catalog-list page during the
 * listing stages below.
 */
const REGISTER_CATALOG_LIST_PAGE_SIZE = 200;

/**
 * Added 2026-09-13 ("part 3", after a real-world sync still failed with the
 * generic "Sync failed partway through — check your connection and try
 * again" message around record ~2600, well past both the earlier
 * sync-start-timeout and const-hoisting bugs). That message only appears
 * client-side when fetch()/response.json() itself rejects -- i.e. the HTTP
 * response never came back as valid JSON at all, which happens when a
 * hosting-level gateway/proxy kills a request that's taking too long,
 * before this app's own register_install_error_handlers() safety net ever
 * gets a chance to run and return a real error. The filter and sync phases
 * each make one live ConnectWise call PER ITEM in a batch, sequentially, at
 * ConnectWise's own — often variable — response time, so a single slow
 * call was enough to push an otherwise-fine batch over that outside limit.
 *
 * Fix has two parts, both here and in connectwise.php:
 *   1. Each per-item ConnectWise call in the loops below now uses a much
 *      shorter cURL timeout (REGISTER_CW_ITEM_TIMEOUT_SECONDS) than
 *      register_cw_request()'s 60s default, so one hung/slow item fails
 *      fast (and is simply retried on a later sync-step, same as any other
 *      per-item error) instead of silently eating most of a request's
 *      available time.
 *   2. Each loop also tracks its own wall-clock time and stops early --
 *      returning whatever it already finished -- once
 *      REGISTER_SYNC_STEP_TIME_BUDGET_SECONDS has elapsed, always
 *      completing at least one item first so a call slower than the budget
 *      still makes forward progress. This bounds a sync-step request's
 *      worst-case runtime by wall time, not by guessing at a safe item
 *      count -- batch_size (still accepted, still capped at 25) now really
 *      just sets an upper limit for a fast run, not the primary safety net.
 *
 * Together, a single sync-step request should finish in well under 30s in
 * the worst realistic case (budget + one item's own timeout), comfortably
 * inside typical shared-hosting gateway limits, regardless of how many
 * items batch_size allows or how slow ConnectWise is being that moment.
 */
const REGISTER_CW_ITEM_TIMEOUT_SECONDS = 12;
const REGISTER_CW_ITEM_CONNECT_TIMEOUT_SECONDS = 4;
const REGISTER_SYNC_STEP_TIME_BUDGET_SECONDS = 8;

// Defensive backstop only -- the real risk this bug fix targets is a
// hosting-level gateway/proxy timeout this setting has no control over,
// but there's no reason to also risk PHP's own limit (some shared-hosting
// defaults are as low as 30s) cutting a request short before the time
// budget above gets a chance to return cleanly.
@ini_set('max_execution_time', '55');

$pdo = register_db();
register_require_login($pdo);

$action = $_GET['action'] ?? '';

if ($action === 'list') {
    $q = trim((string) ($_GET['q'] ?? ''));
    $inStockOnly = ($_GET['in_stock_only'] ?? '') === '1';
    // Type is derived, not a real column (see register_catalog_type_for_
    // category()) -- filtered in PHP after the query, not in SQL.
    $typeFilter = trim((string) ($_GET['type'] ?? ''));
    $categoryFilter = trim((string) ($_GET['category'] ?? ''));
    $subcategoryFilter = trim((string) ($_GET['subcategory'] ?? ''));
    // Exact-identifier batch lookup (added 2026-09-16, for the computer
    // upsell builder's fixed Protection Plan item list) -- comma-separated
    // identifiers, matched exactly (case-insensitive) rather than the
    // partial LIKE matching `q` does below, so "9999" can't accidentally
    // pull back other items that merely contain "9999" somewhere.
    $identifiersFilter = trim((string) ($_GET['identifiers'] ?? ''));

    $sql = 'SELECT id, cw_catalog_id, identifier, description, customer_description,
                   category_name, subcategory_name, manufacturer_part_number, vendor_sku,
                   unit_of_measure, price, on_hand, taxable_flag, product_class, track_inventory, synced_at
            FROM catalog_items
            WHERE inactive_flag = 0';
    $params = [];
    if ($q !== '') {
        $sql .= ' AND (identifier LIKE :q OR description LIKE :q OR customer_description LIKE :q
                        OR manufacturer_part_number LIKE :q OR vendor_sku LIKE :q)';
        $params[':q'] = '%' . $q . '%';
    }
    if ($inStockOnly) {
        // Agreement-class items (track_inventory = 0) have no physical
        // stock to be "out of" -- always included regardless of this
        // toggle. Only Inventory-class items get filtered by on_hand.
        $sql .= ' AND (track_inventory = 0 OR on_hand > 0)';
    }
    if ($categoryFilter !== '') {
        $sql .= ' AND category_name = :category';
        $params[':category'] = $categoryFilter;
    }
    if ($subcategoryFilter !== '') {
        $sql .= ' AND subcategory_name = :subcategory';
        $params[':subcategory'] = $subcategoryFilter;
    }
    if ($identifiersFilter !== '') {
        $idents = array_values(array_filter(array_map('trim', explode(',', $identifiersFilter)), function ($v) { return $v !== ''; }));
        if ($idents !== []) {
            $placeholders = [];
            foreach ($idents as $i => $ident) {
                $key = ':ident' . $i;
                $placeholders[] = $key;
                $params[$key] = $ident;
            }
            $sql .= ' AND identifier COLLATE NOCASE IN (' . implode(',', $placeholders) . ')';
        }
    }

    $sql .= ' ORDER BY identifier COLLATE NOCASE';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $filtered = [];
    foreach ($items as $item) {
        $item['id'] = (int) $item['id'];
        $item['cw_catalog_id'] = (int) $item['cw_catalog_id'];
        $item['price'] = (float) $item['price'];
        $item['on_hand'] = (float) $item['on_hand'];
        $item['taxable_flag'] = (int) $item['taxable_flag'];
        $item['track_inventory'] = (int) $item['track_inventory'];
        $item['type_name'] = register_catalog_type_for_category($item['category_name']);
        if ($typeFilter !== '' && $item['type_name'] !== $typeFilter) {
            continue;
        }
        $filtered[] = $item;
    }

    register_respond(200, ['ok' => true, 'items' => $filtered]);
}

if ($action === 'type-menu') {
    register_respond(200, ['ok' => true, 'menu' => register_catalog_type_menu($pdo)]);
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
    $result = register_catalog_sync_start($pdo);
    register_respond(200, array_merge(['ok' => true], $result));
}

if ($action === 'sync-step') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        register_respond(405, ['ok' => false, 'error' => 'Method not allowed.']);
    }
    $data = register_read_json_body();
    // Lowered from 20/50 to 10/25 on 2026-09-13 as extra headroom after a
    // "Sync failed partway through" failure -- each filter/sync-phase item
    // still costs one real ConnectWise round-trip, so a smaller batch
    // means less time any single request spends doing synchronous
    // ConnectWise work, at the cost of a few more (cheap, same-server)
    // sync-step round-trips overall.
    $batchSize = (int) ($data['batch_size'] ?? 10);
    $batchSize = max(1, min(25, $batchSize));
    // elapsed_ms is diagnostic only (added 2026-09-13 alongside the
    // per-step time budget) -- if a "check your connection" failure ever
    // recurs, whatever elapsed_ms the LAST successful step reported (the
    // browser console/network tab, or a future server-side log of it) is a
    // real data point instead of another guess.
    $stepStartedAt = microtime(true);
    $result = register_catalog_sync_step($pdo, $batchSize);
    $result['elapsed_ms'] = (int) round((microtime(true) - $stepStartedAt) * 1000);
    register_respond(200, array_merge(['ok' => true], $result));
}

register_respond(400, ['ok' => false, 'error' => 'Unknown action.']);

/**
 * Groups ConnectWise's own category_name into one of the 5 business lines
 * Michael wants the register's front page organized by: IT Services, Data
 * Center, Premise Security, Cabling, Voice over IP. Added 2026-09-14.
 *
 * This is NOT a real ConnectWise field -- confirmed via a live diagnostic
 * (register/api/catalog.php's now-removed ?action=probe-fields) that
 * catalog items DO carry a `type` field, but it's something else entirely
 * (e.g. "Hardware" for a patch cable, "IT Services" for a managed-anti-spam
 * Agreement item) -- essentially a duplicate of category.name in the
 * samples checked, nothing like the 5 lines Michael wants. His GL Accounts
 * Map's own "Type" column *does* line up with those 5 lines, but only
 * covers ConnectWise's Agreement/service-oriented categories (Carrier,
 * Cloud Hosting, Rack, Storage, Voice Hardware/Software, Premise Security,
 * Structured Cabling, IT Services, etc.) -- confirmed live that the actual
 * physical Inventory catalog (~1,815 of the ~2,600 synced items) sits under
 * one single ConnectWise category, "Hardware", covering everything from
 * patch cables to servers to laptops, which isn't in the GL map at all.
 *
 * Per Michael (2026-09-14, asked directly rather than guessed): generic
 * "Hardware"/"Software" items -- INCLUDING the Server/Network Device/
 * Storage subcategories, which could have plausibly read as Data Center
 * gear -- all land in the "IT Services" catch-all rather than being split
 * out. Only the categories ConnectWise already labels for a specific line
 * of business (Voice/Premise Security/Structured Cabling/Data-Center's own
 * service categories) get their own bucket.
 *
 * Any category_name not listed here (new to ConnectWise, or a typo/rename)
 * falls into 'Uncategorized' rather than silently vanishing from the menu
 * -- action=type-menu below surfaces that bucket like any other so it gets
 * noticed and this map can be extended.
 */
function register_catalog_type_for_category(?string $categoryName): string
{
    static $map = [
        // Voice over IP
        'Voice Hardware/Software' => 'Voice over IP',
        'Voice Service' => 'Voice over IP',
        'Voice Carrier' => 'Voice over IP',
        // Premise Security
        'Premise Security' => 'Premise Security',
        // Cabling (ConnectWise's own category is literally "Structured Cabling")
        'Structured Cabling' => 'Cabling',
        // Data Center -- ConnectWise's cloud/carrier/rack/storage service categories
        'Carrier' => 'Data Center',
        'Cloud' => 'Data Center',
        'Cloud Hosting' => 'Data Center',
        'Data Center Service' => 'Data Center',
        'Rack' => 'Data Center',
        'Storage' => 'Data Center',
        // IT Services -- explicit IT Services category, plus every generic
        // physical/software catch-all (Michael's call, 2026-09-14)
        'IT Services' => 'IT Services',
        'Hardware' => 'IT Services',
        'Software' => 'IT Services',
    ];
    if ($categoryName === null || $categoryName === '') {
        return 'Uncategorized';
    }
    return $map[$categoryName] ?? 'Uncategorized';
}

/**
 * Builds the nested Type > Category > SubCategory tree action=type-menu
 * responds with, powering the register front page's browse menu. Built
 * from whatever's actually in catalog_items right now, not a hardcoded
 * list, so it never shows an empty branch or misses a category ConnectWise
 * adds later -- anything register_catalog_type_for_category() doesn't
 * recognize falls into 'Uncategorized' rather than silently vanishing.
 * Pulled out as its own function (rather than left inline in the
 * action=type-menu dispatch block) so it's directly unit-testable.
 *
 * Returns a list ordered as [{ type, categories: { categoryName:
 * [subcategoryName, ...] } }, ...] -- the 5 business lines Michael asked
 * for, in the order he listed them, with 'Uncategorized' (if present)
 * always sorting last so it doesn't read as a 6th intentional line.
 */
function register_catalog_type_menu(PDO $pdo): array
{
    $rows = $pdo->query(
        "SELECT DISTINCT category_name, subcategory_name FROM catalog_items
         WHERE inactive_flag = 0 AND category_name IS NOT NULL
         ORDER BY category_name, subcategory_name"
    )->fetchAll(PDO::FETCH_ASSOC);

    $tree = [];
    foreach ($rows as $row) {
        $type = register_catalog_type_for_category($row['category_name']);
        $category = $row['category_name'];
        $subcategory = $row['subcategory_name'];
        if (!isset($tree[$type])) {
            $tree[$type] = [];
        }
        if (!isset($tree[$type][$category])) {
            $tree[$type][$category] = [];
        }
        if ($subcategory !== null && $subcategory !== '' && !in_array($subcategory, $tree[$type][$category], true)) {
            $tree[$type][$category][] = $subcategory;
        }
    }

    $typeOrder = ['IT Services', 'Data Center', 'Premise Security', 'Cabling', 'Voice over IP'];
    $menu = [];
    foreach ($typeOrder as $type) {
        if (isset($tree[$type])) {
            $menu[] = ['type' => $type, 'categories' => $tree[$type]];
            unset($tree[$type]);
        }
    }
    foreach ($tree as $type => $categories) {
        $menu[] = ['type' => $type, 'categories' => $categories];
    }

    return $menu;
}

/**
 * Simple get/set helpers over register_sync_meta, namespaced under
 * 'catalog_sync_' so sync-start can reset them all without disturbing any
 * other key (e.g. catalog_started_at) that table might ever hold.
 */
function register_catalog_sync_meta_get(PDO $pdo, string $key, string $default): string
{
    $stmt = $pdo->prepare('SELECT value FROM register_sync_meta WHERE key = :k');
    $stmt->execute([':k' => 'catalog_sync_' . $key]);
    $value = $stmt->fetchColumn();
    return $value !== false ? (string) $value : $default;
}

function register_catalog_sync_meta_set(PDO $pdo, string $key, string $value): void
{
    $pdo->prepare("INSERT INTO register_sync_meta (key, value) VALUES (:k, :v) ON CONFLICT(key) DO UPDATE SET value = excluded.value")
        ->execute([':k' => 'catalog_sync_' . $key, ':v' => $value]);
}

function register_catalog_sync_meta_add(PDO $pdo, string $key, int $amount): void
{
    $current = (int) register_catalog_sync_meta_get($pdo, $key, '0');
    register_catalog_sync_meta_set($pdo, $key, (string) ($current + $amount));
}

/**
 * Resets a fresh sync run. Deliberately does NOT talk to ConnectWise at
 * all -- confirmed 2026-09-13 that even the cheap id/identifier-only
 * catalog list calls this sync depends on require dozens of paginated
 * ConnectWise round-trips once you account for the ~14,600 active
 * Inventory-class candidates (pageSize 200 -> ~73 pages), and doing all of
 * that synchronously inside one request risked exactly the kind of
 * execution-time-limit failure ("Could not start the sync — check your
 * connection and try again", a non-JSON timeout response, not a graceful
 * error) that this whole queue/step architecture exists to avoid. So
 * listing ConnectWise's catalog is now its OWN bounded, page-at-a-time
 * stage handled by sync-step (see register_catalog_sync_list_step()) --
 * sync-start just clears state and points the queue at page 1.
 */
function register_catalog_sync_start(PDO $pdo): array
{
    $pdo->exec('DELETE FROM cw_catalog_sync_queue');
    foreach (['list_stage', 'agreement_page', 'inventory_page', 'total_agreement_listed', 'total_inventory_listed', 'total_filter_queued', 'total_skipped_cached'] as $key) {
        $pdo->prepare('DELETE FROM register_sync_meta WHERE key = :k')->execute([':k' => 'catalog_sync_' . $key]);
    }
    register_catalog_sync_meta_set($pdo, 'list_stage', 'agreement');
    register_catalog_sync_meta_set($pdo, 'agreement_page', '1');

    $pdo->prepare("INSERT INTO register_sync_meta (key, value) VALUES ('catalog_started_at', :v) ON CONFLICT(key) DO UPDATE SET value = excluded.value")
        ->execute([':v' => date('c')]);

    return ['ok' => true];
}

/**
 * One page of ConnectWise's Procurement Catalog list, for whichever
 * listing stage is still in progress -- 'agreement' first (a handful of
 * pages), then 'inventory' (~73 pages at the confirmed ~14,600-item scale).
 * Each call to this function does exactly ONE ConnectWise request, so no
 * matter how large the catalog grows, a single sync-step call can never
 * make more than one round-trip during a listing stage.
 *
 * Agreement items are queued straight to phase='sync' (matches Michael's
 * live ConnectWise count exactly -- no stock check needed, see catalog.php's
 * header docblock). Inventory candidates are queued to phase='filter'
 * UNLESS already in catalog_no_stock_cache within REGISTER_NO_STOCK_RECHECK_DAYS,
 * in which case they're skipped entirely -- this is what keeps every sync
 * after the first one fast.
 */
function register_catalog_sync_list_step(PDO $pdo, string $stage): array
{
    if ($stage === 'agreement') {
        $page = (int) register_catalog_sync_meta_get($pdo, 'agreement_page', '1');
        $rows = register_cw_list_page('/procurement/catalog', "productClass='Agreement' and inactiveFlag=false", ['id', 'identifier'], $page, REGISTER_CATALOG_LIST_PAGE_SIZE);

        $insert = $pdo->prepare("INSERT INTO cw_catalog_sync_queue (cw_catalog_id, identifier, phase, status) VALUES (:id, :identifier, 'sync', 'pending')");
        foreach ($rows as $item) {
            $cwId = (int) ($item['id'] ?? 0);
            if ($cwId === 0) {
                continue;
            }
            $insert->execute([':id' => $cwId, ':identifier' => (string) ($item['identifier'] ?? '')]);
        }
        register_catalog_sync_meta_add($pdo, 'total_agreement_listed', count($rows));

        if (count($rows) < REGISTER_CATALOG_LIST_PAGE_SIZE) {
            register_catalog_sync_meta_set($pdo, 'list_stage', 'inventory');
            register_catalog_sync_meta_set($pdo, 'inventory_page', '1');
        } else {
            register_catalog_sync_meta_set($pdo, 'agreement_page', (string) ($page + 1));
        }

        return array_merge(['phase' => 'list_agreement', 'processed_this_batch' => count($rows)], register_catalog_sync_totals($pdo));
    }

    // $stage === 'inventory'
    $page = (int) register_catalog_sync_meta_get($pdo, 'inventory_page', '1');
    $rows = register_cw_list_page('/procurement/catalog', "productClass='Inventory' and inactiveFlag=false", ['id', 'identifier'], $page, REGISTER_CATALOG_LIST_PAGE_SIZE);

    $noStockCache = $pdo->query('SELECT cw_catalog_id, checked_at FROM catalog_no_stock_cache')->fetchAll(PDO::FETCH_KEY_PAIR);
    $staleBefore = date('c', strtotime('-' . REGISTER_NO_STOCK_RECHECK_DAYS . ' days'));
    $insertFilter = $pdo->prepare("INSERT INTO cw_catalog_sync_queue (cw_catalog_id, identifier, phase, status) VALUES (:id, :identifier, 'filter', 'pending')");

    $queued = 0;
    $skipped = 0;
    foreach ($rows as $item) {
        $cwId = (int) ($item['id'] ?? 0);
        if ($cwId === 0) {
            continue;
        }
        $checkedAt = $noStockCache[$cwId] ?? null;
        if ($checkedAt !== null && $checkedAt > $staleBefore) {
            $skipped++;
            continue;
        }
        $insertFilter->execute([':id' => $cwId, ':identifier' => (string) ($item['identifier'] ?? '')]);
        $queued++;
    }
    register_catalog_sync_meta_add($pdo, 'total_inventory_listed', count($rows));
    register_catalog_sync_meta_add($pdo, 'total_filter_queued', $queued);
    register_catalog_sync_meta_add($pdo, 'total_skipped_cached', $skipped);

    if (count($rows) < REGISTER_CATALOG_LIST_PAGE_SIZE) {
        register_catalog_sync_meta_set($pdo, 'list_stage', 'items');
    } else {
        register_catalog_sync_meta_set($pdo, 'inventory_page', (string) ($page + 1));
    }

    return array_merge(['phase' => 'list_inventory', 'processed_this_batch' => count($rows)], register_catalog_sync_totals($pdo));
}

/**
 * Processes up to $batchSize queue rows for whichever phase still has
 * pending work -- the catalog-listing stages above always run first (see
 * register_catalog_sync_list_step()), then filter-phase rows, then
 * sync-phase rows, so a caller looping this until done:true sees a clean
 * sequence of phases, never an interleaved mix.
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
    $listStage = register_catalog_sync_meta_get($pdo, 'list_stage', 'items');
    if ($listStage === 'agreement' || $listStage === 'inventory') {
        return register_catalog_sync_list_step($pdo, $listStage);
    }

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

        $batchStartedAt = microtime(true);

        foreach ($filterRows as $row) {
            $cwId = (int) $row['cw_catalog_id'];
            try {
                $inventoryRows = register_cw_request(
                    '/procurement/catalog/' . $cwId . '/inventory',
                    [],
                    'GET',
                    null,
                    REGISTER_CW_ITEM_TIMEOUT_SECONDS,
                    REGISTER_CW_ITEM_CONNECT_TIMEOUT_SECONDS
                );
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

            // Stop early once this call's own time budget is spent -- see
            // REGISTER_SYNC_STEP_TIME_BUDGET_SECONDS's docblock above.
            // Always finishes the item already in flight (accounted for by
            // checking AFTER incrementing $processed) so one slow-but-under-
            // its-own-timeout item can't cause a batch of zero progress.
            if ((microtime(true) - $batchStartedAt) >= REGISTER_SYNC_STEP_TIME_BUDGET_SECONDS) {
                break;
            }
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
             subcategory_name, manufacturer_part_number, vendor_sku, unit_of_measure, price, cost,
             on_hand, taxable_flag, inactive_flag, product_class, track_inventory, synced_at)
         VALUES
            (:cw_id, :identifier, :description, :customer_description, :category_name,
             :subcategory_name, :manufacturer_part_number, :vendor_sku, :unit_of_measure, :price, :cost,
             :on_hand, :taxable_flag, :inactive_flag, :product_class, :track_inventory, datetime(\'now\'))
         ON CONFLICT(cw_catalog_id) DO UPDATE SET
            identifier = excluded.identifier,
            description = excluded.description,
            customer_description = excluded.customer_description,
            category_name = excluded.category_name,
            subcategory_name = excluded.subcategory_name,
            manufacturer_part_number = excluded.manufacturer_part_number,
            vendor_sku = excluded.vendor_sku,
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

    $batchStartedAt = microtime(true);

    foreach ($syncRows as $row) {
        $cwId = (int) $row['cw_catalog_id'];
        try {
            $item = register_cw_request(
                '/procurement/catalog/' . $cwId,
                [],
                'GET',
                null,
                REGISTER_CW_ITEM_TIMEOUT_SECONDS,
                REGISTER_CW_ITEM_CONNECT_TIMEOUT_SECONDS
            );
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
                ':manufacturer_part_number' => $item['manufacturerPartNumber'] ?? null,
                ':vendor_sku' => $item['vendorSku'] ?? null,
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

        if ((microtime(true) - $batchStartedAt) >= REGISTER_SYNC_STEP_TIME_BUDGET_SECONDS) {
            break;
        }
    }

    return array_merge(['phase' => 'sync', 'processed_this_batch' => $processed, 'errors' => $errors], register_catalog_sync_totals($pdo));
}

/**
 * Current queue state, broken out by phase, plus an overall `done` flag
 * (true only once catalog listing has finished AND both the filter and
 * sync phases have nothing left pending -- listing must be checked too,
 * since right after sync-start every queue count is legitimately zero even
 * though there's a whole catalog left to list).
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
    $listStage = register_catalog_sync_meta_get($pdo, 'list_stage', 'items');

    return [
        'list_stage' => $listStage,
        'listing_totals' => [
            'agreement_listed' => (int) register_catalog_sync_meta_get($pdo, 'total_agreement_listed', '0'),
            'inventory_listed' => (int) register_catalog_sync_meta_get($pdo, 'total_inventory_listed', '0'),
            'filter_queued' => (int) register_catalog_sync_meta_get($pdo, 'total_filter_queued', '0'),
            'skipped_cached' => (int) register_catalog_sync_meta_get($pdo, 'total_skipped_cached', '0'),
        ],
        'filter_totals' => $filterTotals,
        'sync_totals' => $syncTotals,
        'done' => $listStage === 'items' && $filterTotals['pending'] === 0 && $syncTotals['pending'] === 0,
    ];
}
