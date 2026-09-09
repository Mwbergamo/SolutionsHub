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
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        )
    SQL);

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
            source TEXT NOT NULL DEFAULT 'mock'
        )
    SQL);
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_customer_services_customer ON customer_services(customer_id)');

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

function relationships_seed_mock_data(PDO $pdo): void
{
    $catalog = relationships_catalog();

    $insertCustomer = $pdo->prepare('INSERT INTO customers (connectwise_id, name, is_mock) VALUES (:cw, :name, 1)');
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

    $insertCustomer->execute([':cw' => 'MOCK-1001', ':name' => 'Riverbend Family Dental']);
    $c1 = (int) $pdo->lastInsertId();
    $addService($c1, 'it', 'managed-it', 'PeopleFirst Managed IT — Per Person', 12, 'people');
    $addService($c1, 'it', 'cyber-security', 'SentinelOne EDR', 18, 'per workstation');
    $addService($c1, 'it', 'cyber-security', 'Guardz', 18, 'per workstation');
    $addService($c1, 'it', 'provided-equipment', 'Provided Firewall — CBT145', 1, 'device');
    // No Data Center, VoIP, Cabling, or Premise Security — good "mostly dark" example.

    $insertCustomer->execute([':cw' => 'MOCK-1002', ':name' => 'Blue Ridge Manufacturing']);
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

    $insertCustomer->execute([':cw' => 'MOCK-1003', ':name' => 'Commonwealth Title & Escrow']);
    $c3 = (int) $pdo->lastInsertId();
    $addService($c3, 'it', 'help-desk', 'Help Desk Support', 22, 'people');
    $addService($c3, 'security', 'ip-cameras', 'IP Camera System — 8 cameras', 8, 'cameras');
    $addService($c3, 'security', 'access-control', 'Access Control System', 4, 'doors');
    // IT only has Help Desk (no Cyber Security, no Managed IT) — good
    // partial-pillar example (pillar shows bright, but roster inside still
    // lists the missing IT services).

    $insertCustomer->execute([':cw' => 'MOCK-1004', ':name' => 'Tidewater Logistics Group']);
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

    $insertCustomer->execute([':cw' => 'MOCK-1005', ':name' => 'Piedmont Veterinary Partners']);
    $c5 = (int) $pdo->lastInsertId();
    // Zero active services -- good empty-state example (every pillar dark).
}
