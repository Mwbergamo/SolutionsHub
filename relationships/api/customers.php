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
 *   -> { ok: true, customers: [{ id, name, is_peoplefirst: bool, is_prospect_only: bool,
 *                                 is_residential: bool }, ...] }
 *
 * GET /relationships/api/customers.php?action=detail&id=123
 *   -> { ok: true, customer: { id, name, is_peoplefirst: bool,
 *                               last_client_checkin_at, last_client_checkin_by,
 *                               last_risk_scan_at, last_risk_scan_by,
 *                               voip_hosted_elsewhere: bool, voip_hosted_agreement_name,
 *                               is_prospect_only: bool, is_residential: bool,
 *                               cw_status_name,
 *                               billing_trend, ticket_trend, contact_trend,
 *                               cx_issue_ticket_count_90d, cx_issue_synced_at,
 *                               opportunity_score, opportunity_label },
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
 * is_prospect_only / is_residential classify every non-Vendor ConnectWise
 * Company into exactly one of Active (neither flag set) / Prospect /
 * Residential -- see connectwise-prospect-sync-core.php's
 * relationships_cw_classify_company_bucket() for the exact rule (in short:
 * status "Residential" always wins; otherwise a real agreement OR status
 * Active/Delinquent/Special Info is Active; everything else is Prospect).
 * Added 2026-09-26 per Michael, expanding what was originally (2026-09-10)
 * a Prospect-only flag. cw_status_name is the raw live ConnectWise Company
 * status string these flags were derived from. Neither flag carries other
 * behavior here: a zero-service company already comes back inactive/
 * cross-sell-eligible through the normal code path below -- the flags
 * exist purely so the UI can badge "zero existing relationship" (app.js)
 * and split the front-page customer list into its three blocks.
 *
 * The list action's search (added 2026-09-10, per Michael) also matches a
 * synced ConnectWise Contact's first name, last name, or email (the
 * `contacts` table -- see connectwise-contacts-sync-core.php) and resolves
 * to that contact's parent company, so a CRC can find "William Munn" or
 * "wmunn@..." even when that text never appears in the company name. This
 * is local-data-only (per Michael's "search synced local data" choice, not
 * a live ConnectWise lookup) -- a contact who hasn't synced yet (nightly
 * sync hasn't run, or the sync's email-field mapping turns out to be wrong
 * -- see that file's header) simply won't be found by name/email search
 * until the next successful sync, though their company is still findable
 * by company name as always. A matched-via-contact result carries
 * matched_contact_name so the UI can show why it's in the list ("via
 * William Munn").
 *
 * billing_trend / ticket_trend / contact_trend / cx_issue_ticket_count_90d /
 * opportunity_score / opportunity_label on the detail action -- added
 * 2026-10-07 per Michael: "I'd also like the popover data to show in the
 * customer's profile. This will give the reps a direct report after their
 * attention is brought to issues." Same scoring formula dashboard.php's
 * Overview list uses (opportunity-scoring.php), computed fresh for this one
 * customer rather than reused from a bulk list, so the profile page always
 * reflects this customer's current synced numbers even when opened
 * directly (e.g. from a search result) rather than clicked from the
 * Overview list.
 */

declare(strict_types=1);

require_once __DIR__ . '/_util.php';
require_once __DIR__ . '/territory-access.php';
require_once __DIR__ . '/prospecting-core.php';
// Account Opportunity/Risk score, added 2026-10-07 per Michael's "show the
// popover data in the customer's profile" follow-up -- the 'detail' action
// below returns the same score/label/trend breakdown dashboard.php's
// Overview list shows, computed from the exact same shared formula (see
// opportunity-scoring.php) so a rep opening this customer sees the same
// "why" a coordinator saw on the front page.
require_once __DIR__ . '/opportunity-scoring.php';
require_once __DIR__ . '/connectwise-billing-sync-core.php';
require_once __DIR__ . '/connectwise-ticket-history-sync-core.php';
require_once __DIR__ . '/connectwise-contacts-sync-core.php';

$pdo = relationships_db();
relationships_require_login($pdo);
$allowedTerritories = relationships_allowed_territories($pdo);

$action = $_GET['action'] ?? '';

if ($action === 'list') {
    $q = trim((string) ($_GET['q'] ?? ''));
    $matchedContact = []; // customer id => "First Last" of the contact that matched, when matched via a contact

    // Rep-based territory filtering (see territory-access.php). $customers
    // below is unqualified in the no-search/name-search branches, and
    // aliased "c" in the contact-join branch -- build both fragments once.
    $territoryFilter = $allowedTerritories === null
        ? ['sql' => '', 'params' => []]
        : relationships_territory_filter_sql($allowedTerritories, 'customers');
    $territoryFilterC = $allowedTerritories === null
        ? ['sql' => '', 'params' => []]
        : relationships_territory_filter_sql($allowedTerritories, 'c');

    if ($q === '') {
        $stmt = $pdo->prepare("SELECT id, name, is_peoplefirst, is_prospect_only, is_residential FROM customers WHERE 1=1 {$territoryFilter['sql']} ORDER BY name ASC LIMIT 200");
        $stmt->execute($territoryFilter['params']);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $like = '%' . $q . '%';

        $nameStmt = $pdo->prepare("SELECT id, name, is_peoplefirst, is_prospect_only, is_residential FROM customers WHERE name LIKE :q {$territoryFilter['sql']} ORDER BY name ASC LIMIT 50");
        $nameStmt->execute(array_merge([':q' => $like], $territoryFilter['params']));
        $nameRows = $nameStmt->fetchAll(PDO::FETCH_ASSOC);

        // Also match a synced ConnectWise Contact's first/last name or
        // email and resolve to their parent company -- local data only,
        // see the file header above.
        $contactStmt = $pdo->prepare(
            "SELECT c.id, c.name, c.is_peoplefirst, c.is_prospect_only, c.is_residential,
                    ct.first_name AS matched_first_name, ct.last_name AS matched_last_name
             FROM contacts ct
             JOIN customers c ON c.id = ct.customer_id
             WHERE (ct.first_name LIKE :q1 OR ct.last_name LIKE :q2 OR ct.email LIKE :q3
                OR (ct.first_name || ' ' || ct.last_name) LIKE :q4)
                {$territoryFilterC['sql']}
             ORDER BY c.name ASC LIMIT 50"
        );
        $contactStmt->execute(array_merge([':q1' => $like, ':q2' => $like, ':q3' => $like, ':q4' => $like], $territoryFilterC['params']));
        $contactRows = $contactStmt->fetchAll(PDO::FETCH_ASSOC);

        $rows = [];
        foreach ($nameRows as $r) {
            $rows[(int) $r['id']] = $r;
        }
        foreach ($contactRows as $r) {
            $id = (int) $r['id'];
            if (!isset($rows[$id])) {
                $rows[$id] = $r;
            }
            if (!isset($matchedContact[$id])) {
                $matchedContact[$id] = trim($r['matched_first_name'] . ' ' . $r['matched_last_name']);
            }
        }
        $rows = array_values($rows);
    }

    $customers = array_map(
        static function (array $r) use ($matchedContact): array {
            $id = (int) $r['id'];
            return [
                'id' => $id,
                'name' => $r['name'],
                'is_peoplefirst' => (bool) $r['is_peoplefirst'],
                'is_prospect_only' => (bool) $r['is_prospect_only'],
                'is_residential' => (bool) $r['is_residential'],
                'matched_contact_name' => $matchedContact[$id] ?? null,
            ];
        },
        $rows
    );
    relationships_respond(200, ['ok' => true, 'customers' => $customers]);
}

// ?action=resolve&cw_id=N -- ConnectWise company id -> local customer id, used by the
// Commissions "open invoices" report to link a customer name to its page here.
if ($action === 'resolve') {
    $cw = trim((string) ($_GET['cw_id'] ?? ''));
    if ($cw === '' || !ctype_digit($cw)) {
        relationships_respond(400, ['ok' => false, 'error' => 'Missing ConnectWise company id.']);
    }
    $resStmt = $pdo->prepare('SELECT id, territory_name FROM customers WHERE connectwise_id = :cw LIMIT 1');
    $resStmt->execute([':cw' => $cw]);
    $found = $resStmt->fetch(PDO::FETCH_ASSOC);
    if ($found === false) {
        relationships_respond(404, ['ok' => false, 'error' => 'That customer is not in Relationships yet.']);
    }
    relationships_require_territory_scope($allowedTerritories, $found['territory_name']);
    relationships_respond(200, ['ok' => true, 'id' => (int) $found['id']]);
}

if ($action === 'detail') {
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) {
        relationships_respond(400, ['ok' => false, 'error' => 'Missing customer id.']);
    }

    $custStmt = $pdo->prepare(
        'SELECT id, connectwise_id, name, is_peoplefirst, last_client_checkin_at, last_client_checkin_by, last_risk_scan_at, last_risk_scan_by,
                voip_hosted_elsewhere, voip_hosted_agreement_name, is_prospect_only, is_residential, cw_status_name, territory_name,
                cx_issue_ticket_count_90d, cx_issue_synced_at
         FROM customers WHERE id = :id'
    );
    $custStmt->execute([':id' => $id]);
    $customer = $custStmt->fetch(PDO::FETCH_ASSOC);
    if ($customer === false) {
        relationships_respond(404, ['ok' => false, 'error' => 'Customer not found.']);
    }
    relationships_require_territory_scope($allowedTerritories, $customer['territory_name']);

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

    // Account Opportunity/Risk score -- see opportunity-scoring.php. Same
    // three trend lookups dashboard.php's Overview list already makes per
    // customer, just for this one id instead of the whole portfolio.
    $billingTrend = relationships_cw_billing_stored_series($pdo, (int) $customer['id'])['trend'];
    $ticketTrend = relationships_cw_ticket_history_trend($pdo, (int) $customer['id']);
    $contactTrend = relationships_cw_contacts_trend($pdo, (int) $customer['id']);
    $cxIssueCount = (int) ($customer['cx_issue_ticket_count_90d'] ?? 0);
    $opportunity = relationships_account_opportunity_score($billingTrend, $ticketTrend, $contactTrend, $cxIssueCount);

    relationships_respond(200, [
        'ok' => true,
        'customer' => [
            'id' => (int) $customer['id'],
            'connectwise_id' => $customer['connectwise_id'] !== null ? (string) $customer['connectwise_id'] : null,
            'name' => $customer['name'],
            'is_peoplefirst' => (bool) $customer['is_peoplefirst'],
            'last_client_checkin_at' => $customer['last_client_checkin_at'],
            'last_client_checkin_by' => $customer['last_client_checkin_by'],
            'last_risk_scan_at' => $customer['last_risk_scan_at'],
            'last_risk_scan_by' => $customer['last_risk_scan_by'],
            'voip_hosted_elsewhere' => $voipHostedElsewhere,
            'voip_hosted_agreement_name' => $customer['voip_hosted_agreement_name'],
            'is_prospect_only' => (bool) $customer['is_prospect_only'],
            'is_residential' => (bool) $customer['is_residential'],
            'cw_status_name' => $customer['cw_status_name'],
            // Prospecting's 90-day claim (null unless claimed via Prospecting
            // and not yet promoted) -- see prospecting-core.php.
            'prospect_claim' => (bool) $customer['is_prospect_only'] ? relationships_prospect_claim_for_customer($pdo, (int) $customer['id']) : null,
            // Opportunity/Risk rank explainer -- added 2026-10-07. Same
            // field names/shapes as dashboard.php's Overview list rows, so
            // app.js's opportunityPopoverContentHtml() can render this
            // customer's own profile panel with the exact same function
            // used for the Overview list's hover/click popover.
            'billing_trend' => $billingTrend,
            'ticket_trend' => $ticketTrend,
            'contact_trend' => $contactTrend,
            'cx_issue_ticket_count_90d' => $cxIssueCount,
            'cx_issue_synced_at' => $customer['cx_issue_synced_at'],
            'opportunity_score' => $opportunity['score'],
            'opportunity_label' => $opportunity['label'],
        ],
        'pillars' => $pillars,
    ]);
}

relationships_respond(400, ['ok' => false, 'error' => 'Unknown action.']);
