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
    // Only productClass='Inventory' items are synced at all (see
    // catalog-sync.php) -- that's what separates a real physical item CBT
    // stocks from a recurring/service line item found on agreements, per
    // Michael's "On Hand" distinction.
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
            synced_at TEXT NOT NULL DEFAULT (datetime('now'))
        )
    SQL);
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_catalog_items_identifier ON catalog_items(identifier COLLATE NOCASE)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_catalog_items_on_hand ON catalog_items(on_hand)');

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
}
