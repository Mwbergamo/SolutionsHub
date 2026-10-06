<?php
/**
 * commissions/api/db.php
 *
 * SQLite store for the Commissions sub-app (added 2026-10-05). Lives in
 * commissions/data/ (git-ignored, HTTP-blocked by .htaccess), same pattern
 * as relationships/data. Holds:
 *
 *   settings          key/value (labor cost per hour)
 *   reps              the PAYEES (Chester, Arcus, Moe, Pivotal): commission %
 *                     of gross profit and which ConnectWise territory words
 *                     pay them; a territory can pay several (a split)
 *   line_commissions  one row per invoice line per payee
 *   invoices          one row per ConnectWise invoice in scope
 *   invoice_lines     the product / time / agreement lines of each invoice
 *                     with the assumed cost, price, % and commission $
 *   months            which calendar months are LOCKED (frozen history)
 *   catalog_cache     product catalog cost/price by catalog item id
 *   company_cache     ConnectWise company -> territory
 *   agreement_cache   agreement start date + additions
 *   sync_queue / sync_state   the batched sync's work list and progress
 *   settings_audit    who changed what (rates are sensitive)
 */

declare(strict_types=1);

function commissions_db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $dataDir = __DIR__ . '/../data';
    if (!is_dir($dataDir)) {
        mkdir($dataDir, 0770, true);
    }
    $pdo = new PDO('sqlite:' . $dataDir . '/commissions.sqlite');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA busy_timeout = 8000');

    commissions_migrate($pdo);
    return $pdo;
}

function commissions_migrate(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS settings (
            key TEXT PRIMARY KEY,
            value TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS reps (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE,
            sort_order INTEGER NOT NULL DEFAULT 0,
            territory_match TEXT NOT NULL DEFAULT '',
            base_pct REAL NOT NULL DEFAULT 0,
            agreement_after_year_pct REAL,
            active INTEGER NOT NULL DEFAULT 1
        );
        CREATE TABLE IF NOT EXISTS invoices (
            id INTEGER PRIMARY KEY,               -- ConnectWise invoice id
            invoice_number TEXT,
            invoice_date TEXT,                    -- YYYY-MM-DD as ConnectWise reports it
            month TEXT,                           -- YYYY-MM of invoice_date
            status_name TEXT,
            is_closed INTEGER NOT NULL DEFAULT 0, -- Closed / Closed - Emailed
            is_void INTEGER NOT NULL DEFAULT 0,
            company_id INTEGER,
            company_name TEXT,
            territory TEXT,
            rep_id INTEGER,
            apply_to_type TEXT,
            agreement_id INTEGER,
            agreement_start TEXT,
            subtotal REAL,
            total REAL,
            lines_total REAL,
            detail_state TEXT NOT NULL DEFAULT 'pending', -- ok | no_lines | mismatch | error
            detail_note TEXT,
            synced_at TEXT
        );
        CREATE INDEX IF NOT EXISTS idx_invoices_month ON invoices(month);
        CREATE INDEX IF NOT EXISTS idx_invoices_rep ON invoices(rep_id);
        CREATE TABLE IF NOT EXISTS invoice_lines (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            invoice_id INTEGER NOT NULL,
            kind TEXT NOT NULL,                   -- product | time | agreement | adjustment
            item TEXT,
            ticket_id INTEGER,
            ticket_summary TEXT,
            hours REAL,
            qty REAL,
            price REAL NOT NULL DEFAULT 0,        -- extended price billed
            cost REAL NOT NULL DEFAULT 0,         -- extended assumed cost
            unit_cost REAL,                       -- product lines: catalog unit cost
            cost_note TEXT,                       -- where the cost came from / problems
            gp REAL NOT NULL DEFAULT 0,
            over_year INTEGER NOT NULL DEFAULT 0, -- agreement older than 1 year at invoice date
            pct REAL NOT NULL DEFAULT 0,
            commission REAL NOT NULL DEFAULT 0,
            is_loss INTEGER NOT NULL DEFAULT 0,
            FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
        );
        CREATE INDEX IF NOT EXISTS idx_lines_invoice ON invoice_lines(invoice_id);
        CREATE TABLE IF NOT EXISTS line_commissions (
            line_id INTEGER NOT NULL,
            invoice_id INTEGER NOT NULL,
            rep_id INTEGER NOT NULL,              -- the payee (Chester, Arcus, Moe, Pivotal ...)
            pct REAL NOT NULL DEFAULT 0,
            commission REAL NOT NULL DEFAULT 0,   -- negative on a losing line (net loss handling)
            PRIMARY KEY (line_id, rep_id),
            FOREIGN KEY (line_id) REFERENCES invoice_lines(id) ON DELETE CASCADE
        );
        CREATE INDEX IF NOT EXISTS idx_lc_rep ON line_commissions(rep_id);
        CREATE INDEX IF NOT EXISTS idx_lc_invoice ON line_commissions(invoice_id);
        CREATE TABLE IF NOT EXISTS months (
            month TEXT PRIMARY KEY,
            locked_at TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS catalog_cache (
            catalog_id INTEGER PRIMARY KEY,
            identifier TEXT,
            description TEXT,
            cost REAL,
            price REAL,
            product_class TEXT,
            fetched_at TEXT
        );
        CREATE TABLE IF NOT EXISTS company_cache (
            company_id INTEGER PRIMARY KEY,
            name TEXT,
            territory TEXT,
            fetched_at TEXT
        );
        CREATE TABLE IF NOT EXISTS agreement_cache (
            agreement_id INTEGER PRIMARY KEY,
            name TEXT,
            start_date TEXT,
            additions_json TEXT,
            fetched_at TEXT
        );
        CREATE TABLE IF NOT EXISTS sync_queue (
            invoice_id INTEGER PRIMARY KEY,
            payload TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'pending', -- pending | done | error
            error_message TEXT
        );
        CREATE TABLE IF NOT EXISTS sync_state (
            key TEXT PRIMARY KEY,
            value TEXT
        );
        CREATE TABLE IF NOT EXISTS settings_audit (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            at TEXT NOT NULL,
            by_email TEXT,
            what TEXT NOT NULL,
            old_value TEXT,
            new_value TEXT
        );
    SQL);

    // Payout model (Michael, 2026-10-05). Each "rep" row is a PAYEE: it earns
    // base_pct of a line's gross profit whenever the invoiced company's
    // ConnectWise territory contains one of its territory_match words.
    //   "Arcus + Chester Sienko"        -> Arcus 15% + Chester 30% (15% on agreements 365+ days old)
    //   "Arcus, LLC Accounts"           -> Arcus 15%
    //   "Chester Sienko's Accounts"     -> Chester 30% (15% on agreements 365+ days old)
    //   "Moe Okeilli (new accounts)" / "Trey + Moe Okeilli" -> Moe 20%
    //   "Pivotal Tech Groups Accounts"  -> Pivotal 15%
    //   "Richmond Telecom's Accounts"   -> Richmond Telecom 15%
    // Any other territory is a house account and pays nothing. A losing line
    // NETS against the payee. (The sales manager's 1.25% is separate -- manager.php.)
    $defaults = ['labor_cost_per_hour' => '90'];
    $ins = $pdo->prepare('INSERT OR IGNORE INTO settings (key, value) VALUES (:k, :v)');
    foreach ($defaults as $k => $v) {
        $ins->execute([':k' => $k, ':v' => $v]);
    }

    $payees = [
        // name, sort, territory words, base %, % on agreements 365+ days old
        ['Chester', 1, 'Chester', 30.0, 15.0],
        ['Arcus', 2, 'Arcus', 15.0, null],
        ['Moe', 3, 'Moe', 20.0, null],
        ['Pivotal', 4, 'Pivotal', 15.0, null],
        ['Richmond Telecom', 5, 'Richmond Telecom', 15.0, null],
    ];
    $add = $pdo->prepare('INSERT OR IGNORE INTO reps (name, sort_order, territory_match, base_pct, agreement_after_year_pct) VALUES (:n, :s, :t, :b, :a)');
    $repCount = (int) $pdo->query('SELECT COUNT(*) FROM reps')->fetchColumn();
    if ($repCount === 0) {
        foreach ($payees as [$n, $s, $t, $b, $a]) {
            $add->execute([':n' => $n, ':s' => $s, ':t' => $t, ':b' => $b, ':a' => $a]);
        }
        commissions_set_setting($pdo, 'model_version', '3');
    } elseif (commissions_setting($pdo, 'model_version', '1') !== '3') {
        // Upgrade a database created by an earlier version of this tool.
        $pdo->beginTransaction();
        $upd = $pdo->prepare('UPDATE reps SET territory_match = :t, base_pct = :b, agreement_after_year_pct = :a, sort_order = :s, active = 1 WHERE name = :n');
        foreach ($payees as [$n, $s, $t, $b, $a]) {
            $add->execute([':n' => $n, ':s' => $s, ':t' => $t, ':b' => $b, ':a' => $a]);
            $upd->execute([':n' => $n, ':s' => $s, ':t' => $t, ':b' => $b, ':a' => $a]);
        }
        $pdo->exec("DELETE FROM settings WHERE key = 'loss_mode'");
        $pdo->commit();
        commissions_set_setting($pdo, 'model_version', '3');
        // Rules changed: rebuild every stored line (locked months too -- nothing was paid on the old rules).
        commissions_set_setting($pdo, 'model_recompute_pending', '1');
    }
}

function commissions_setting(PDO $pdo, string $key, string $default = ''): string
{
    $stmt = $pdo->prepare('SELECT value FROM settings WHERE key = :k');
    $stmt->execute([':k' => $key]);
    $v = $stmt->fetchColumn();
    return $v === false ? $default : (string) $v;
}

function commissions_set_setting(PDO $pdo, string $key, string $value): void
{
    $pdo->prepare('INSERT INTO settings (key, value) VALUES (:k, :v) ON CONFLICT(key) DO UPDATE SET value = excluded.value')
        ->execute([':k' => $key, ':v' => $value]);
}

function commissions_state_get(PDO $pdo, string $key): ?string
{
    $stmt = $pdo->prepare('SELECT value FROM sync_state WHERE key = :k');
    $stmt->execute([':k' => $key]);
    $v = $stmt->fetchColumn();
    return ($v === false || $v === null) ? null : (string) $v;
}

function commissions_state_set(PDO $pdo, string $key, ?string $value): void
{
    $pdo->prepare('INSERT INTO sync_state (key, value) VALUES (:k, :v) ON CONFLICT(key) DO UPDATE SET value = excluded.value')
        ->execute([':k' => $key, ':v' => $value]);
}
