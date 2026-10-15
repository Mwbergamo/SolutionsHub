#!/usr/bin/env python3
path = "api/db.php"
with open(path, "r", encoding="utf-8") as f:
    src = f.read()
orig = src

old = "    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_cw_opportunity_sync_queue_status ON cw_opportunity_sync_queue(status)');\n}"
assert src.count(old) == 1, "anchor count=%d" % src.count(old)

new = old[:-1] + """
    // Asset Assignments: per-contact "inactive" flag, mirroring the Account Contacts inactive
    // control (app.js's contacts-toggle-inactive) but scoped to this screen's own IT Glue
    // contact ids -- a different identity space than the ConnectWise-synced `contacts` table
    // (IT Glue and ConnectWise contacts are separately synced and not reliably matchable by id
    // or even email), so this is its own flag rather than trying to join the two. Added
    // 2026-10-15 per Michael.
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS asset_contact_status (
            customer_id INTEGER NOT NULL,
            contact_id INTEGER NOT NULL,
            contact_name TEXT NOT NULL DEFAULT '',
            inactive INTEGER NOT NULL DEFAULT 0,
            set_by_name TEXT NOT NULL DEFAULT '',
            set_at TEXT NOT NULL DEFAULT (datetime('now')),
            PRIMARY KEY (customer_id, contact_id)
        )
    SQL);

    // Asset Assignments grid: a manual value for one (contact, column) cell -- e.g. the
    // Microsoft License a rep picked for someone during a meeting, or a Firewall/VPN/DUO
    // assignment IT Glue doesn't track at all. IT Glue's own API is read-only here (see
    // itglue.php's file header), so this is the record of what was DECIDED, not a live push
    // back to IT Glue/Microsoft -- the matching to-do (see relationships_itglue_create_todo() /
    // the override_set action, both in itglue.php) is what drives the actual follow-through.
    // Latest value per cell only; the to-do created alongside each change is the history.
    // Added 2026-10-15 per Michael.
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS asset_overrides (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            customer_id INTEGER NOT NULL,
            contact_id INTEGER NOT NULL,
            column_key TEXT NOT NULL,
            value TEXT NOT NULL DEFAULT '',
            set_by_name TEXT NOT NULL DEFAULT '',
            set_at TEXT NOT NULL DEFAULT (datetime('now')),
            UNIQUE (customer_id, contact_id, column_key)
        )
    SQL);
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_asset_overrides_customer ON asset_overrides(customer_id)');

    // Asset Assignments grid: the manual (non-IT-Glue-backed) columns every rep sees, the same
    // across every customer -- "configurable like a spreadsheet" (Michael, 2026-10-15) means the
    // team can add/remove a column here, not that each customer gets its own layout. Computer /
    // Microsoft License / Software are built into assets.js itself (they're backed by real IT
    // Glue joins); this table is only the extra manual columns layered on top of those. options
    // is a comma-separated preset list shown in the cell's dropdown (NULL = free text).
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS asset_grid_columns (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            column_key TEXT NOT NULL UNIQUE,
            label TEXT NOT NULL,
            options TEXT,
            position INTEGER NOT NULL DEFAULT 0,
            created_by_name TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        )
    SQL);
    $seedAssetColumn = static function (string $key, string $label, string $options, int $pos) use ($pdo): void {
        $stmt = $pdo->prepare('INSERT OR IGNORE INTO asset_grid_columns (column_key, label, options, position, created_by_name) VALUES (:k, :l, :o, :p, :by)');
        $stmt->execute([':k' => $key, ':l' => $label, ':o' => $options, ':p' => $pos, ':by' => 'system']);
    };
    $seedAssetColumn('firewall', 'Firewall Assignment', 'Managed, Customer-owned, None', 10);
    $seedAssetColumn('vpn', 'VPN Assignment', 'Enabled, Not enabled', 11);
    $seedAssetColumn('duo', 'DUO', 'Enrolled, Not enrolled', 12);
    $seedAssetColumn('guardz', 'Guardz', 'Protected, Not protected', 13);
}"""
assert src.count(old) == 1
src = src.replace(old, new, 1)
assert src != orig
with open(path, "w", encoding="utf-8") as f:
    f.write(src)
print("db.php updated: asset_contact_status / asset_overrides / asset_grid_columns tables added")
