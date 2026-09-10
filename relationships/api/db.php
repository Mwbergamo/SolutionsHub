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
        'INSERT INTO customers (connectwise_id, name, is_mock, is_peoplefirst, voip_hosted_elsewhere, voip_hosted_agreement_name)
         VALUES (:cw, :name, 1, :pf, :hv, :hvname)'
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

    $insertCustomer->execute([':cw' => 'MOCK-1001', ':name' => 'Riverbend Family Dental', ':pf' => 1, ':hv' => 0, ':hvname' => null]);
    $c1 = (int) $pdo->lastInsertId();
    $addService($c1, 'it', 'managed-it', 'PeopleFirst Managed IT — Per Person', 12, 'people');
    $addService($c1, 'it', 'cyber-security', 'SentinelOne EDR', 18, 'per workstation');
    $addService($c1, 'it', 'cyber-security', 'Guardz', 18, 'per workstation');
    $addService($c1, 'it', 'provided-equipment', 'Provided Firewall — CBT145', 1, 'device');
    // No Data Center, VoIP, Cabling, or Premise Security — good "mostly dark"
    // example. PeopleFirst top-tier member (is_peoplefirst) — good example
    // for the gold search/header highlight even with a mostly-dark pillar grid.

    $insertCustomer->execute([':cw' => 'MOCK-1002', ':name' => 'Blue Ridge Manufacturing', ':pf' => 0, ':hv' => 0, ':hvname' => null]);
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
        ':hv' => 1, ':hvname' => 'Voice Agreement - Zultys Hosted',
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

    $insertCustomer->execute([':cw' => 'MOCK-1004', ':name' => 'Tidewater Logistics Group', ':pf' => 0, ':hv' => 0, ':hvname' => null]);
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

    $insertCustomer->execute([':cw' => 'MOCK-1005', ':name' => 'Piedmont Veterinary Partners', ':pf' => 0, ':hv' => 0, ':hvname' => null]);
    $c5 = (int) $pdo->lastInsertId();
    // Zero active services -- good empty-state example (every pillar dark).
}
