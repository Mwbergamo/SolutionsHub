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
 *   -> { ok: true, customers: [{ id, name, is_peoplefirst: bool, is_prospect_only: bool }, ...] }
 *
 * GET /relationships/api/customers.php?action=detail&id=123
 *   -> { ok: true, customer: { id, name, is_peoplefirst: bool,
 *                               last_client_checkin_at, last_client_checkin_by,
 *                               last_risk_scan_at, last_risk_scan_by,
 *                               voip_hosted_elsewhere: bool, voip_hosted_agreement_name,
 *                               is_prospect_only: bool },
 *        pillars: [{ id, name, active: bool,
 *                     services: [{ id, name, active: bool,
 *                                  products: [{ label, qty, unit }, ...] }, ...] }, ...] }
 *
 * is_peoplefirst marks CodeBlue's top-tier IT Services customers (see
 * connectwise-sync-core.php) -- little to no cross-sell, but due a
 * quarterly risk assessment / client visit instead. last_client_checkin_at/
 * last_risk_scan_at (+ _by) are only ever set for PeopleFirst customers, via
 * relationships/api/peoplefirst.php?action=log -- see that file for the
 * "needs a checkin/scan this month/quarter" logic used by the Cross-Sell
 * Report.
 *
 * voip_hosted_elsewhere marks a customer whose voice is hosted directly by
 * the manufacturer (e.g. Zultys Hosted) on an active-but-empty ConnectWise
 * Voice Agreement -- CBT isn't selling/marketing VoIP services to them.
 * When true, every VoIP/phone service comes back with cross_sell_eligible
 * forced false (see the override below), regardless of the shared
 * catalog's default -- same override applied in checklist.php's report/
 * queue so these customers never show up needing VoIP outreach.
 *
 * is_prospect_only marks a customer that came from the Prospect sync (a
 * real ConnectWise Company, Active/Delinquent/Special Info status, no
 * Vendor type) rather than an active agreement -- see
 * connectwise-prospect-sync-core.php. It carries no other behavior here:
 * a prospect customer has zero customer_services rows, so every
 * pillar/service already comes back inactive/cross-sell-eligible through
 * the normal code path below -- the flag exists purely so the UI can badge
 * these as "zero existing relationship" rather than "missing a few
 * things" (app.js).
 */

declare(strict_types=1);

require_once __DIR__ . '/_util.php';

$pdo = relationships_db();
relationships_require_login($pdo);

$action = $_GET['action'] ?? '';

if ($action === 'list') {
    $q = trim((string) ($_GET['q'] ?? ''));
    if ($q === '') {
        $stmt = $pdo->query('SELECT id, name, is_peoplefirst, is_prospect_only FROM customers ORDER BY name ASC LIMIT 200');
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stmt = $pdo->prepare('SELECT id, name, is_peoplefirst, is_prospect_only FROM customers WHERE name LIKE :q ORDER BY name ASC LIMIT 50');
        $stmt->execute([':q' => '%' . $q . '%']);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    $customers = array_map(
        static fn (array $r): array => [
            'id' => (int) $r['id'],
            'name' => $r['name'],
            'is_peoplefirst' => (bool) $r['is_peoplefirst'],
            'is_prospect_only' => (bool) $r['is_prospect_only'],
        ],
        $rows
    );
    relationships_respond(200, ['ok' => true, 'customers' => $customers]);
}

if ($action === 'detail') {
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) {
        relationships_respond(400, ['ok' => false, 'error' => 'Missing customer id.']);
    }

    $custStmt = $pdo->prepare(
        'SELECT id, name, is_peoplefirst, last_client_checkin_at, last_client_checkin_by, last_risk_scan_at, last_risk_scan_by,
                voip_hosted_elsewhere, voip_hosted_agreement_name, is_prospect_only
         FROM customers WHERE id = :id'
    );
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

    $voipHostedElsewhere = (bool) $customer['voip_hosted_elsewhere'];

    $pillars = [];
    foreach (relationships_catalog() as $pillarId => $pillarDef) {
        $services = [];
        $pillarActive = false;
        foreach ($pillarDef['services'] as $serviceId => $serviceName) {
            $key = $pillarId . '::' . $serviceId;
            $products = $activeByService[$key] ?? [];
            $active = count($products) > 0;
            if ($active) {
                $pillarActive = true;
            }
            // A customer on a manufacturer-hosted voice platform (Zultys
            // Hosted, etc.) never gets a VoIP/phone cross-sell prompt,
            // even for services the catalog normally flags -- CBT isn't
            // selling against that agreement, so there's nothing to market.
            $crossSellEligible = ($pillarId === 'voip' && $voipHostedElsewhere)
                ? false
                : relationships_is_cross_sell_eligible($pillarId, $serviceId);
            $services[] = [
                'id' => $serviceId,
                'name' => $serviceName,
                'active' => $active,
                'products' => $products,
                'cross_sell_eligible' => $crossSellEligible,
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
        'customer' => [
            'id' => (int) $customer['id'],
            'name' => $customer['name'],
            'is_peoplefirst' => (bool) $customer['is_peoplefirst'],
            'last_client_checkin_at' => $customer['last_client_checkin_at'],
            'last_client_checkin_by' => $customer['last_client_checkin_by'],
            'last_risk_scan_at' => $customer['last_risk_scan_at'],
            'last_risk_scan_by' => $customer['last_risk_scan_by'],
            'voip_hosted_elsewhere' => $voipHostedElsewhere,
            'voip_hosted_agreement_name' => $customer['voip_hosted_agreement_name'],
            'is_prospect_only' => (bool) $customer['is_prospect_only'],
        ],
        'pillars' => $pillars,
    ]);
}

relationships_respond(400, ['ok' => false, 'error' => 'Unknown action.']);
