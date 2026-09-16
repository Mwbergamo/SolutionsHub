<?php
/**
 * relationships/api/db.php
 *
 * Opens (and, on first run, creates + seeds) the Relationships dashboard's
 * SQLite database. This is a separate, self-contained mini-app from the
 * main SolutionsHub quoting tool — it needs real accounts and durable,
 * shared-across-users state (checklist checkboxes + timestamps), which the
 * static SolutionsHub SPA has no concept of.
 *
 * The database file lives in relationships/data/, which is:
 *   - listed in .gitignore (it's runtime data, not source — a `git pull`
 *     deploy must never overwrite or wipe it)
 *   - blocked from direct HTTP access by relationships/data/.htaccess
 *     (same "Deny from all" treatment as the repo's .git folder)
 *
 * Every other relationships/api/*.php file starts with:
 *   require __DIR__ . '/db.php';
 *   $pdo = relationships_db();
 */

declare(strict_types=1);

require_once __DIR__ . '/catalog.php';

function relationships_db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $dataDir = __DIR__ . '/../data';
    if (!is_dir($dataDir)) {
        mkdir($dataDir, 0770, true);
    }
    $dbPath = $dataDir . '/relationships.sqlite';
    $isNew = !is_file($dbPath);

    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode = WAL');

    relationships_migrate($pdo);

    if ($isNew) {
        relationships_seed_mock_data($pdo);
    }

    return $pdo;
}

function relationships_migrate(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS crc_users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        )
    SQL);

    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS customers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            connectwise_id TEXT,
            name TEXT NOT NULL,
            is_mock INTEGER NOT NULL DEFAULT 1,
            is_peoplefirst INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        )
    SQL);
    relationships_add_column_if_missing($pdo, 'customers', 'is_peoplefirst', 'INTEGER NOT NULL DEFAULT 0');
    // PeopleFirst quarterly risk-assessment / monthly client-checkin
    // tracking -- only meaningful when is_peoplefirst = 1, but kept on the
    // customers row itself (rather than a separate table) since each
    // customer only ever has ONE "most recent" checkin and ONE "most
    // recent" risk scan; history of past ones isn't tracked.
    relationships_add_column_if_missing($pdo, 'customers', 'last_client_checkin_at', 'TEXT');
    relationships_add_column_if_missing($pdo, 'customers', 'last_client_checkin_by', 'TEXT');
    relationships_add_column_if_missing($pdo, 'customers', 'last_risk_scan_at', 'TEXT');
    relationships_add_column_if_missing($pdo, 'customers', 'last_risk_scan_by', 'TEXT');
    // Set when a customer's active Voice Agreement (ConnectWise agreement
    // type 66) is "empty" -- zero active additions. That's how CodeBlue
    // represents a manufacturer-hosted phone platform (e.g. Zultys Hosted)
    // that CBT doesn't sell services against: the agreement exists so the
    // relationship is on record, but there's nothing to sync. Used to
    // suppress VoIP/phone cross-sell for these customers (see
    // connectwise-sync-core.php, customers.php, checklist.php).
    relationships_add_column_if_missing($pdo, 'customers', 'voip_hosted_elsewhere', 'INTEGER NOT NULL DEFAULT 0');
    relationships_add_column_if_missing($pdo, 'customers', 'voip_hosted_agreement_name', 'TEXT');
    // Set when this customer row came from the Prospect sync (a real
    // ConnectWise Company with Active/Delinquent/Special Info status and
    // no Vendor type) rather than from an active agreement -- i.e. it has
    // zero recorded services and is a full cross-sell opportunity across
    // every pillar, not just some. Added 2026-09-10 per Michael. Always 0
    // for a customer with any real synced services -- see
    // connectwise-sync-core.php (clears it whenever it upserts a customer
    // from an actual agreement) and connectwise-prospect-sync-core.php
    // (only ever sets it on a customer with zero customer_services rows).
    relationships_add_column_if_missing($pdo, 'customers', 'is_prospect_only', 'INTEGER NOT NULL DEFAULT 0');
    // 'monthly' (default) or 'annual' -- set by the Monthly Billing sync
    // (connectwise-billing-sync-core.php) when a customer's normal
    // trailing-6-month Agreement-invoice window comes back entirely $0 but
    // a wider 3-year check finds real Agreement billing anyway (e.g. one
    // large invoice a year, in one month, like Evolution Divorce & Family
    // Law -- see claude/relationships-annual-billing-cadence.md). Read by
    // relationships_cw_billing_stored_series() to decide whether to read
    // customer_monthly_billing (6 months) or customer_yearly_billing (3
    // years) for this customer's Monthly Billing panel. Recomputed from
    // scratch on every billing sync run, same as is_peoplefirst/
    // is_prospect_only above, so a customer whose billing pattern changes
    // isn't stuck on a stale cadence forever.
    relationships_add_column_if_missing($pdo, 'customers', 'billing_cadence', "TEXT NOT NULL DEFAULT 'monthly'");

    // One row per active ConnectWise agreement addition (mocked for now —
    // `source` distinguishes seeded sample rows from anything a future real
    // ConnectWise sync writes). pillar_id/service_id match SolutionsHub's
    // own PILLARS catalog ids exactly, so the dashboard and the Solutions
    // Hub deep-links always agree on what a "service" is.
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS customer_services (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            customer_id INTEGER NOT NULL REFERENCES customers(id) ON DELETE CASCADE,
            pillar_id TEXT NOT NULL,
            pillar_name TEXT NOT NULL,
            service_id TEXT NOT NULL,
            service_name TEXT NOT NULL,
            product_label TEXT NOT NULL,
            qty REAL NOT NULL DEFAULT 1,
            unit TEXT,
            source TEXT NOT NULL DEFAULT 'mock',
            cw_agreement_id INTEGER
        )
    SQL);
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_customer_services_customer ON customer_services(customer_id)');
    relationships_add_column_if_missing($pdo, 'customer_services', 'cw_agreement_id', 'INTEGER');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_customer_services_cw_agreement ON customer_services(cw_agreement_id)');

    // Queue of ConnectWise agreements to (re)sync -- populated wholesale by
    // relationships_cw_sync_start(), drained in bounded batches by
    // relationships_cw_sync_step() so a single HTTP request (Bluehost's
    // execution-time limits) or a single cron run never has to process all
    // ~440 agreements in one shot. See connectwise-sync-core.php.
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS cw_sync_queue (
            agreement_id INTEGER PRIMARY KEY,
            agreement_type_id INTEGER NOT NULL,
            agreement_name TEXT NOT NULL DEFAULT '',
            company_cw_id TEXT NOT NULL,
            company_name TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'pending',
            error_message TEXT,
            queued_at TEXT NOT NULL DEFAULT (datetime('now')),
            processed_at TEXT
        )
    SQL);
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_cw_sync_queue_status ON cw_sync_queue(status)');
    relationships_add_column_if_missing($pdo, 'cw_sync_queue', 'agreement_name', "TEXT NOT NULL DEFAULT ''");

    // Small key/value table for sync run bookkeeping (started_at of the
    // current/most recent run, etc.) -- avoids a dedicated single-row table.
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS cw_sync_meta (
            key TEXT PRIMARY KEY,
            value TEXT
        )
    SQL);

    // Nightly-synced Monthly Billing series (per-customer "YYYY-MM" =>
    // dollar total, from Agreement-generated invoices) -- see
    // connectwise-billing-sync-core.php. Added 2026-09-10 per Michael: the
    // 6-month chart moved from a live-per-dashboard-open ConnectWise query
    // to this nightly sync (same cadence as the agreement/addition sync),
    // while Service Tickets YTD stayed live since that's the kind of
    // number a CRC wants as-of-right-now on a call. One row per
    // (customer, month) -- a full sync just overwrites each month's total,
    // it doesn't accumulate history beyond what's queried each run.
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS customer_monthly_billing (
            customer_id INTEGER NOT NULL REFERENCES customers(id) ON DELETE CASCADE,
            month TEXT NOT NULL,
            total REAL NOT NULL DEFAULT 0,
            synced_at TEXT NOT NULL DEFAULT (datetime('now')),
            PRIMARY KEY (customer_id, month)
        )
    SQL);

    // Annual-cadence counterpart to customer_monthly_billing above -- added
    // 2026-09-15 per Michael: "For accounts that are only being billed
    // annually, I want to see their last 3 years of billings, just like
    // the last 3 months for normal monthly customers." Only ever written
    // for a customer whose billing_cadence (customers table, above) is
    // 'annual' -- see connectwise-billing-sync-core.php. Kept as its own
    // table (year keys, not reused "YYYY" rows inside
    // customer_monthly_billing) specifically so the portfolio-wide Monthly
    // Billing gauge (dashboard.php's `SELECT month, SUM(total) ... FROM
    // customer_monthly_billing GROUP BY month`) can never accidentally mix
    // an annual lump sum into a monthly total -- that gauge and this table
    // are completely untouched by the annual-cadence feature.
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS customer_yearly_billing (
            customer_id INTEGER NOT NULL REFERENCES customers(id) ON DELETE CASCADE,
            year TEXT NOT NULL,
            total REAL NOT NULL DEFAULT 0,
            synced_at TEXT NOT NULL DEFAULT (datetime('now')),
            PRIMARY KEY (customer_id, year)
        )
    SQL);

    // Queue of customers to (re)pull billing for -- same start()/step()
    // shape and reasoning as cw_sync_queue, but keyed by customer (one
    // /finance/invoices call covers a customer's whole 6-month series)
    // rather than by agreement.
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS cw_billing_sync_queue (
            customer_id INTEGER PRIMARY KEY,
            connectwise_id TEXT NOT NULL,
            company_name TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'pending',
            error_message TEXT,
            queued_at TEXT NOT NULL DEFAULT (datetime('now')),
            processed_at TEXT
        )
    SQL);
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_cw_billing_sync_queue_status ON cw_billing_sync_queue(status)');

    // Queue of ConnectWise Companies to (re)sync as pure prospects -- added
    // 2026-09-10 per Michael, so Relationship Coordinators can see (and
    // market every service to) companies that have a real, active-ish
    // relationship with CBT in ConnectWise but no recurring Agreement of
    // any tracked type. Same start()/step() shape as the other two queues,
    // but everything a step needs (id + name) is already known from the one
    // company list call in start() -- unlike agreements/billing, no further
    // per-item ConnectWise round-trip is needed in step(), just a DB
    // upsert. See connectwise-prospect-sync-core.php.
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS cw_prospect_sync_queue (
            connectwise_id TEXT PRIMARY KEY,
            company_name TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'pending',
            error_message TEXT,
            queued_at TEXT NOT NULL DEFAULT (datetime('now')),
            processed_at TEXT
        )
    SQL);
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_cw_prospect_sync_queue_status ON cw_prospect_sync_queue(status)');

    // Front-page Primary Relationship Dashboard metrics -- added 2026-09-10
    // per Michael. Both the Service Ticket volume and Active Contact count
    // trends below reuse the exact "recent 3 months vs prior 3 months
    // average" definition already proven for Monthly Billing
    // (relationships_cw_activity_billing_series_from_totals(), reused
    // as-is against these two new byMonth maps) -- one consistent meaning
    // for "trend" everywhere in the app. Both are nightly-synced, not
    // live, for the same reason Monthly Billing moved off live: the front
    // page needs to render this for every customer at once, and a live
    // ConnectWise round-trip per customer per page load doesn't scale.
    relationships_add_column_if_missing($pdo, 'customers', 'ticket_count_ytd', 'INTEGER');
    relationships_add_column_if_missing($pdo, 'customers', 'active_contact_count', 'INTEGER');

    // Nightly-synced Service Ticket volume history (per-customer "YYYY-MM"
    // => ticket count opened that month, Professional Services boards
    // only -- same board condition as the live Service Tickets YTD query
    // in connectwise-activity.php). See connectwise-ticket-history-sync-core.php.
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS customer_ticket_count_history (
            customer_id INTEGER NOT NULL REFERENCES customers(id) ON DELETE CASCADE,
            month TEXT NOT NULL,
            count INTEGER NOT NULL DEFAULT 0,
            synced_at TEXT NOT NULL DEFAULT (datetime('now')),
            PRIMARY KEY (customer_id, month)
        )
    SQL);

    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS cw_ticket_history_sync_queue (
            customer_id INTEGER PRIMARY KEY,
            connectwise_id TEXT NOT NULL,
            company_name TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'pending',
            error_message TEXT,
            queued_at TEXT NOT NULL DEFAULT (datetime('now')),
            processed_at TEXT
        )
    SQL);
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_cw_ticket_history_sync_queue_status ON cw_ticket_history_sync_queue(status)');

    // Nightly-synced Active Contact count history, same shape as ticket
    // history above. Contact tracking starts from this build's first sync
    // run -- there is no way to ask ConnectWise "how many active contacts
    // did this account have 6 months ago", so (per Michael, "start
    // tracking now") the 6-month trend simply has no real prior-period
    // data for its first ~3-6 months. The shared trend helper already
    // degrades gracefully for that case (percent: null, direction from
    // whatever recent data exists) -- see connectwise-contacts-sync-core.php.
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS customer_contact_count_history (
            customer_id INTEGER NOT NULL REFERENCES customers(id) ON DELETE CASCADE,
            month TEXT NOT NULL,
            count INTEGER NOT NULL DEFAULT 0,
            synced_at TEXT NOT NULL DEFAULT (datetime('now')),
            PRIMARY KEY (customer_id, month)
        )
    SQL);

    // Locally-synced ConnectWise contact roster (active contacts only --
    // see connectwise-contacts-sync-core.php) -- powers both the Active
    // Contact count above and searching the main customer search box by a
    // contact's first/last name or email (per Michael), resolving to that
    // contact's company. Replaced wholesale per customer on each sync run,
    // same as customer_services per agreement.
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS contacts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            customer_id INTEGER NOT NULL REFERENCES customers(id) ON DELETE CASCADE,
            connectwise_contact_id TEXT NOT NULL,
            first_name TEXT NOT NULL DEFAULT '',
            last_name TEXT NOT NULL DEFAULT '',
            email TEXT,
            synced_at TEXT NOT NULL DEFAULT (datetime('now'))
        )
    SQL);
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_contacts_customer ON contacts(customer_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_contacts_first_name ON contacts(first_name COLLATE NOCASE)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_contacts_last_name ON contacts(last_name COLLATE NOCASE)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_contacts_email ON contacts(email COLLATE NOCASE)');

    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS cw_contacts_sync_queue (
            customer_id INTEGER PRIMARY KEY,
            connectwise_id TEXT NOT NULL,
            company_name TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'pending',
            error_message TEXT,
            queued_at TEXT NOT NULL DEFAULT (datetime('now')),
            processed_at TEXT
        )
    SQL);
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_cw_contacts_sync_queue_status ON cw_contacts_sync_queue(status)');

    // 7-step cross-sell checklist progress. One row per (customer, pillar,
    // missing service, step) — created on demand the first time a step is
    // touched, rather than pre-populated for every customer x every
    // missing service x 7 steps (which would be a huge amount of mostly-
    // empty rows). Absence of a row = step not started.
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS checklist_progress (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            customer_id INTEGER NOT NULL REFERENCES customers(id) ON DELETE CASCADE,
            pillar_id TEXT NOT NULL,
            service_id TEXT NOT NULL,
            service_name TEXT NOT NULL,
            step_number INTEGER NOT NULL,
            completed_at TEXT,
            completed_by_user_id INTEGER REFERENCES crc_users(id),
            completed_by_name TEXT,
            UNIQUE(customer_id, pillar_id, service_id, step_number)
        )
    SQL);
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_checklist_lookup ON checklist_progress(pillar_id, service_id, step_number)');

    // Audit trail for the ConnectWise Activity that checklist.php's 'set'
    // action tries to create in the customer's real ConnectWise record every
    // time a checklist step is newly checked off -- added 2026-09-11 per
    // Michael (see connectwise-activity-create.php). One row per attempt
    // (not per checklist_progress row -- unchecking then rechecking the same
    // step is a second genuine completion event and gets a second row here,
    // same as it gets a second real ConnectWise Activity). `status` is
    // 'created' or 'error' -- a failure here NEVER blocks or reverts the
    // checklist_progress save itself (Michael's explicit call), it's purely
    // a record of what ConnectWise did or didn't get, for spot-checking and
    // for a future "failed activities" admin view if that's ever wanted.
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS checklist_cw_activity_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            customer_id INTEGER NOT NULL REFERENCES customers(id) ON DELETE CASCADE,
            pillar_id TEXT NOT NULL,
            service_id TEXT NOT NULL,
            step_number INTEGER NOT NULL,
            status TEXT NOT NULL,
            cw_activity_id TEXT,
            payload_variant TEXT,
            error_message TEXT,
            completed_by_user_id INTEGER REFERENCES crc_users(id),
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        )
    SQL);
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_checklist_cw_activity_log_customer ON checklist_cw_activity_log(customer_id)');

    // OutGrow Last Touch history -- added 2026-09-15 per Michael. One row
    // per touch date ever recorded for a customer (append-only -- nothing
    // here is ever UPDATEd or DELETEd, so "the current value" is always
    // just the newest row for that customer_id). Two kinds of row:
    //   - source = 'manual': a CRC picked a date and clicked Save
    //     (outgrow.php's 'set' action) -- set_by_user_id/set_by_name are
    //     that CRC, from relationships_require_login().
    //   - source = 'connectwise_seed': the ONE-TIME backfill of a value
    //     that already existed in ConnectWise's own "OutGrow Last Touch"
    //     Company custom field before this feature existed (per Michael:
    //     "count that as their first historical entry") -- only ever
    //     inserted when this customer has zero rows here yet, so it can
    //     only ever be the very first (oldest) row for a customer. See
    //     connectwise-outgrow.php. set_by_user_id is NULL for a seed row
    //     (nothing to attribute it to -- it predates this feature).
    // cw_push_status/cw_push_error record whether THIS row's value made it
    // into ConnectWise: NULL for a seed row (that's a read, not a write --
    // nothing was pushed), 'pushed' or 'error' for a manual row (see
    // connectwise-outgrow.php's relationships_cw_outgrow_write()). Same
    // "save locally regardless, never let a ConnectWise failure block or
    // revert the local save" posture as checklist_cw_activity_log above,
    // per Michael's standing instruction for this whole integration.
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS outgrow_last_touch_history (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            customer_id INTEGER NOT NULL REFERENCES customers(id) ON DELETE CASCADE,
            touch_date TEXT NOT NULL,
            source TEXT NOT NULL DEFAULT 'manual',
            set_by_user_id INTEGER REFERENCES crc_users(id),
            set_by_name TEXT NOT NULL,
            cw_push_status TEXT,
            cw_push_error TEXT,
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        )
    SQL);
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_outgrow_history_customer ON outgrow_last_touch_history(customer_id, id)');

    // "Current vendor if not CodeBlue" -- added 2026-09-15 per Michael's
    // Customer Meeting Capture request. One editable note PER PILLAR (not
    // per individual service -- confirmed via AskUserQuestion 2026-09-15):
    // which outside vendor a customer uses for that whole pillar, when
    // they're not sourcing it from CodeBlue. Single current value + who/when
    // last touched it -- same "value + updated_at + updated_by" shape as
    // customers.last_client_checkin_at/by (PeopleFirst), NOT a full history
    // log like outgrow_last_touch_history -- Michael only asked to "see the
    // last time it was updated," not a history list. UNIQUE(customer_id,
    // pillar_id) makes 'set' a plain upsert (delete+insert, same pattern
    // checklist_progress uses) rather than needing a separate lookup path.
    // No ConnectWise sync -- not mentioned in the request, unlike OutGrow
    // Last Touch.
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS pillar_vendor_notes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            customer_id INTEGER NOT NULL REFERENCES customers(id) ON DELETE CASCADE,
            pillar_id TEXT NOT NULL,
            vendor_name TEXT NOT NULL DEFAULT '',
            updated_at TEXT,
            updated_by_user_id INTEGER REFERENCES crc_users(id),
            updated_by_name TEXT,
            UNIQUE(customer_id, pillar_id)
        )
    SQL);

    // Customer Meeting Capture -- added 2026-09-15 per Michael. A logged
    // meeting (subject, date, notes) against a customer, attributed to the
    // CRC who logged it. Each meeting also tries to create a ConnectWise
    // Activity under that customer's Company -- see
    // connectwise-meeting-activity.php -- using the SAME lookup/date/
    // fallback machinery connectwise-activity-create.php already built and
    // proved out for checklist-step completions ("following the same
    // format we use for the Check-list items," per Michael). cw_push_status/
    // cw_push_error/cw_activity_id record that attempt's outcome directly on
    // this row (rather than a separate log table, like
    // outgrow_last_touch_history does) -- one meeting, one Activity attempt,
    // nothing here is ever retried automatically.
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS customer_meetings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            customer_id INTEGER NOT NULL REFERENCES customers(id) ON DELETE CASCADE,
            subject TEXT NOT NULL,
            meeting_date TEXT NOT NULL,
            notes TEXT NOT NULL DEFAULT '',
            logged_by_user_id INTEGER REFERENCES crc_users(id),
            logged_by_name TEXT NOT NULL,
            cw_activity_id TEXT,
            cw_push_status TEXT,
            cw_push_error TEXT,
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        )
    SQL);
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_customer_meetings_customer ON customer_meetings(customer_id, id DESC)');

    // To-do tasks logged under a meeting -- each ALSO tries to create its
    // own ConnectWise Activity, at CREATION time (confirmed via
    // AskUserQuestion 2026-09-15: "so we have historical references to
    // what's been done" reads as logging the assignment as it happens, not
    // waiting for completion -- unlike the cross-sell checklist, which only
    // fires on completion). Assignable to exactly one of the 7 fixed
    // roster names (relationships_todo_roster() in catalog.php).
    // assigned_to_user_id is nullable and OPTIMISTIC: it's filled in by
    // matching assigned_to_name against crc_users.name (case-insensitive)
    // at save time if that person has a Relationships login yet, but
    // assigned_to_name is always stored regardless -- registration is
    // self-service (auth.php's 'register' action, any @codebluetechnology.com
    // email) and a teammate who hasn't signed in yet must still be
    // assignable today, not block the meeting note. customer_id is
    // denormalized off the parent meeting (not just meeting_id) so the
    // global to-do dashboard and per-customer queries don't need a JOIN.
    // completed_at/by is a plain local mark -- no second ConnectWise push on
    // completion, per Michael's answer (CW only fires once, at creation).
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS meeting_tasks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            meeting_id INTEGER NOT NULL REFERENCES customer_meetings(id) ON DELETE CASCADE,
            customer_id INTEGER NOT NULL REFERENCES customers(id) ON DELETE CASCADE,
            description TEXT NOT NULL,
            assigned_to_user_id INTEGER REFERENCES crc_users(id),
            assigned_to_name TEXT NOT NULL,
            created_by_user_id INTEGER REFERENCES crc_users(id),
            created_by_name TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT (datetime('now')),
            completed_at TEXT,
            completed_by_user_id INTEGER REFERENCES crc_users(id),
            completed_by_name TEXT,
            cw_activity_id TEXT,
            cw_push_status TEXT,
            cw_push_error TEXT
        )
    SQL);
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_meeting_tasks_meeting ON meeting_tasks(meeting_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_meeting_tasks_customer ON meeting_tasks(customer_id, id DESC)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_meeting_tasks_assignee ON meeting_tasks(assigned_to_name, completed_at)');

    // Rep-based territory filtering -- added 2026-09-16 per Michael. Every
    // ConnectWise Company carries a `territory{id,name}` field (see
    // register/api/customers.php's research notes -- backed by a
    // /system/locations record); territory_name here is that record's
    // plain display name, kept as free text rather than a numeric id
    // since this app never talks to /system/locations directly. Synced by
    // connectwise-territory-sync-core.php from an UNFILTERED company list
    // (every status, every type -- unlike the Prospect sync's filtered
    // one) so a customer never silently loses its territory tag just
    // because its ConnectWise status isn't one the Prospect sync's filter
    // happens to match. NULL for a mock customer, or a real one that
    // hasn't been through a territory sync yet.
    relationships_add_column_if_missing($pdo, 'customers', 'territory_name', 'TEXT');

    // Which CRC (by email -- matched against the shared Microsoft 365
    // session, see auth/session.php and relationships_allowed_territories()
    // in territory-access.php) is restricted to which territory. A CRC
    // with zero rows here sees every customer, unrestricted -- this table
    // is an allow-list of RESTRICTIONS, not a roster of every rep. One row
    // per (email, territory), so a rep covering several territories (e.g.
    // Moe Okeilli's three) just gets several rows. email is stored
    // lowercased; territory_name should match customers.territory_name
    // exactly (territory-admin.php's picker lists the real synced values
    // to avoid a typo silently hiding every customer from a rep).
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS crc_territory_reps (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT NOT NULL,
            territory_name TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT (datetime('now')),
            UNIQUE(email, territory_name)
        )
    SQL);
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_crc_territory_reps_email ON crc_territory_reps(email)');

    // Queue for the territory sync -- same start()/step() shape as every
    // other ConnectWise sync in this file, for the same Bluehost
    // execution-time reason. Unlike cw_prospect_sync_queue this is never
    // filtered by status/type: it's meant to tag EVERY company this
    // ConnectWise instance knows about, so start() does one unconditioned
    // /company/companies list.
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS cw_territory_sync_queue (
            connectwise_id TEXT PRIMARY KEY,
            company_name TEXT NOT NULL,
            territory_name TEXT,
            status TEXT NOT NULL DEFAULT 'pending',
            error_message TEXT,
            queued_at TEXT NOT NULL DEFAULT (datetime('now')),
            processed_at TEXT
        )
    SQL);
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_cw_territory_sync_queue_status ON cw_territory_sync_queue(status)');

    // Seed Michael's initial territory assignments (2026-09-16), so this
    // ships already configured rather than starting empty. INSERT OR
    // IGNORE -- harmless no-op on every later request once these exist;
    // Michael can edit/remove them from the Territory Admin screen from
    // here on, this is just the starting point.
    $seedTerritoryReps = $pdo->prepare(
        'INSERT OR IGNORE INTO crc_territory_reps (email, territory_name) VALUES (:email, :territory)'
    );
    foreach ([
        ['email' => 'csienko@codebluetechnology.com', 'territory' => 'Arcus + Chester Sienko'],
        ['email' => 'csienko@codebluetechnology.com', 'territory' => "Chester Sienko's Accounts"],
        ['email' => 'mokeilli@codebluetechnology.com', 'territory' => 'ITTS Trading (old accounts)'],
        ['email' => 'mokeilli@codebluetechnology.com', 'territory' => 'Moe Okeilli (new accounts)'],
        ['email' => 'mokeilli@codebluetechnology.com', 'territory' => 'Trey + Moe Okeilli'],
    ] as $seedRow) {
        $seedTerritoryReps->execute([':email' => $seedRow['email'], ':territory' => $seedRow['territory']]);
    }

    // Meeting to-do task email notifications -- added 2026-09-16 per
    // Michael ("the assigned rep needs to receive an email... when To-Do's
    // are created from meetings"). Same outcome-logging pattern as
    // cw_push_status/cw_push_error on this same table: the email attempt
    // never blocks or reverts the local task save (see task-email.php),
    // its outcome is just recorded here so a failure isn't silently lost.
    relationships_add_column_if_missing($pdo, 'meeting_tasks', 'email_status', 'TEXT');
    relationships_add_column_if_missing($pdo, 'meeting_tasks', 'email_error', 'TEXT');

    // Scheduled to-dos -- added 2026-09-16 per Michael ("I want to add the
    // ability to schedule to-dos with a date... show on a calendar in the
    // coordinator's list view"). Optional (per Michael, AskUserQuestion):
    // a to-do can be created with or without a due date; one left blank
    // just never appears on the calendar and is listed as "Unscheduled"
    // in the new per-coordinator to-do view instead. "YYYY-MM-DD", same
    // convention as OutGrow Last Touch and the meeting date field.
    relationships_add_column_if_missing($pdo, 'meeting_tasks', 'due_date', 'TEXT');
}

/**
 * SQLite has no "ADD COLUMN IF NOT EXISTS" -- check PRAGMA table_info first
 * so re-running migrate() on a database that already has the column (every
 * request after the first deploy of a schema change) is a no-op instead of
 * an error.
 */
function relationships_add_column_if_missing(PDO $pdo, string $table, string $column, string $type): void
{
    $stmt = $pdo->query('PRAGMA table_info(' . $table . ')');
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $col) {
        if ($col['name'] === $column) {
            return;
        }
    }
    $pdo->exec("ALTER TABLE $table ADD COLUMN $column $type");
}

function relationships_seed_mock_data(PDO $pdo): void
{
    $catalog = relationships_catalog();

    $insertCustomer = $pdo->prepare(
        'INSERT INTO customers (connectwise_id, name, is_mock, is_peoplefirst, voip_hosted_elsewhere, voip_hosted_agreement_name, is_prospect_only)
         VALUES (:cw, :name, 1, :pf, :hv, :hvname, :prospect)'
    );
    $insertService = $pdo->prepare(
        'INSERT INTO customer_services (customer_id, pillar_id, pillar_name, service_id, service_name, product_label, qty, unit, source)
         VALUES (:customer_id, :pillar_id, :pillar_name, :service_id, :service_name, :product_label, :qty, :unit, \'mock\')'
    );

    $addService = function (int $customerId, string $pillarId, string $serviceId, string $productLabel, float $qty, ?string $unit) use ($insertService, $catalog): void {
        $insertService->execute([
            ':customer_id' => $customerId,
            ':pillar_id' => $pillarId,
            ':pillar_name' => $catalog[$pillarId]['name'],
            ':service_id' => $serviceId,
            ':service_name' => $catalog[$pillarId]['services'][$serviceId],
            ':product_label' => $productLabel,
            ':qty' => $qty,
            ':unit' => $unit,
        ]);
    };

    // A handful of realistic sample customers with deliberately varied
    // service mixes, so every part of the dashboard (bright pillars, dark
    // pillars, drill-down quantities, an empty-roster edge case) has
    // something real to show against.

    $insertCustomer->execute([':cw' => 'MOCK-1001', ':name' => 'Riverbend Family Dental', ':pf' => 1, ':hv' => 0, ':hvname' => null, ':prospect' => 0]);
    $c1 = (int) $pdo->lastInsertId();
    $addService($c1, 'it', 'managed-it', 'PeopleFirst Managed IT — Per Person', 12, 'people');
    $addService($c1, 'it', 'cyber-security', 'SentinelOne EDR', 18, 'per workstation');
    $addService($c1, 'it', 'cyber-security', 'Guardz', 18, 'per workstation');
    $addService($c1, 'it', 'provided-equipment', 'Provided Firewall — CBT145', 1, 'device');
    // No Data Center, VoIP, Cabling, or Premise Security — good "mostly dark"
    // example. PeopleFirst top-tier member (is_peoplefirst) — good example
    // for the gold search/header highlight even with a mostly-dark pillar grid.

    $insertCustomer->execute([':cw' => 'MOCK-1002', ':name' => 'Blue Ridge Manufacturing', ':pf' => 0, ':hv' => 0, ':hvname' => null, ':prospect' => 0]);
    $c2 = (int) $pdo->lastInsertId();
    $addService($c2, 'it', 'managed-it', 'PeopleFirst Managed IT — Per Person', 64, 'people');
    $addService($c2, 'it', 'cyber-security', 'SentinelOne EDR', 71, 'per workstation');
    $addService($c2, 'it', 'cyber-security', 'Managed Patch Management', 71, 'per workstation');
    $addService($c2, 'it', 'provided-equipment', 'Provided Firewall — CBM390', 2, 'device');
    $addService($c2, 'dc', 'private-cloud', 'Private Cloud Hosting', 1, 'environment');
    $addService($c2, 'dc', 'disaster-recovery', 'Failover and Disaster Recovery', 1, 'plan');
    $addService($c2, 'voip', 'cloud-voice', 'Cloud Voice System', 64, 'seats');
    $addService($c2, 'cabling', 'cabling-business', 'Data Cabling for Business', 1, 'site');
    // No Premise Security — a good single-pillar-missing example.

    $insertCustomer->execute([
        ':cw' => 'MOCK-1003', ':name' => 'Commonwealth Title & Escrow', ':pf' => 0,
        ':hv' => 1, ':hvname' => 'Voice Agreement - Zultys Hosted', ':prospect' => 0,
    ]);
    $c3 = (int) $pdo->lastInsertId();
    $addService($c3, 'it', 'help-desk', 'Help Desk Support', 22, 'people');
    $addService($c3, 'security', 'ip-cameras', 'IP Camera System — 8 cameras', 8, 'cameras');
    $addService($c3, 'security', 'access-control', 'Access Control System', 4, 'doors');
    // IT only has Help Desk (no Cyber Security, no Managed IT) — good
    // partial-pillar example (pillar shows bright, but roster inside still
    // lists the missing IT services). Also this build's example of a
    // manufacturer-hosted voice platform (voip_hosted_elsewhere) — an
    // empty ConnectWise Voice Agreement, no VoIP cross-sell should be
    // suggested for it even though the VoIP pillar shows no active
    // CodeBlue-sold services.

    $insertCustomer->execute([':cw' => 'MOCK-1004', ':name' => 'Tidewater Logistics Group', ':pf' => 0, ':hv' => 0, ':hvname' => null, ':prospect' => 0]);
    $c4 = (int) $pdo->lastInsertId();
    $addService($c4, 'it', 'managed-it', 'PeopleFirst Managed IT — Per Person', 140, 'people');
    $addService($c4, 'it', 'cyber-security', 'SentinelOne EDR', 155, 'per workstation');
    $addService($c4, 'it', 'cyber-security', 'Guardz', 155, 'per workstation');
    $addService($c4, 'it', 'cyber-security', 'Managed Patch Management', 155, 'per workstation');
    $addService($c4, 'it', 'provided-equipment', 'Provided Firewall — CBM590 (Advanced Security)', 3, 'device');
    $addService($c4, 'it', 'equipment-sales', 'New Workstation Purchase', 12, 'units');
    $addService($c4, 'dc', 'private-cloud', 'Private Cloud Hosting', 1, 'environment');
    $addService($c4, 'dc', 'public-cloud', 'Microsoft Azure Management', 1, 'tenant');
    $addService($c4, 'dc', 'internet-sourcing', 'Internet Connectivity Sourcing', 3, 'circuits');
    $addService($c4, 'dc', 'hardware-hosting', 'Hardware Hosting', 1, 'rack');
    $addService($c4, 'dc', 'disaster-recovery', 'Failover and Disaster Recovery', 1, 'plan');
    $addService($c4, 'voip', 'cloud-voice', 'Cloud Voice System', 140, 'seats');
    $addService($c4, 'voip', 'call-center', 'Call Center', 12, 'agents');
    $addService($c4, 'voip', 'conference-room', 'Conference Room Solutions', 4, 'rooms');
    $addService($c4, 'cabling', 'cabling-business', 'Data Cabling for Business', 1, 'site');
    $addService($c4, 'cabling', 'data-closet', 'Data Closet Installation', 2, 'closets');
    $addService($c4, 'security', 'ip-cameras', 'IP Camera System — 24 cameras', 24, 'cameras');
    $addService($c4, 'security', 'access-control', 'Access Control System', 18, 'doors');
    // Every pillar lit up — good "fully engaged, nothing to cross-sell at
    // the pillar level" example (roster inside each pillar can still show
    // a missing service or two).

    $insertCustomer->execute([':cw' => 'MOCK-1005', ':name' => 'Piedmont Veterinary Partners', ':pf' => 0, ':hv' => 0, ':hvname' => null, ':prospect' => 0]);
    $c5 = (int) $pdo->lastInsertId();
    // Zero active services -- good empty-state example (every pillar dark).
    // NOT flagged is_prospect_only -- this one's just an ordinary customer
    // who happens to have nothing synced yet, distinct from the dedicated
    // Prospect example below.

    $insertCustomer->execute([':cw' => 'MOCK-1006', ':name' => 'Harborview Consulting Group', ':pf' => 0, ':hv' => 0, ':hvname' => null, ':prospect' => 1]);
    $c6 = (int) $pdo->lastInsertId();
    // Zero active services AND is_prospect_only -- CodeBlue's Prospect sync
    // example (connectwise-prospect-sync-core.php): a real ConnectWise
    // Company with no agreement of any kind, badged distinctly from an
    // ordinary customer with nothing synced (Piedmont above) so a CRC can
    // tell "cold/warm lead" apart from "existing customer, just nothing
    // recorded here yet."
}
