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
    // random token; the customer-facing fields (name, address, payment
    // method, signature, ...) are added by public.php's ?action=submit,
    // and cw_company_id/cw_contact_id only once ConnectWise actually
    // confirms the create. Nothing here is ever deleted -- a failed
    // ConnectWise create leaves status='failed' with the customer's typed
    // data still saved, so staff can finish the signup by hand rather
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

            -- 'pending' (link sent, not yet submitted -- dashboard: red
            -- "Sent") | 'submitted' (customer completed the form -- CW
            -- company+contact created, Billing Status "Credit Hold" --
            -- dashboard: yellow "Signed", or green "Payment Added" once a
            -- live ConnectWise lookup shows Billing Status changed away
            -- from Credit Hold, see requests.php) | 'failed' (ConnectWise
            -- create failed -- see fail_reason; their typed data is still
            -- saved below, and they can retry). 'awaiting_payment' is a
            -- legacy value from a same-day two-step redesign that was
            -- superseded before going live (see credit_hold_status below)
            -- -- no current code sets it, but old rows (if any) fall back
            -- to showing the signup form again, same as 'pending'/'failed'.
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

    // Added 2026-09-17 (follow-up #2, per Michael), and SUPERSEDED
    // 2026-09-18 (see below) -- these 5 columns were for real-time,
    // self-service payment collection via Alternative Payments
    // (altpay.php). That design was replaced the same day by Michael's
    // "Invoicing adds the payment method manually in Alternative
    // Payments" workflow (see public.php's header) -- nothing writes to
    // these columns anymore. Left in place (not dropped) as dormant/
    // legacy rather than risk a DROP COLUMN against a live SQLite file
    // for columns that cost nothing sitting empty; a future revival of
    // self-service vaulting could reuse them as-is.
    ratesheet_add_column_if_missing($pdo, 'rate_sheet_requests', 'altpay_customer_id', 'TEXT');
    ratesheet_add_column_if_missing($pdo, 'rate_sheet_requests', 'altpay_payment_method_id', 'TEXT');
    ratesheet_add_column_if_missing($pdo, 'rate_sheet_requests', 'altpay_payment_method_summary', 'TEXT');
    ratesheet_add_column_if_missing($pdo, 'rate_sheet_requests', 'altpay_status', 'TEXT'); // dormant, see above
    ratesheet_add_column_if_missing($pdo, 'rate_sheet_requests', 'altpay_fail_reason', 'TEXT');
    ratesheet_add_column_if_missing($pdo, 'rate_sheet_requests', 'altpay_invoice_id', 'TEXT'); // dormant, see above (added briefly same-day for the two-step redesign)
    ratesheet_add_column_if_missing($pdo, 'rate_sheet_requests', 'credit_hold_fail_reason', 'TEXT'); // dormant -- was for an API-driven Credit Hold release, no longer used (Invoicing releases it manually in ConnectWise)

    // Added 2026-09-18 (this is the design that actually shipped -- see
    // public.php's header for the full story of same-day redesigns).
    // Per Michael: the customer picks Card/ACH as a plain PREFERENCE only
    // (no numbers collected here at all); on submit, the ConnectWise
    // Company + Contact are created, with the Company's Billing Status set
    // to "Credit Hold"; Invoicing gets emailed and adds the real payment
    // method directly in Alternative Payments themselves, then changes
    // Billing Status manually in ConnectWise once done. The dashboard's
    // green "Payment Added" state is computed live from ConnectWise's own
    // Billing Status (see requests.php) -- Michael's explicit choice over
    // tracking a "payment added" flag in this database, so ConnectWise
    // stays the single source of truth and nothing here can drift out of
    // sync with it.
    //
    // credit_hold_status here just records what THIS APP attempted at
    // signup time (informational only, e.g. for the printable record):
    // 'held' (the "Credit Hold" ConnectWise Company Status was resolved,
    // set on create, AND confirmed by a post-create read-back -- see
    // public.php) | 'lookup_failed' (it could not be resolved OR the
    // post-create read-back didn't confirm it -- either way the Company
    // was created as Active instead, loudly flagged in both notification
    // emails, see public.php) | NULL (ConnectWise create itself failed,
    // so no Company/Status was ever set).
    ratesheet_add_column_if_missing($pdo, 'rate_sheet_requests', 'credit_hold_status', 'TEXT');

    // Added 2026-09-18 (follow-up, per Michael): the invoicing@ "ready for
    // payment" notice's own send status -- BUG FIX 2026-09-19: this was
    // originally added as a literal column in the CREATE TABLE statement
    // above, which only takes effect for a brand-new database. On this
    // app's already-live production database, `CREATE TABLE IF NOT
    // EXISTS` is a no-op, so the live table never actually got this
    // column -- every submit crashed with "no such column:
    // invoicing_email_status" on the final status-bookkeeping UPDATE
    // (after the ConnectWise Company/Contact had ALREADY been created and
    // both notification emails had ALREADY been attempted, so a customer
    // hitting this saw a raw server error even though their signup had
    // actually gone through). Moved here, the same ALTER-TABLE-if-missing
    // path every other post-launch column in this table already uses, so
    // it actually reaches the live schema. 'sent' | 'failed' | NULL.
    ratesheet_add_column_if_missing($pdo, 'rate_sheet_requests', 'invoicing_email_status', 'TEXT');

    // Added 2026-09-19, per Michael: Invoicing needs the customer's phone
    // number (alongside name/email, already collected) to actually reach
    // them. Required on the signup form -- see public.php's validation --
    // so this is only NULL for older rows submitted before this was added.
    ratesheet_add_column_if_missing($pdo, 'rate_sheet_requests', 'phone', 'TEXT');
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
