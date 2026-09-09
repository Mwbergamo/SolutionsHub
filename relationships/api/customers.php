<?php
/**
 * relationships/api/customers.php
 *
 * Read-only customer + service data for the dashboard. Every row currently
 * comes from the mock data seeded in db.php's relationships_seed_mock_data()
 * (source = 'mock') — this is a stand-in for a real ConnectWise sync
 * (customer's active pillar agreements + the active additions on each),
 * which needs API credentials CodeBlue hasn't provided yet. Swapping mock
 * data for real ConnectWise data later only touches db.php's seeding (or a
 * future sync job) — this endpoint's shape doesn't need to change.
 *
 * GET /relationships/api/customers.php?action=list&q=search+text
 *   -> { ok: true, customers: [{ id, name }, ...] }
 *
 * GET /relationships/api/customers.php?action=detail&id=123
 *   -> { ok: true, customer: { id, name },
 *        pillars: [{ id, name, active: bool,
 *                     services: [{ id, name, active: bool,
 *                                  products: [{ label, qty, unit }, ...] }, ...] }, ...] }
 */

declare(strict_types=1);

require_once __DIR__ . '/_util.php';

$pdo = relationships_db();
relationships_require_login($pdo);

// Mirrors the catalog in db.php's seeder — see the comment there for why
// this isn't just imported from SolutionsHub's app.js. Kept in sync by
// hand; a future ConnectWise sync should validate pillar_id/service_id
// against this same list before writing customer_services rows.
const RELATIONSHIPS_CATALOG = [
    'it' => ['name' => 'IT Services', 'services' => [
        'managed-it' => 'Managed IT Services',
        'vcio' => 'vCIO',
        'cyber-security' => 'Cyber Security',
        'provided-equipment' => 'Provided Equipment',
        'help-desk' => 'Help Desk Support',
        'onsite-support' => 'On-Site Technical Support',
        'equipment-sales' => 'Equipment Sales',
    ]],
    'dc' => ['name' => 'Data Center Services', 'services' => [
        'private-cloud' => 'Private Cloud Hosting',
        'public-cloud' => 'Public Cloud Hosting',
        'internet-sourcing' => 'Internet Connectivity Sourcing',
        'hardware-hosting' => 'Hardware Hosting',
        'disaster-recovery' => 'Failover and Disaster Recovery',
    ]],
    'voip' => ['name' => 'Voice over IP Services', 'services' => [
        'cloud-voice' => 'Cloud Voice System',
        'premise-voice' => 'Premise Voice System',
        'sip-trunking' => 'SIP Trunking',
        'call-center' => 'Call Center',
        'phone-hardware' => 'Phone Hardware Solutions',
        'conference-room' => 'Conference Room Solutions',
    ]],
    'cabling' => ['name' => 'Data Cabling', 'services' => [
        'cabling-business' => 'Data Cabling for Business',
        'cabling-repair' => 'Cabling Repair',
        'data-closet' => 'Data Closet Installation',
        'cabling-docs' => 'Cabling Documentation',
        'cabling-supplies' => 'Cabling Supplies',
    ]],
    'security' => ['name' => 'Premise Security', 'services' => [
        'ip-cameras' => 'IP Security Camera Systems',
        'access-control' => 'Access Control Systems',
    ]],
];

$action = $_GET['action'] ?? '';

if ($action === 'list') {
    $q = trim((string) ($_GET['q'] ?? ''));
    if ($q === '') {
        $stmt = $pdo->query('SELECT id, name FROM customers ORDER BY name ASC LIMIT 200');
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stmt = $pdo->prepare('SELECT id, name FROM customers WHERE name LIKE :q ORDER BY name ASC LIMIT 50');
        $stmt->execute([':q' => '%' . $q . '%']);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    $customers = array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'name' => $r['name']], $rows);
    relationships_respond(200, ['ok' => true, 'customers' => $customers]);
}

if ($action === 'detail') {
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) {
        relationships_respond(400, ['ok' => false, 'error' => 'Missing customer id.']);
    }

    $custStmt = $pdo->prepare('SELECT id, name FROM customers WHERE id = :id');
    $custStmt->execute([':id' => $id]);
    $customer = $custStmt->fetch(PDO::FETCH_ASSOC);
    if ($customer === false) {
        relationships_respond(404, ['ok' => false, 'error' => 'Customer not found.']);
    }

    $svcStmt = $pdo->prepare(
        'SELECT pillar_id, service_id, product_label, qty, unit
         FROM customer_services WHERE customer_id = :id
         ORDER BY pillar_id, service_id, product_label'
    );
    $svcStmt->execute([':id' => $id]);
    $rows = $svcStmt->fetchAll(PDO::FETCH_ASSOC);

    // Group active rows by pillar -> service so the catalog walk below can
    // just look up "does this service have any active products".
    $activeByService = []; // "pillarId::serviceId" => [ {label, qty, unit}, ... ]
    foreach ($rows as $r) {
        $key = $r['pillar_id'] . '::' . $r['service_id'];
        $activeByService[$key] ??= [];
        $activeByService[$key][] = [
            'label' => $r['product_label'],
            'qty' => (float) $r['qty'] == (int) $r['qty'] ? (int) $r['qty'] : (float) $r['qty'],
            'unit' => $r['unit'],
        ];
    }

    $pillars = [];
    foreach (RELATIONSHIPS_CATALOG as $pillarId => $pillarDef) {
        $services = [];
        $pillarActive = false;
        foreach ($pillarDef['services'] as $serviceId => $serviceName) {
            $key = $pillarId . '::' . $serviceId;
            $products = $activeByService[$key] ?? [];
            $active = count($products) > 0;
            if ($active) {
                $pillarActive = true;
            }
            $services[] = [
                'id' => $serviceId,
                'name' => $serviceName,
                'active' => $active,
                'products' => $products,
            ];
        }
        $pillars[] = [
            'id' => $pillarId,
            'name' => $pillarDef['name'],
            'active' => $pillarActive,
            'services' => $services,
        ];
    }

    relationships_respond(200, [
        'ok' => true,
        'customer' => ['id' => (int) $customer['id'], 'name' => $customer['name']],
        'pillars' => $pillars,
    ]);
}

relationships_respond(400, ['ok' => false, 'error' => 'Unknown action.']);
