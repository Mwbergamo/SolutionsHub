<?php
declare(strict_types=1);

/**
 * marketing/api/db.php
 *
 * SQLite store for the Marketing document hub (added 2026-11-12, per
 * Michael: "a filing cabinet" of marketing collateral organized by
 * service pillar -- upload/download/search PDFs, pptx, xlsx and other
 * files, folders and sub-folders reps create and remove routinely, and a
 * record of which file was emailed to which company, by whom, and when).
 *
 * Deliberately a NEW, self-contained app with its own database -- unlike
 * myday/api/digest.php (a pure read-only aggregator of numbers that
 * already live elsewhere), nothing about "what files exist in which
 * folder" or "what was sent to which company" exists anywhere else in
 * SolutionsHub. Identity still comes from the one shared Microsoft 365
 * session (auth/session.php) every app uses -- no local user table,
 * same approach myday/ already established, since nothing here needs a
 * numeric user id to foreign-key against.
 *
 * Tables:
 *   folders     one row per folder. A pillar's own top-level folder has
 *               parent_id = NULL; every other folder nests under
 *               another folder. Soft-deleted (deleted_at set) rather than
 *               dropped, so a removed folder -- and everything under it,
 *               see marketing_folder_descendant_ids() -- can be restored
 *               from Trash per Michael's "recoverable Trash" call.
 *   documents   one row per uploaded file, same soft-delete/Trash
 *               pattern. The real bytes live on disk under
 *               marketing/data/documents/<id>/ (data/.htaccess denies
 *               all direct HTTP access, same as every other app's
 *               data/ folder), named by this row's own id so a restored
 *               document doesn't collide with anything uploaded since.
 *   send_log    one row per "emailed this file to this company" send.
 *               Deliberately denormalized (customer_name/contact_name/
 *               contact_email/document_filename are snapshotted at send
 *               time, not re-joined live) so the history stays accurate
 *               and complete even if a customer is renamed, a contact
 *               changes email, or the document is later deleted --
 *               exactly what "I want to know which files have been sent
 *               to each company already" needs to keep meaning something
 *               a year from now.
 */

function marketing_db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $dataDir = __DIR__ . '/../data';
    if (!is_dir($dataDir)) {
        mkdir($dataDir, 0770, true);
    }
    $pdo = new PDO('sqlite:' . $dataDir . '/marketing.sqlite');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA busy_timeout = 8000');

    marketing_migrate($pdo);
    return $pdo;
}

/**
 * The 5 service pillars Michael named, plus a 6th catch-all per his
 * "Yes, add a General / Company-Wide folder" answer (2026-11-12) for
 * things that aren't pillar-specific -- logos, letterhead, email
 * templates, event/trade-show materials, company one-pagers. Ids match
 * Solutions Creator's own PILLARS array (app.js) where one already
 * exists (it/dc/security/cabling/voip) so the same service line is
 * identified the same way everywhere in SolutionsHub; display names here
 * follow Michael's own wording from this request rather than Solutions
 * Creator's shorter on-screen labels ("Premise Security", "Data
 * Cabling").
 */
function marketing_pillars(): array
{
    return [
        ['id' => 'it', 'name' => 'IT Services'],
        ['id' => 'dc', 'name' => 'Data Center Services'],
        ['id' => 'security', 'name' => 'Premise Security Services'],
        ['id' => 'cabling', 'name' => 'Data Cabling Services'],
        ['id' => 'voip', 'name' => 'Voice over IP Services'],
        ['id' => 'general', 'name' => 'General / Company-Wide'],
    ];
}

function marketing_migrate(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS folders (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            pillar TEXT NOT NULL,
            parent_id INTEGER REFERENCES folders(id) ON DELETE CASCADE,
            name TEXT NOT NULL,
            created_at TEXT NOT NULL,
            created_by_name TEXT NOT NULL DEFAULT '',
            created_by_email TEXT NOT NULL DEFAULT '',
            deleted_at TEXT,
            deleted_by_name TEXT,
            deleted_by_email TEXT
        );
        CREATE INDEX IF NOT EXISTS idx_mkt_folders_parent ON folders(parent_id);
        CREATE INDEX IF NOT EXISTS idx_mkt_folders_pillar ON folders(pillar, parent_id);

        CREATE TABLE IF NOT EXISTS documents (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            folder_id INTEGER NOT NULL REFERENCES folders(id) ON DELETE CASCADE,
            original_filename TEXT NOT NULL,
            ext TEXT NOT NULL,
            size_bytes INTEGER NOT NULL,
            uploaded_by_name TEXT NOT NULL DEFAULT '',
            uploaded_by_email TEXT NOT NULL DEFAULT '',
            uploaded_at TEXT NOT NULL,
            deleted_at TEXT,
            deleted_by_name TEXT,
            deleted_by_email TEXT
        );
        CREATE INDEX IF NOT EXISTS idx_mkt_documents_folder ON documents(folder_id);
        CREATE INDEX IF NOT EXISTS idx_mkt_documents_name ON documents(original_filename);

        -- One row per "emailed this file to this company" send. See this
        -- file's header for why customer/contact/filename are snapshotted
        -- rather than joined live.
        CREATE TABLE IF NOT EXISTS send_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            document_id INTEGER REFERENCES documents(id) ON DELETE SET NULL,
            document_filename TEXT NOT NULL,
            customer_id INTEGER,
            customer_name TEXT NOT NULL DEFAULT '',
            contact_id INTEGER,
            contact_name TEXT NOT NULL DEFAULT '',
            contact_email TEXT NOT NULL DEFAULT '',
            sent_by_name TEXT NOT NULL DEFAULT '',
            sent_by_email TEXT NOT NULL DEFAULT '',
            subject TEXT NOT NULL DEFAULT '',
            note TEXT NOT NULL DEFAULT '',
            sent_at TEXT NOT NULL
        );
        CREATE INDEX IF NOT EXISTS idx_mkt_send_log_customer ON send_log(customer_id, sent_at);
        CREATE INDEX IF NOT EXISTS idx_mkt_send_log_document ON send_log(document_id);
    SQL);

    // Seed each pillar's top-level folder exactly once (id used as the
    // stable identity: INSERT OR IGNORE keyed off a (pillar, parent_id
    // IS NULL) uniqueness we enforce here in code, since SQLite can't
    // express "unique among NULLs" directly).
    $existing = $pdo->query("SELECT pillar FROM folders WHERE parent_id IS NULL")->fetchAll(PDO::FETCH_COLUMN);
    $ins = $pdo->prepare('INSERT INTO folders (pillar, parent_id, name, created_at, created_by_name, created_by_email) VALUES (:p, NULL, :n, :t, :cn, :ce)');
    foreach (marketing_pillars() as $p) {
        if (in_array($p['id'], $existing, true)) {
            continue;
        }
        $ins->execute([':p' => $p['id'], ':n' => $p['name'], ':t' => gmdate('Y-m-d\TH:i:s\Z'), ':cn' => 'System', ':ce' => '']);
    }
}

/** Every descendant folder id of $folderId (not including itself), for cascading a Trash/restore/purge through a subtree. */
function marketing_folder_descendant_ids(PDO $pdo, int $folderId): array
{
    $ids = [];
    $frontier = [$folderId];
    while ($frontier !== []) {
        $placeholders = implode(',', array_fill(0, count($frontier), '?'));
        $stmt = $pdo->prepare("SELECT id FROM folders WHERE parent_id IN ($placeholders)");
        $stmt->execute($frontier);
        $children = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $ids = array_merge($ids, $children);
        $frontier = $children;
    }
    return $ids;
}
