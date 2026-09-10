<?php
/**
 * relationships/api/dashboard.php
 *
 * Primary Relationship Dashboard -- the front page's overview data, added
 * 2026-09-10 per Michael's request: "gauges" (rectangular KPI tiles under
 * the search bar, "there will be a dozen gauges very soon") plus a
 * per-customer list with 6-month trend indicators for Agreement Billing,
 * Service Tickets YTD, and Active Contacts. Tapping a customer in that list
 * is handled entirely client-side (app.js reuses the same select-customer
 * action the search box already uses) -- this endpoint only returns data.
 *
 * Everything here reads already-synced local data (customer_monthly_billing,
 * customer_ticket_count_history, customer_contact_count_history, and the
 * customers.ticket_count_ytd / customers.active_contact_count columns) --
 * no live ConnectWise calls, same reasoning as Monthly Billing's move to a
 * nightly sync (see connectwise-billing-sync-core.php's file header): this
 * page needs every customer's numbers at once, so a live round-trip per
 * customer wouldn't scale.
 *
 * GET /relationships/api/dashboard.php?action=overview
 *   -> { ok: true,
 *        gauges: [ { key, label, value, format: 'count'|'trend', trend? }, ... ],
 *        customers: [ { id, name, is_peoplefirst, is_prospect_only,
 *                        billing_trend: { direction, percent },
 *                        ticket_count_ytd, ticket_trend: { direction, percent },
 *                        contact_count, contact_trend: { direction, percent } }, ... ] }
 *
 * gauges is deliberately a flat, ordered array (not a fixed set of named
 * fields) so more tiles can be added later -- per Michael, "there will be a
 * dozen gauges very soon" -- without changing this endpoint's shape; the
 * frontend just renders whatever comes back. The five gauges below (total
 * customers, total prospects, portfolio billing trend, tickets YTD, active
 * contacts) are a starting set covering what this feature asked for; which
 * exact gauges belong here long-term hasn't been separately confirmed with
 * Michael beyond that.
 *
 * billing_trend / ticket_trend / contact_trend all use the exact same
 * recent-3-vs-prior-3-month shared helper (relationships_cw_activity_billing_series_from_totals()
 * in connectwise-activity.php) as their respective detail-view trends, so a
 * customer's trend badge here always means the same thing it means on their
 * own dashboard. A customer with no synced history yet (never synced, or a
 * mock/seed customer) reads back direction: 'flat', percent: null -- the
 * frontend shows that as "not enough history yet" rather than a misleading
 * 0%/spike.
 */

declare(strict_types=1);

require_once __DIR__ . '/_util.php';
require_once __DIR__ . '/connectwise-activity.php';
require_once __DIR__ . '/connectwise-billing-sync-core.php';
require_once __DIR__ . '/connectwise-ticket-history-sync-core.php';
require_once __DIR__ . '/connectwise-contacts-sync-core.php';

$pdo = relationships_db();
relationships_require_login($pdo);

$action = $_GET['action'] ?? '';

if ($action === 'overview') {
    $customerRows = $pdo->query(
        'SELECT id, name, is_peoplefirst, is_prospect_only, ticket_count_ytd, active_contact_count
         FROM customers ORDER BY name ASC LIMIT 500'
    )->fetchAll(PDO::FETCH_ASSOC);

    $customers = [];
    $totalTicketsYtd = 0;
    $totalContacts = 0;
    $totalProspects = 0;
    foreach ($customerRows as $r) {
        $customerId = (int) $r['id'];
        $isProspect = (bool) $r['is_prospect_only'];
        if ($isProspect) {
            $totalProspects++;
        }
        $ticketYtd = $r['ticket_count_ytd'] !== null ? (int) $r['ticket_count_ytd'] : 0;
        $contactCount = $r['active_contact_count'] !== null ? (int) $r['active_contact_count'] : 0;
        $totalTicketsYtd += $ticketYtd;
        $totalContacts += $contactCount;

        $billingTrend = relationships_cw_billing_stored_series($pdo, $customerId)['trend'];
        $ticketTrend = relationships_cw_ticket_history_trend($pdo, $customerId);
        $contactTrend = relationships_cw_contacts_trend($pdo, $customerId);

        $customers[] = [
            'id' => $customerId,
            'name' => $r['name'],
            'is_peoplefirst' => (bool) $r['is_peoplefirst'],
            'is_prospect_only' => $isProspect,
            'billing_trend' => $billingTrend,
            'ticket_count_ytd' => $ticketYtd,
            'ticket_trend' => $ticketTrend,
            'contact_count' => $contactCount,
            'contact_trend' => $contactTrend,
        ];
    }

    // Portfolio-wide billing trend: same recent-3-vs-prior-3 math, applied
    // to the SUM of every customer's monthly billing rather than one
    // customer's -- so the gauge means "is the whole book trending up or
    // down", not any single account's number.
    $portfolioByMonth = $pdo->query(
        'SELECT month, SUM(total) AS total FROM customer_monthly_billing GROUP BY month'
    )->fetchAll(PDO::FETCH_KEY_PAIR);
    $portfolioBillingTrend = relationships_cw_activity_billing_series_from_totals($portfolioByMonth, 6)['trend'];

    $totalCustomers = count($customerRows);

    $gauges = [
        ['key' => 'total_customers', 'label' => 'Total Customers', 'value' => $totalCustomers, 'format' => 'count'],
        ['key' => 'total_prospects', 'label' => 'Total Prospects', 'value' => $totalProspects, 'format' => 'count'],
        ['key' => 'portfolio_billing_trend', 'label' => 'Portfolio Billing Trend', 'value' => null, 'format' => 'trend', 'trend' => $portfolioBillingTrend],
        ['key' => 'tickets_ytd', 'label' => 'Service Tickets YTD', 'value' => $totalTicketsYtd, 'format' => 'count'],
        ['key' => 'active_contacts', 'label' => 'Active Contacts', 'value' => $totalContacts, 'format' => 'count'],
    ];

    relationships_respond(200, [
        'ok' => true,
        'gauges' => $gauges,
        'customers' => $customers,
    ]);
}

relationships_respond(400, ['ok' => false, 'error' => 'Unknown action.']);
