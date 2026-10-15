#!/usr/bin/env python3
path = "db.php"
with open(path, "r", encoding="utf-8") as f:
    src = f.read()
orig = src

old = """    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_general_todos_customer ON general_todos(customer_id, id DESC)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_general_todos_assignee ON general_todos(assigned_to_name, completed_at)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_general_todos_open ON general_todos(customer_id, removed_at, completed_at, due_date)');
"""
assert src.count(old) == 1, "anchor count=%d" % src.count(old)
new = old + """
    // Cross-app "Add to Favorites" (added 2026-10-15 per Michael) -- a rep on ANY SolutionsHub
    // app (root Solutions Creator, this Relationships dashboard, Register, Rate Sheet, ...) can
    // star the page they're on; it shows as a quick link in a new left-hand sidebar on the root
    // Overview screen. Lives here (not a new per-app table) because every app already shares this
    // one login session (crc_users -- see _util.php's relationships_current_user()) and the root
    // app already calls into relationships/api/* for Saved Solutions (solution-save.js), so this
    // is the established shared backend, not a new piece of infrastructure. url is whatever that
    // app's own deep-link looks like (e.g. "relationships/?cw_company=123",
    // "relationships/?view=report&section=sales") -- just a bookmark, no snapshot of in-progress
    // state (AskUserQuestion 2026-10-15). UNIQUE(user_id, url) so starring the same page twice is
    // a no-op, not a duplicate row.
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS favorites (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL REFERENCES crc_users(id),
            app TEXT NOT NULL,
            label TEXT NOT NULL,
            url TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT (datetime('now')),
            UNIQUE(user_id, url)
        )
    SQL);
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_favorites_user ON favorites(user_id, created_at)');
"""
src = src.replace(old, new, 1)
assert src != orig
with open(path, "w", encoding="utf-8") as f:
    f.write(src)
print("db.php updated")
