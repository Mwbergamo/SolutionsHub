<?php
/**
 * call-experience-designer/api/db.php
 *
 * Opens (and, on first run, creates) the Call In Experience Designer
 * app's SQLite database. Self-contained sub-app, same pattern as
 * ratesheet/api/db.php: its own accounts table (rep logins, via the
 * shared Microsoft 365 SSO -- see auth/session.php) and its own tables
 * for every "Call In Experience" request a rep sends out plus the four
 * recorded/uploaded tracks a customer submits against it.
 *
 * Added 2026-10-23 per Michael's request: a rep sends a customer a
 * tokenized link to a public recording page (record.html); the customer
 * records or uploads up to four fixed phone-system greeting tracks (Main
 * System Greeting, Phone Tree Menu, General Holiday Message, Emergency
 * Message), each with an optional date-start/date-stop window, types
 * their Company Name, and submits. service@codebluetechnology.com gets
 * an email with a link to the authenticated rep-side record (receipt.html)
 * to play/download each track -- see api/public.php's header for why that
 * link is login-gated rather than a second public token.
 *
 * Unlike ratesheet/, this app never talks to ConnectWise or any other
 * third-party API -- it's purely a recording/upload + email workflow, so
 * there's no connectwise-config.php or similar to .gitignore.
 *
 * The database file lives in call-experience-designer/data/, which is:
 *   - listed in this folder's own .gitignore (runtime data, not source --
 *     a `git pull` deploy must never overwrite or wipe it)
 *   - blocked from direct HTTP access by data/.htaccess (same "Deny from
 *     all" treatment as ratesheet/data/, register/data/, relationships/data/)
 *
 * Every other call-experience-designer/api/*.php file starts with:
 *   require __DIR__ . '/db.php';
 *   $pdo = call_experience_db();
 */

declare(strict_types=1);

function call_experience_db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $dataDir = __DIR__ . '/../data';
    if (!is_dir($dataDir)) {
        mkdir($dataDir, 0770, true);
    }
    $dbPath = $dataDir . '/call-experience.sqlite';

    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode = WAL');

    call_experience_migrate($pdo);

    return $pdo;
}

function call_experience_migrate(PDO $pdo): void
{
    // password_hash is a required-but-unused column, same reasoning as
    // ratesheet_users/register_users/crc_users: auth/local-user.php's
    // auth_upsert_local_user() writes a random placeholder into it on
    // first sign-in (real auth is the shared Microsoft 365 session, not a
    // local password).
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS call_experience_users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        )
    SQL);

    // One row per "Call In Experience" link a rep sends. Created at
    // send-time with just the rep + customer email + a random token;
    // company_name/submitted_at are filled in by public.php's
    // ?action=submit. Nothing here is ever deleted.
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS call_experience_requests (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            token TEXT NOT NULL UNIQUE,

            created_by_user_id INTEGER REFERENCES call_experience_users(id),
            rep_name TEXT NOT NULL,
            rep_email TEXT NOT NULL,

            customer_email TEXT NOT NULL,
            company_name TEXT,

            -- 'pending' (link sent, customer hasn't submitted yet) |
            -- 'submitted' (customer clicked Submit -- tracks/dates are
            -- locked from further editing on the public page from this
            -- point on, same "already submitted" convention ratesheet
            -- uses).
            status TEXT NOT NULL DEFAULT 'pending',

            notify_email_status TEXT,  -- service@ notice: 'sent' | 'failed' | NULL (not yet attempted)
            ip_address TEXT,           -- submitting IP, same audit-trail convention as ratesheet

            sent_at TEXT NOT NULL DEFAULT (datetime('now')),
            submitted_at TEXT
        )
    SQL);
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_call_experience_requests_status ON call_experience_requests(status)');

    // One row per track per request -- at most 4 (track_key is one of the
    // 4 fixed keys below), created/updated as the customer records,
    // uploads, or clears each one, and as date_start/date_stop are set.
    // A row can exist with dates set but no file yet (dates are saved
    // independently of a recording/upload, per the public page's
    // behavior), or a file with no dates (both are optional, per
    // Michael's "both fields are not required but available").
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS call_experience_tracks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            request_id INTEGER NOT NULL REFERENCES call_experience_requests(id) ON DELETE CASCADE,
            track_key TEXT NOT NULL,   -- 'main_greeting' | 'phone_tree' | 'general_holiday' | 'emergency'

            date_start TEXT,          -- 'YYYY-MM-DD' or NULL
            date_stop TEXT,           -- 'YYYY-MM-DD' or NULL

            stored_filename TEXT,     -- e.g. 'main_greeting.wav', relative to call_experience_track_dir($requestId) -- NULL when no file yet (cleared, or never recorded)
            original_filename TEXT,   -- customer's own filename, for an uploaded (not recorded) track
            source TEXT,              -- 'recorded' | 'uploaded' | NULL (no file)
            size_bytes INTEGER,
            uploaded_at TEXT,

            updated_at TEXT NOT NULL DEFAULT (datetime('now')),

            UNIQUE (request_id, track_key)
        )
    SQL);
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_call_experience_tracks_request ON call_experience_tracks(request_id)');
}

/** Same ALTER-TABLE-if-needed helper as register/relationships/ratesheet use for live schema changes. */
function call_experience_add_column_if_missing(PDO $pdo, string $table, string $column, string $definition): void
{
    $stmt = $pdo->query("PRAGMA table_info($table)");
    $existing = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array($column, $existing, true)) {
        $pdo->exec("ALTER TABLE $table ADD COLUMN $column $definition");
    }
}

/**
 * The 4 fixed tracks a customer records/uploads, in display order.
 * Single source of truth for key => label -- read by public.php,
 * requests.php, and both front-end JS files keep their own copy in sync
 * (see record.js's TRACKS array).
 *
 * @return array<string,string> track_key => label
 */
function call_experience_track_defs(): array
{
    return [
        'main_greeting' => 'Main System Greeting',
        'phone_tree' => 'Phone Tree Menu',
        'general_holiday' => 'General Holiday Message',
        'emergency' => 'Emergency Message',
    ];
}

/** Where a request's audio files live on disk -- data/.htaccess already blocks direct HTTP access to everything under data/. */
function call_experience_track_dir(int $requestId): string
{
    return __DIR__ . '/../data/tracks/' . $requestId;
}
