<?php
/**
 * register/api/db.php
 *
 * Opens (and, on first run, creates) the Register app's SQLite database.
 * Self-contained sub-app, same pattern as relationships/api/db.php — its
 * own accounts table (retail staff, not CRCs) and its own tables for the
 * synced ConnectWise catalog + the sales this app records.
 *
 * The database file lives in register/data/, which is:
 *   - listed in .gitignore (runtime data, not source — a `git pull` deploy
 *     must never overwrite or wipe it)
 *   - blocked from direct HTTP access by register/data/.htaccess (same
 *     "Deny from all" treatment as relationships/data/)
 *
 * Every other register/api/*.php file starts with:
 *   require __DIR__ . '/db.php';
 *   $pdo = register_db();
 */

declare(strict_types=1);

function register_db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $dataDir = __DIR__ . '/../data';
    if (!is_dir($dataDir)) {
        mkdir($dataDir, 0770, true);
    }
    $dbPath = $dataDir . '/register.sqlite';

    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode = WAL');

    register_migrate($pdo);

    return $pdo;
}

function register_migrate(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS register_users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        )
    SQL);

    // One row per ConnectWise Procurement Catalog item synced into this
    // app -- confirmed field names/shapes come from a live diagnostic
    // (relationships/api/cw-catalog-probe.php, 2026-09-12): the catalog
    // list endpoint (/procurement/catalog) gives id/identifier/description/
    // customerDescription/category/subcategory/unitOfMeasure/price/cost/
    // productClass/inactiveFlag, and on-hand quantity is NOT one of those
    // fields -- it's the sum of `onHand` across every row returned by
    // /procurement/catalog/{id}/inventory (one row per warehouse/bin).
    //
    // Two productClass values are synced (see catalog.php):
    //   - 'Inventory' -- a real physical item CBT stocks. track_inventory=1,
    //     on_hand is the real summed quantity, checkout enforces it.
    //   - 'Agreement' -- added 2026-09-13 per Michael, confirmed via a live
    //     probe (register/api/catalog.php's now-removed
    //     ?action=probe-agreement-class): recurring-protection/managed-
    //     service products (e.g. "Basic Managed Anti-Spam", "Managed
    //     Backup") that retail staff can ring up at time of sale. Per
    //     Michael, this rings up as a plain line item on the receipt only
    //     -- it does NOT create or attach a real ConnectWise Agreement, so
    //     there's no physical stock to track: track_inventory=0, on_hand is
    //     unused/always 0, checkout never limits quantity for these.
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS catalog_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            cw_catalog_id INTEGER NOT NULL UNIQUE,
            identifier TEXT NOT NULL,
            description TEXT NOT NULL DEFAULT '',
            customer_description TEXT NOT NULL DEFAULT '',
            category_name TEXT,
            subcategory_name TEXT,
            unit_of_measure TEXT,
            price REAL NOT NULL DEFAULT 0,
            cost REAL NOT NULL DEFAULT 0,
            on_hand REAL NOT NULL DEFAULT 0,
            taxable_flag INTEGER NOT NULL DEFAULT 1,
            inactive_flag INTEGER NOT NULL DEFAULT 0,
            product_class TEXT NOT NULL DEFAULT 'Inventory',
            track_inventory INTEGER NOT NULL DEFAULT 1,
            synced_at TEXT NOT NULL DEFAULT (datetime('now'))
        )
    SQL);
    register_add_column_if_missing($pdo, 'catalog_items', 'product_class', "TEXT NOT NULL DEFAULT 'Inventory'");
    register_add_column_if_missing($pdo, 'catalog_items', 'track_inventory', 'INTEGER NOT NULL DEFAULT 1');
    // Added 2026-09-14 for the Type/Category/SubCategory catalog reorg --
    // confirmed live (register/api/catalog.php's now-removed
    // ?action=probe-fields) that ConnectWise catalog items have no
    // barcode/UPC field at all, but DO carry a real `manufacturerPartNumber`
    // and `vendorSku`. A rep scanning a part's box label is almost always
    // scanning one of these (a UPC sticker specifically was never found),
    // so both are now synced and searchable alongside identifier.
    register_add_column_if_missing($pdo, 'catalog_items', 'manufacturer_part_number', 'TEXT');
    register_add_column_if_missing($pdo, 'catalog_items', 'vendor_sku', 'TEXT');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_catalog_items_identifier ON catalog_items(identifier COLLATE NOCASE)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_catalog_items_on_hand ON catalog_items(on_hand)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_catalog_items_mfg_part ON catalog_items(manufacturer_part_number COLLATE NOCASE)');

    // One row per completed checkout. subtotal/tax_amount/total are stored
    // (not recomputed from sale_items later) so a sale's recorded total
    // never silently drifts if catalog prices change after the fact.
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS sales (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL REFERENCES register_users(id),
            subtotal REAL NOT NULL DEFAULT 0,
            tax_amount REAL NOT NULL DEFAULT 0,
            total REAL NOT NULL DEFAULT 0,
            payment_method TEXT NOT NULL,
            payment_reference TEXT,
            customer_name TEXT,
            note TEXT,
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        )
    SQL);
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_sales_created_at ON sales(created_at)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_sales_user ON sales(user_id)');

    // Line items for a sale. catalog_item_id is nullable + ON DELETE SET
    // NULL: a sale's history must survive even if the catalog item is
    // later removed from ConnectWise/stops syncing -- identifier/
    // description/unit_price are copied onto the row at sale time so the
    // receipt/history never depends on the catalog_items row still
    // existing or still matching what it said at checkout.
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS sale_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            sale_id INTEGER NOT NULL REFERENCES sales(id) ON DELETE CASCADE,
            catalog_item_id INTEGER REFERENCES catalog_items(id) ON DELETE SET NULL,
            identifier TEXT NOT NULL,
            description TEXT NOT NULL DEFAULT '',
            unit_price REAL NOT NULL DEFAULT 0,
            quantity REAL NOT NULL DEFAULT 1,
            line_total REAL NOT NULL DEFAULT 0
        )
    SQL);
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_sale_items_sale ON sale_items(sale_id)');

    // Queue of ConnectWise Procurement Catalog items to (re)sync -- added
    // 2026-09-12 after a real "Sync failed — check your connection" error
    // on a first live attempt (it ran ~4 minutes before failing): a single
    // synchronous request doing one full-item GET + one /inventory GET per
    // catalog item was always going to risk Bluehost's execution-time
    // limit once there are more than a handful of Inventory items, exactly
    // as flagged in this file's original header comment. Converted to the
    // same start()/step()-bounded-batch queue shape relationships/api/
    // sync.php already uses for its much larger (~440 agreement) sync --
    // see catalog.php's action=sync-start/sync-step/sync-status.
    //
    // `phase` added 2026-09-13: confirmed via live diagnostics that
    // productClass='Inventory' matches ~14,600 active catalog items, but
    // only ~603 of them actually have on-hand stock right now (the rest are
    // legacy/discontinued catalog entries) -- and ConnectWise gives no
    // server-side way to filter the catalog list by on-hand status
    // (productClass alone doesn't distinguish a zero-stock item from a
    // stocked one; childconditions and every bin/warehouse inventory-list
    // endpoint tried came back unsupported or 404). So every Inventory
    // candidate needs its own cheap /inventory-only check first
    // (phase='filter') before the ~603 (plus all Agreement items, which
    // skip straight to phase='sync') get a full detail sync. `cached_on_hand`
    // carries the summed on-hand total computed during the filter check
    // forward into the sync phase, so it's never fetched twice for the same
    // item.
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS cw_catalog_sync_queue (
            cw_catalog_id INTEGER PRIMARY KEY,
            identifier TEXT NOT NULL DEFAULT '',
            phase TEXT NOT NULL DEFAULT 'sync',
            cached_on_hand REAL,
            status TEXT NOT NULL DEFAULT 'pending',
            error_message TEXT,
            queued_at TEXT NOT NULL DEFAULT (datetime('now')),
            processed_at TEXT
        )
    SQL);
    register_add_column_if_missing($pdo, 'cw_catalog_sync_queue', 'phase', "TEXT NOT NULL DEFAULT 'sync'");
    register_add_column_if_missing($pdo, 'cw_catalog_sync_queue', 'cached_on_hand', 'REAL');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_cw_catalog_sync_queue_status ON cw_catalog_sync_queue(phase, status)');

    // Persists which Inventory-class catalog items were last confirmed to
    // have ZERO on-hand stock, and when -- added 2026-09-13 alongside the
    // `phase` column above, so a full ~14,600-item /inventory scan only
    // ever has to happen once. Every later sync skips re-checking any item
    // still in here within the staleness window (see
    // register_catalog_sync_start()'s $staleDays), and only re-verifies the
    // ~603 already-known-stocked items plus whatever has aged out --
    // instead of re-scanning the entire legacy catalog every time.
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS catalog_no_stock_cache (
            cw_catalog_id INTEGER PRIMARY KEY,
            identifier TEXT NOT NULL DEFAULT '',
            checked_at TEXT NOT NULL DEFAULT (datetime('now'))
        )
    SQL);
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_catalog_no_stock_cache_checked_at ON catalog_no_stock_cache(checked_at)');

    // Small key/value table for sync run bookkeeping (started_at of the
    // current/most recent run) -- same shape as relationships' cw_sync_meta.
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS register_sync_meta (
            key TEXT PRIMARY KEY,
            value TEXT
        )
    SQL);
}

/**
 * SQLite has no "ADD COLUMN IF NOT EXISTS" -- check PRAGMA table_info first
 * so re-running migrate() on a database that already has the column (every
 * request after the first deploy of a schema change) is a no-op instead of
 * an error. Same helper as relationships/api/db.php.
 */
function register_add_column_if_missing(PDO $pdo, string $table, string $column, string $type): void
{
    $stmt = $pdo->query('PRAGMA table_info(' . $table . ')');
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $col) {
        if ($col['name'] === $column) {
            return;
        }
    }
    $pdo->exec("ALTER TABLE $table ADD COLUMN $column $type");
}
