<?php
/**
 * client-finance/api/db.php
 *
 * SQLite store for the "Client Finance" sub-app (added 2026-10-16, per
 * Michael: a per-customer financial dashboard pulled live from
 * ConnectWise, built for pulling up on screen during a client meeting).
 * Lives in client-finance/data/ (git-ignored, HTTP-blocked by .htaccess),
 * same pattern as relationships/data and commissions/data.
 *
 * Deliberately does NOT duplicate customer identity/classification data --
 * relationships/api/db.php's `customers` table (and `contacts`, for
 * name/email search) is this app's single source of truth for "who is
 * this company and what's its ConnectWise id", read cross-app exactly the
 * way commissions/ already reuses relationships/api/connectwise.php (see
 * client-finance/api/customers.php). Every table here is keyed by
 * `cw_company_id` (ConnectWise's own Company id), the one identifier both
 * apps already share, rather than relationships' local `customers.id`.
 *
 * This is financial data synced ON DEMAND, per customer, when a rep opens
 * that customer's dashboard -- not a big nightly sync of every customer
 * like relationships' agreement sync. A rep walking into a meeting wants
 * THIS customer's numbers fresh, not whatever a nightly cron produced for
 * all 1,000+ companies. `company_sync_state.synced_at` (null = never) is
 * what the UI checks to show "Not yet synced -- click to sync" instead of
 * a chart that would otherwise look like a confirmed $0, same spirit as
 * relationships' `billing_synced_at` (see relationships-connectwise-
 * sync.md). See sync-core.php for the actual queue/step sync logic -- as
 * of this commit the real ConnectWise field mapping is still pending
 * Michael running client-finance/api/probe.php against live data, so the
 * sync functions there are structured but stubbed (see that file's
 * header) and every table below exists but stays empty until that's
 * wired in.
 *
 * Tables:
 *   company_sync_state  one row per customer ever opened here: last
 *                       synced timestamp + per-section sync status
 *   invoices            Standard/T&M, Agreement, Credit Memo and Sales-
 *                       Order-sourced invoices, classified into `category`
 *   invoice_lines       the product/time/credit line detail of each
 *                       invoice -- ticket reference included so a line
 *                       can show "what this was for"
 *   agreements          one row per ConnectWise agreement for the company
 *   agreement_additions line-level agreement detail: price, cost, start/
 *                       cancelled dates (relationships/customer_services
 *                       has none of this -- pillar/service classification
 *                       only)
 *   opportunities        flagged Sales-Order lines (Computer/Networking
 *                       hardware, 3+ years old) -- the "Opportunity" scan
 *   sync_queue / sync_state   per-company batched sync plumbing, same
 *                       start()/step() shape as every other sync in this
 *                       codebase (Bluehost execution-time safety)
 */

declare(strict_types=1);

function client_finance_db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $dataDir = __DIR__ . '/../data';
    if (!is_dir($dataDir)) {
        mkdir($dataDir, 0770, true);
    }
    $pdo = new PDO('sqlite:' . $dataDir . '/client-finance.sqlite');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA busy_timeout = 8000');

    client_finance_migrate($pdo);
    return $pdo;
}

function client_finance_migrate(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS settings (
            key TEXT PRIMARY KEY,
            value TEXT NOT NULL
        );

        -- One row per ConnectWise company id ever opened in this app.
        CREATE TABLE IF NOT EXISTS company_sync_state (
            cw_company_id INTEGER PRIMARY KEY,
            company_name TEXT NOT NULL DEFAULT '',
            standard_synced_at TEXT,
            product_sales_synced_at TEXT,
            agreements_synced_at TEXT,
            credits_synced_at TEXT,
            opportunities_synced_at TEXT,
            last_error TEXT
        );

        -- Standard/T&M, Agreement, Credit Memo and Sales-Order-sourced
        -- invoices, one row per ConnectWise invoice. `category` is this
        -- app's own classification (standard | agreement | credit |
        -- sales_order | other), derived at sync time from ConnectWise's
        -- `type` + `applyToType` fields -- see sync-core.php.
        CREATE TABLE IF NOT EXISTS invoices (
            id INTEGER PRIMARY KEY,              -- ConnectWise invoice id
            cw_company_id INTEGER NOT NULL,
            invoice_number TEXT,
            invoice_date TEXT,                   -- YYYY-MM-DD
            month TEXT,                          -- YYYY-MM, for the trend queries
            cw_type TEXT NOT NULL DEFAULT '',     -- raw ConnectWise `type`
            cw_apply_to_type TEXT NOT NULL DEFAULT '', -- raw ConnectWise `applyToType`
            category TEXT NOT NULL DEFAULT 'other',
            agreement_id INTEGER,
            total REAL NOT NULL DEFAULT 0,
            synced_at TEXT NOT NULL
        );
        CREATE INDEX IF NOT EXISTS idx_cf_invoices_company_category ON invoices(cw_company_id, category, month);

        -- Line-level detail: product lines (Product Sales / Sales Orders),
        -- time lines (Standard/T&M), and credit lines (Credits). `kind`
        -- distinguishes them; fields not relevant to a given kind stay
        -- null/zero.
        CREATE TABLE IF NOT EXISTS invoice_lines (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            invoice_id INTEGER NOT NULL REFERENCES invoices(id) ON DELETE CASCADE,
            kind TEXT NOT NULL,                  -- product | time | credit
            description TEXT NOT NULL DEFAULT '',
            product_identifier TEXT,
            catalog_id INTEGER,                   -- ConnectWise procurement/catalog id (product lines only)
            catalog_category TEXT,                -- Procurement Catalog `category.name` (product lines only)
            catalog_subcategory TEXT,              -- Procurement Catalog `subcategory.name` (product lines only)
            qty REAL,
            unit_cost REAL,
            unit_price REAL,
            cost REAL NOT NULL DEFAULT 0,         -- extended
            price REAL NOT NULL DEFAULT 0,        -- extended
            line_date TEXT,                       -- when known separately from the invoice date
            ticket_id INTEGER,
            ticket_summary TEXT,
            project_id INTEGER,
            project_name TEXT,
            sales_order_id INTEGER,                -- product lines: confirmed real tie-back is Sales Order + Opportunity, not a ticket (probe-product-lines)
            sales_order_identifier TEXT,
            opportunity_id INTEGER,
            opportunity_name TEXT
        );
        CREATE INDEX IF NOT EXISTS idx_cf_lines_invoice ON invoice_lines(invoice_id);

        -- One row per ConnectWise agreement for the company (financial
        -- summary only -- relationships/customer_services already owns
        -- pillar/service classification, this is deliberately not that).
        CREATE TABLE IF NOT EXISTS agreements (
            id INTEGER PRIMARY KEY,              -- ConnectWise agreement id
            cw_company_id INTEGER NOT NULL,
            name TEXT NOT NULL DEFAULT '',
            type_id INTEGER,
            type_name TEXT,
            status TEXT,
            start_date TEXT,
            cancelled_date TEXT,
            synced_at TEXT NOT NULL
        );
        CREATE INDEX IF NOT EXISTS idx_cf_agreements_company ON agreements(cw_company_id);

        -- Line-level agreement detail (additions): price/cost, start/
        -- cancelled dates per addition, per Michael's "like in collections"
        -- request for line-level cost/price visibility.
        CREATE TABLE IF NOT EXISTS agreement_additions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            agreement_id INTEGER NOT NULL REFERENCES agreements(id) ON DELETE CASCADE,
            cw_addition_id INTEGER,
            product_identifier TEXT,
            description TEXT NOT NULL DEFAULT '',
            qty REAL,
            unit_cost REAL,
            unit_price REAL,
            start_date TEXT,
            cancelled_date TEXT
        );
        CREATE INDEX IF NOT EXISTS idx_cf_additions_agreement ON agreement_additions(agreement_id);

        -- "Opportunity" scan results: a Sales-Order product line, 3+ years
        -- old, classified Computer or Networking hardware. One row per
        -- flagged line. `status` tracks whether it's been turned into a
        -- Relationships To-Do yet (per Michael's "take the Potential
        -- Opportunity into the Relationship app ... as a To-Do" button).
        CREATE TABLE IF NOT EXISTS opportunities (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            cw_company_id INTEGER NOT NULL,
            invoice_line_id INTEGER NOT NULL REFERENCES invoice_lines(id) ON DELETE CASCADE,
            category TEXT NOT NULL,              -- computer | networking
            subcategory TEXT,                    -- Desktop | Laptop | Server | Network Device | ...
            match_confidence TEXT NOT NULL DEFAULT 'clean', -- clean (subcategory alone) | keyword (catch-all subcategory + description keyword, see client-finance-probe-findings.md #6)
            sold_date TEXT NOT NULL,
            years_ago REAL NOT NULL,
            status TEXT NOT NULL DEFAULT 'open',  -- open | actioned | dismissed
            actioned_at TEXT,
            actioned_by_name TEXT,
            relationships_todo_id INTEGER,        -- the general_todos.id this became, if any
            created_at TEXT NOT NULL
        );
        CREATE INDEX IF NOT EXISTS idx_cf_opportunities_company ON opportunities(cw_company_id, status);

        -- Per-company batched sync plumbing (Bluehost execution-time
        -- safety), same start()/step() shape as relationships' and
        -- commissions' syncs. One sync run = one company.
        CREATE TABLE IF NOT EXISTS sync_queue (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            cw_company_id INTEGER NOT NULL,
            section TEXT NOT NULL,                -- standard | product_sales | agreements | credits | opportunities
            payload TEXT,
            status TEXT NOT NULL DEFAULT 'pending', -- pending | done | error
            error_message TEXT
        );
        CREATE INDEX IF NOT EXISTS idx_cf_sync_queue_status ON sync_queue(status);
        CREATE TABLE IF NOT EXISTS sync_state (
            key TEXT PRIMARY KEY,
            value TEXT
        );
    SQL);

    $defaults = ['opportunity_years_threshold' => '3'];
    $ins = $pdo->prepare('INSERT OR IGNORE INTO settings (key, value) VALUES (:k, :v)');
    foreach ($defaults as $k => $v) {
        $ins->execute([':k' => $k, ':v' => $v]);
    }
}

function client_finance_setting(PDO $pdo, string $key, string $default = ''): string
{
    $stmt = $pdo->prepare('SELECT value FROM settings WHERE key = :k');
    $stmt->execute([':k' => $key]);
    $v = $stmt->fetchColumn();
    return $v === false ? $default : (string) $v;
}
