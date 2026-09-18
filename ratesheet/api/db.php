<?php
/**
 * ratesheet/api/db.php
 *
 * Opens (and, on first run, creates) the Customer Rate Sheet Sign Up app's
 * SQLite database. Self-contained sub-app, same pattern as
 * relationships/api/db.php and register/api/db.php -- its own accounts
 * table (rep logins, via the shared Microsoft 365 SSO -- see
 * auth/session.php) and its own table for every rate-sheet request a rep
 * sends out plus what the customer eventually submits.
 *
 * Added 2026-09-17 per Michael's "Customer Rate Sheet Sign Up" request:
 * a rep picks a prospect email + Sending Representative + Location
 * (Warsaw/Richmond) + Kind of Account (Commercial/Residential) and sends
 * a tokenized public link; the customer fills out name/email/address/
 * payment METHOD/e-signature on that public link (no login -- see
 * public.php); a successful submit creates the Company + Contact in
 * ConnectWise (register/api/customers.php's proven
 * register_cw_create_company()/register_cw_create_contact() pattern,
 * mirrored here as ratesheet_cw_create_company()/
 * ratesheet_cw_create_contact() in requests.php -- see that file's header
 * for why this is a copy rather than a cross-app include).
 *
 * The database file lives in ratesheet/data/, which is:
 *   - listed in .gitignore (runtime data, not source -- a `git pull`
 *     deploy must never overwrite or wipe it)
 *   - blocked from direct HTTP access by ratesheet/data/.htaccess (same
 *     "Deny from all" treatment as register/data/ and relationships/data/)
 *
 * Every other ratesheet/api/*.php file starts with:
 *   require __DIR__ . '/db.php';
 *   $pdo = ratesheet_db();
 */

declare(strict_types=1);

function ratesheet_db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $dataDir = __DIR__ . '/../data';
    if (!is_dir($dataDir)) {
        mkdir($dataDir, 0770, true);
    }
    $dbPath = $dataDir . '/ratesheet.sqlite';

    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode = WAL');

    ratesheet_migrate($pdo);

    return $pdo;
}

function ratesheet_migrate(PDO $pdo): void
{
    // password_hash is a required-but-unused column, same reasoning as
    // register_users/crc_users: auth/local-user.php's
    // auth_upsert_local_user() writes a random placeholder into it on
    // first sign-in (real auth is the shared Microsoft 365 session, not a
    // local password) -- kept NOT NULL to match that shared helper's fixed
    // INSERT column list rather than special-casing this table.
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS ratesheet_users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        )
    SQL);

    // One row per rate sheet a rep sends. Filled in gradually: created at
    // send-time with just the rep/prospect/location/account fields and a
    // random token; the customer-facing identity/address/terms/signature
    // fields (plus cw_company_id/cw_contact_id/altpay_customer_id/
    // altpay_invoice_id) are added by public.php's ?action=step1-submit,
    // and payment_method/altpay_payment_method_id only once
    // ?action=step2-submit actually vaults a payment method (see that
    // file's header -- follow-up #4). Nothing here is ever deleted -- a
    // failed Step 1 ConnectWise create leaves status='failed' with the
    // customer's typed data still saved, so staff can finish the signup
    // by hand rather
    // than losing what the customer already filled in.
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS rate_sheet_requests (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            token TEXT NOT NULL UNIQUE,

            -- Who's actually logged in and clicked Send (may differ from
            -- rep_name/rep_email below -- e.g. an admin sending on behalf
            -- of a teammate). Nullable so a row is never lost if the
            -- ratesheet_users row is later removed.
            created_by_user_id INTEGER REFERENCES ratesheet_users(id),

            prospect_email TEXT NOT NULL,
            rep_name TEXT NOT NULL,
            rep_email TEXT NOT NULL,
            location TEXT NOT NULL,        -- 'Warsaw' | 'Richmond'
            account_kind TEXT NOT NULL,    -- 'Commercial' | 'Residential'
            hourly_rate REAL NOT NULL,     -- resolved at send-time from location

            -- 'pending' (link sent, not yet started) | 'awaiting_payment'
            -- (Step 1 done -- CW company+contact created, Credit Hold ON,
            -- waiting on Step 2 payment; added follow-up #4) | 'submitted'
            -- (Step 2 done -- payment vaulted, Credit Hold released) |
            -- 'failed' (Step 1's CW create itself failed -- see
            -- fail_reason; their typed data is still saved below).
            status TEXT NOT NULL DEFAULT 'pending',
            fail_reason TEXT,

            -- Customer-submitted fields (NULL until submit is attempted).
            first_name TEXT,
            last_name TEXT,
            business_name TEXT,
            customer_email TEXT,
            address_line1 TEXT,
            address_line2 TEXT,
            city TEXT,
            state TEXT,
            zip TEXT,
            payment_method TEXT,          -- 'card' | 'ach' (method only -- see public.php's header)
            want_copy_of_signup INTEGER,  -- 0/1
            invoices_emailed INTEGER,     -- 0/1 -- the dashboard's "Invoices Emailed" column
            agreed_to_terms INTEGER NOT NULL DEFAULT 0,
            signature_data TEXT,          -- data: URL (PNG) from the signature pad
            signed_at TEXT,

            cw_company_id INTEGER,
            cw_contact_id INTEGER,

            internal_email_status TEXT,   -- hello@codebluetechnology.com notice: 'sent' | 'failed' | NULL
            customer_copy_email_status TEXT, -- only attempted when want_copy_of_signup=1

            sent_at TEXT NOT NULL DEFAULT (datetime('now')),
            submitted_at TEXT
        )
    SQL);
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_rate_sheet_requests_rep_email ON rate_sheet_requests(rep_email COLLATE NOCASE)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_rate_sheet_requests_status ON rate_sheet_requests(status)');

    // Added 2026-09-17 (follow-up, per Michael): the printable "accepted
    // terms" record needs the submitting IP address alongside the
    // signature/timestamp/checkbox it already had. ALTER TABLE via
    // ratesheet_add_column_if_missing() rather than editing the CREATE
    // TABLE above, since that only runs on a brand-new database -- this
    // app is already live with real rows.
    ratesheet_add_column_if_missing($pdo, 'rate_sheet_requests', 'ip_address', 'TEXT');

    // Added 2026-09-17 (follow-up #2, per Michael): real payment
    // collection via Alternative Payments (altpay.php), replacing the
    // "payment_method choice only" placeholder above. The actual card/
    // bank numbers are never stored here -- only Alternative Payments'
    // own customer id + payment method id, plus a redacted display
    // string ("Visa ending 4242") for the dashboard/receipt. See
    // altpay.php's header for the vaulting flow and public.php's
    // ?action=submit for how a vaulting failure is handled (fail-open,
    // same as the existing ConnectWise-failure pattern -- the signup
    // still completes and is flagged for staff to finish manually).
    ratesheet_add_column_if_missing($pdo, 'rate_sheet_requests', 'altpay_customer_id', 'TEXT');
    ratesheet_add_column_if_missing($pdo, 'rate_sheet_requests', 'altpay_payment_method_id', 'TEXT');
    ratesheet_add_column_if_missing($pdo, 'rate_sheet_requests', 'altpay_payment_method_summary', 'TEXT');
    ratesheet_add_column_if_missing($pdo, 'rate_sheet_requests', 'altpay_status', 'TEXT'); // 'vaulted' | 'failed' | NULL
    ratesheet_add_column_if_missing($pdo, 'rate_sheet_requests', 'altpay_fail_reason', 'TEXT');

    // Added 2026-09-17 (follow-up #4, per Michael): two-step signup --
    // Step 1 (info/terms/signature) now creates the ConnectWise Company
    // (with Credit Hold on) + Contact and a PERMANENT Alternative Payments
    // setup invoice up front; Step 2 (payment only) checks out against
    // that same invoice and releases Credit Hold once a payment method is
    // actually vaulted. See public.php's ?action=step1-submit/
    // ?action=step2-submit and connectwise.php's
    // ratesheet_cw_release_credit_hold(). Unlike the old throwaway
    // invoice (created and archived per card attempt, id only ever held
    // in browser JS state), this invoice is never archived, so its id
    // needs to persist on the row itself.
    ratesheet_add_column_if_missing($pdo, 'rate_sheet_requests', 'altpay_invoice_id', 'TEXT');
}

/** Same ALTER-TABLE-if-needed helper as register/relationships use for live schema changes. */
function ratesheet_add_column_if_missing(PDO $pdo, string $table, string $column, string $definition): void
{
    $stmt = $pdo->query("PRAGMA table_info($table)");
    $existing = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array($column, $existing, true)) {
        $pdo->exec("ALTER TABLE $table ADD COLUMN $column $definition");
    }
}
