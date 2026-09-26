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
 *        leaderboards: [ { key, label, entries: [ { name, count }, ... ] (0-3, ranked) }, ... ],
 *        customers: [ { id, name, is_peoplefirst, is_prospect_only,
 *                        billing_trend: { direction, percent },
 *                        ticket_count_ytd, ticket_trend: { direction, percent },
 *                        contact_count, contact_trend: { direction, percent } }, ... ] }
 *
 * gauges is deliberately a flat, ordered array (not a fixed set of named
 * fields) so more tiles can be added later -- per Michael, "there will be a
 * dozen gauges very soon" -- without changing this endpoint's shape; the
 * frontend just renders whatever comes back. The four gauges below (total
 * customers, total prospects, portfolio billing trend, active contacts) are
 * a starting set covering what this feature asked for; which exact gauges
 * belong here long-term hasn't been separately confirmed with Michael
 * beyond that. A fifth, "Service Tickets YTD" (a portfolio-wide sum of
 * every customer's ticket_count_ytd), was removed 2026-09-16 per Michael --
 * it wasn't a trustworthy number at the aggregate level and he only needs
 * ticket counts per-customer (still returned below on each customer row,
 * and still shown in the customer list's "Tickets YTD" column and each
 * customer's own dashboard).
 *
 * billing_trend / ticket_trend / contact_trend all use the exact same
 * recent-3-vs-prior-3-month shared helper (relationships_cw_activity_billing_series_from_totals()
 * in connectwise-activity.php) as their respective detail-view trends, so a
 * customer's trend badge here always means the same thing it means on their
 * own dashboard. A customer with no synced history yet (never synced, or a
 * mock/seed customer) reads back direction: 'flat', percent: null -- the
 * frontend shows that as "not enough history yet" rather than a misleading
 * 0%/spike.
 *
 * leaderboards -- added 2026-09-23, per Michael: two small weekly rep
 * leaderboards ("Top 3 reps based on closed tasks for the week" / "Top 3
 * reps based on number of meetings created"), rendered by the frontend as
 * their own pair of tiles alongside the gauges above (leaderboardsHtml()
 * in app.js), with a gold star on whoever's #1. A separate top-level array
 * from gauges rather than a new gauges `format`, since these carry a
 * ranked list of {name, count} rather than one value -- see
 * relationships_leaderboard_week_start_utc()'s comment below for exactly
 * what "week" and "resets Sunday night" mean here. Entries are empty
 * arrays, never null, when nobody's done anything yet this week -- the
 * frontend shows a quiet "nobody yet" line rather than an empty tile.
 */

declare(strict_types=1);

require_once __DIR__ . '/_util.php';
require_once __DIR__ . '/territory-access.php';
require_once __DIR__ . '/connectwise-activity.php';
require_once __DIR__ . '/connectwise-billing-sync-core.php';
require_once __DIR__ . '/connectwise-ticket-history-sync-core.php';
require_once __DIR__ . '/connectwise-contacts-sync-core.php';

$pdo = relationships_db();
relationships_require_login($pdo);
$allowedTerritories = relationships_allowed_territories($pdo);

$action = $_GET['action'] ?? '';

/**
 * Start of the current rep-leaderboard week, as a UTC "Y-m-d H:i:s"
 * string ready to compare directly against this app's stored
 * created_at/completed_at columns (both SQLite datetime('now') -- always
 * UTC). "Week" here is Monday 00:00:00 through Sunday 23:59:59, in
 * America/New_York -- the same timezone every other rep-facing "now" in
 * this app is computed in (see connectwise-activity-create.php,
 * connectwise-meeting-activity.php, meetings.php) -- so per Michael's
 * "reset the count on Sunday night of each week," the leaderboard rolls
 * over to 0 the moment Monday begins Eastern time, not at UTC midnight.
 *
 * There's no actual reset job or stored "week" state anywhere -- this is
 * computed fresh on every request and just used as a WHERE bound, so the
 * leaderboard is automatically empty again once every row from last week
 * has aged out of the window. Nothing to run on a schedule, nothing that
 * can drift out of sync.
 */
function relationships_leaderboard_week_start_utc(): string
{
    $eastern = new DateTimeZone('America/New_York');
    $now = new DateTimeImmutable('now', $eastern);
    $isoDayOfWeek = (int) $now->format('N'); // 1 (Monday) .. 7 (Sunday)
    $weekStart = $now->setTime(0, 0, 0)->modify('-' . ($isoDayOfWeek - 1) . ' days');
    return $weekStart->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

/**
 * Shared shape for both leaderboards below -- ranked {name, count} rows,
 * already sorted highest-first (ties broken alphabetically so the result
 * is stable) and capped at the top 3.
 */
function relationships_leaderboard_rows(PDO $pdo, string $sql, array $params): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $rows[] = ['name' => (string) $r['name'], 'count' => (int) $r['n']];
    }
    return $rows;
}

if ($action === 'overview') {
    // Rep-based territory filtering (see territory-access.php) -- applied
    // both to the customer list below AND to the portfolio-wide billing
    // gauge's SUM() further down, so a restricted rep's "Portfolio Billing
    // Trend" reflects only their own book, not every customer's.
    $territoryFilter = $allowedTerritories === null
        ? ['sql' => '', 'params' => []]
        : relationships_territory_filter_sql($allowedTerritories, 'customers');

    // No LIMIT here -- fixed 2026-09-26. This used to read "...ORDER BY
    // name ASC LIMIT 500", harmless back when the whole `customers` table
    // was real (agreement-backed) customers only. Once the Prospect
    // Companies sync (connectwise-prospect-sync-core.php) started
    // upserting every active/delinquent/special-info non-Vendor ConnectWise
    // company as its own customer row -- by design, so every pillar/service
    // can be marketed to them -- the real row count passed 500 and this
    // query silently started truncating to the first 500 customers
    // alphabetically. Every gauge below (Total Customers, Total Prospects,
    // Active Contacts, 60+ Days outgrow-stale) is derived by looping over
    // $customerRows in PHP, so all four silently went wrong together the
    // moment that happened -- Michael saw Total Customers drop to 80 and
    // a much lower PeopleFirst-among-real-customers count right after a
    // "Run Sync Now" that had, correctly, just synced ~1,764 prospect
    // companies for the first time. Nothing here calls ConnectWise live
    // (see file header) -- it's a pure local-SQLite read plus a PHP loop,
    // so there's no per-request network cost to removing the cap; the
    // per-customer trend lookups later in this function (billing/ticket/
    // contact) are each one indexed-by-customer_id local query too. If the
    // full customer+prospect set ever grows large enough that this page
    // becomes slow to load, the right fix is separate COUNT()/SUM()
    // queries for the gauges (so they're correct regardless of list size)
    // rather than reintroducing a LIMIT that silently drops real data.
    $customerStmt = $pdo->prepare(
        "SELECT id, name, is_peoplefirst, is_prospect_only, is_residential, cw_status_name, ticket_count_ytd, active_contact_count
         FROM customers WHERE 1=1 {$territoryFilter['sql']} ORDER BY name ASC"
    );
    $customerStmt->execute($territoryFilter['params']);
    $customerRows = $customerStmt->fetchAll(PDO::FETCH_ASSOC);

    // "60+ Days Since Last OutGrow Touch" metric -- added 2026-09-23, per
    // Michael. A customer's CURRENT Last Touch is the newest row in
    // outgrow_last_touch_history (same rule outgrow.php's 'get' uses --
    // newest by id, not by date). Counts real customers only (prospects
    // have their own tile/workflow): "stale" = no touch date at all, or
    // one 60+ days before today (America/New_York, like every other
    // rep-facing "today" in this app).
    $outgrowLatest = [];
    foreach ($pdo->query(
        'SELECT h.customer_id, h.touch_date, h.set_by_name
         FROM outgrow_last_touch_history h
         JOIN (SELECT customer_id, MAX(id) AS mid FROM outgrow_last_touch_history GROUP BY customer_id) m ON m.mid = h.id'
    )->fetchAll(PDO::FETCH_ASSOC) as $o) {
        $outgrowLatest[(int) $o['customer_id']] = $o;
    }
    $easternTz = new DateTimeZone('America/New_York');
    $todayEastern = new DateTimeImmutable('today', $easternTz);
    $outgrowStaleCount = 0;

    $customers = [];
    $totalContacts = 0;
    $totalProspects = 0;
    $totalResidential = 0;
    $totalCustomers = 0;
    foreach ($customerRows as $r) {
        $customerId = (int) $r['id'];
        $isProspect = (bool) $r['is_prospect_only'];
        $isResidential = (bool) $r['is_residential'];
        if ($isProspect) {
            $totalProspects++;
        }
        if ($isResidential) {
            $totalResidential++;
        }

        // Duplicate-without-contacts filter -- added 2026-09-26 per Michael:
        // "I want to filter out any duplicated customers. To filter out
        // those duplicates, only take the customers that have one or more
        // contacts. Any duplicates without contacts should be ignored."
        // Scoped to the Total Customers bucket only, via the same
        // !$isProspect && !$isResidential guard the outgrow-stale check
        // below already uses -- a real Prospect or Residential company can
        // legitimately have zero synced contacts (it may never have had a
        // ConnectWise contact recorded) without being a duplicate, so this
        // never touches those two buckets. `continue` here skips the row
        // entirely: not counted in Total Customers, not counted toward
        // Active Contacts (nothing to count), not included in the customer
        // list returned to the frontend, and not counted toward the
        // 60+-days outgrow-stale gauge below either. customers.php's own
        // search/detail lookup is deliberately untouched by this -- a rep
        // who already knows the company's name can still find and open it
        // there; this filter only changes what shows up on the front-page
        // dashboard.
        $contactCount = $r['active_contact_count'] !== null ? (int) $r['active_contact_count'] : 0;
        if (!$isProspect && !$isResidential && $contactCount === 0) {
            continue;
        }
        if (!$isProspect && !$isResidential) {
            $totalCustomers++;
        }

        $lastTouch = $outgrowLatest[$customerId]['touch_date'] ?? null;
        $outgrowDaysSince = null;
        if ($lastTouch !== null) {
            $touchDay = DateTimeImmutable::createFromFormat('!Y-m-d', $lastTouch, $easternTz);
            if ($touchDay !== false) {
                $diff = $touchDay->diff($todayEastern);
                $outgrowDaysSince = $diff->invert ? -((int) $diff->days) : (int) $diff->days;
            } else {
                $lastTouch = null; // unparseable stored value -- treat as no published touch
            }
        }
        if (!$isProspect && !$isResidential && ($outgrowDaysSince === null || $outgrowDaysSince >= 60)) {
            $outgrowStaleCount++;
        }
        // ticket_count_ytd stays per-customer (feeds the customer list's
        // "Tickets YTD" column and each customer's own dashboard) -- only
        // the portfolio-wide "Service Tickets YTD" gauge below was removed
        // 2026-09-16 per Michael: it wasn't showing a trustworthy number at
        // the aggregate level, and he only needs this data per-customer.
        $ticketYtd = $r['ticket_count_ytd'] !== null ? (int) $r['ticket_count_ytd'] : 0;
        $totalContacts += $contactCount;

        $billingTrend = relationships_cw_billing_stored_series($pdo, $customerId)['trend'];
        $ticketTrend = relationships_cw_ticket_history_trend($pdo, $customerId);
        $contactTrend = relationships_cw_contacts_trend($pdo, $customerId);

        $customers[] = [
            'id' => $customerId,
            'name' => $r['name'],
            'is_peoplefirst' => (bool) $r['is_peoplefirst'],
            'is_prospect_only' => $isProspect,
            'is_residential' => $isResidential,
            'cw_status_name' => $r['cw_status_name'],
            'last_outgrow_touch' => $lastTouch,
            'last_outgrow_touch_by' => $lastTouch !== null ? ($outgrowLatest[$customerId]['set_by_name'] ?? null) : null,
            'outgrow_days_since' => $outgrowDaysSince,
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
    $portfolioFilter = $allowedTerritories === null
        ? ['sql' => '', 'params' => []]
        : relationships_territory_filter_sql($allowedTerritories, 'customers');
    $portfolioStmt = $pdo->prepare(
        "SELECT customer_monthly_billing.month, SUM(customer_monthly_billing.total) AS total
         FROM customer_monthly_billing
         JOIN customers ON customers.id = customer_monthly_billing.customer_id
         WHERE 1=1 {$portfolioFilter['sql']}
         GROUP BY customer_monthly_billing.month"
    );
    $portfolioStmt->execute($portfolioFilter['params']);
    $portfolioByMonth = $portfolioStmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $portfolioBillingTrend = relationships_cw_activity_billing_series_from_totals($portfolioByMonth, 6)['trend'];

    // Prospects are their own group (Total Prospects tile), not part of
    // Total Customers -- 2026-09-23, per Michael, to set up a separate
    // prospect workflow. Residential is the same idea, added 2026-09-26,
    // per Michael: "I want to add another block for Residential
    // customers" -- companies whose live ConnectWise Company status is
    // literally "Residential" (see connectwise-prospect-sync-core.php's
    // relationships_cw_classify_company_bucket() -- Residential wins over
    // an existing agreement too, per Michael's own answer when asked).
    //
    // $totalCustomers itself is no longer this subtraction -- as of the
    // 2026-09-26 duplicate-without-contacts filter above, a skipped
    // (duplicate) row is present in $customerRows but counted in NONE of
    // totalCustomers/totalProspects/totalResidential, so
    // count($customerRows) - totalProspects - totalResidential would now
    // overcount Total Customers by however many duplicates were filtered.
    // It's a direct counter incremented in the loop above instead.

    $gauges = [
        ['key' => 'total_customers', 'label' => 'Total Customers', 'value' => $totalCustomers, 'format' => 'count'],
        ['key' => 'total_prospects', 'label' => 'Total Prospects', 'value' => $totalProspects, 'format' => 'count'],
        ['key' => 'total_residential', 'label' => 'Total Residential', 'value' => $totalResidential, 'format' => 'count'],
        ['key' => 'portfolio_billing_trend', 'label' => 'Portfolio Billing Trend', 'value' => null, 'format' => 'trend', 'trend' => $portfolioBillingTrend],
        ['key' => 'active_contacts', 'label' => 'Active Contacts', 'value' => $totalContacts, 'format' => 'count'],
        ['key' => 'outgrow_stale', 'label' => '60+ Days Since Last OutGrow Touch', 'value' => $outgrowStaleCount, 'format' => 'count'],
    ];

    // Whether the bulk ConnectWise backfill of existing OutGrow dates (see
    // outgrow.php's 'backfill_all') is due -- never run, or last attempted
    // 12+ hours ago. Without it, customers whose date only exists in
    // ConnectWise (backfilled lazily, one customer at a time, when their
    // dashboard is opened) would wrongly count as "no published touch".
    $backfillAt = $pdo->query("SELECT value FROM cw_sync_meta WHERE key = 'outgrow_backfill_at'")->fetchColumn();
    $backfillStale = $backfillAt === false || $backfillAt === null
        || (time() - (int) strtotime((string) $backfillAt . ' UTC')) > 12 * 3600;

    // Weekly rep leaderboards -- added 2026-09-23, per Michael. Each is
    // territory-scoped the same way everything else on this page is (a
    // restricted rep's leaderboard reflects only activity on customers in
    // their own territories), via a join back to customers rather than a
    // direct territory_name column on meeting_tasks/customer_meetings.
    $weekStartUtc = relationships_leaderboard_week_start_utc();

    // "Top 3 reps based on closed tasks for the week" -- credited to
    // whoever actually checked the task off (completed_by_name), not
    // necessarily who it was assigned to; completed_at is set once, on
    // completion, by meetings.php's set_task_done action.
    $closedTasksFilter = $allowedTerritories === null
        ? ['sql' => '', 'params' => []]
        : relationships_territory_filter_sql($allowedTerritories, 'c');
    $closedTasksLeaders = relationships_leaderboard_rows(
        $pdo,
        "SELECT mt.completed_by_name AS name, COUNT(*) AS n
         FROM meeting_tasks mt
         JOIN customers c ON c.id = mt.customer_id
         WHERE mt.completed_at IS NOT NULL AND mt.completed_at >= :weekStart
               AND mt.completed_by_name IS NOT NULL AND mt.completed_by_name != ''
               {$closedTasksFilter['sql']}
         GROUP BY mt.completed_by_name
         ORDER BY n DESC, mt.completed_by_name COLLATE NOCASE ASC
         LIMIT 3",
        array_merge([':weekStart' => $weekStartUtc], $closedTasksFilter['params'])
    );

    // "Top 3 reps based on number of meetings created" -- credited to
    // whoever logged the meeting (logged_by_name), counted by created_at
    // (when the meeting was LOGGED in this app, not the meeting's own
    // meeting_date) so a meeting entered late still counts toward the
    // week it was actually logged in, matching "meetings created."
    $meetingsFilter = $allowedTerritories === null
        ? ['sql' => '', 'params' => []]
        : relationships_territory_filter_sql($allowedTerritories, 'c');
    $meetingsLeaders = relationships_leaderboard_rows(
        $pdo,
        "SELECT cm.logged_by_name AS name, COUNT(*) AS n
         FROM customer_meetings cm
         JOIN customers c ON c.id = cm.customer_id
         WHERE cm.created_at >= :weekStart
               AND cm.logged_by_name IS NOT NULL AND cm.logged_by_name != ''
               {$meetingsFilter['sql']}
         GROUP BY cm.logged_by_name
         ORDER BY n DESC, cm.logged_by_name COLLATE NOCASE ASC
         LIMIT 3",
        array_merge([':weekStart' => $weekStartUtc], $meetingsFilter['params'])
    );

    $leaderboards = [
        ['key' => 'weekly_closed_tasks', 'label' => 'Closed Tasks This Week', 'entries' => $closedTasksLeaders],
        ['key' => 'weekly_meetings_logged', 'label' => 'Meetings Logged This Week', 'entries' => $meetingsLeaders],
    ];

    relationships_respond(200, [
        'ok' => true,
        'gauges' => $gauges,
        'leaderboards' => $leaderboards,
        'customers' => $customers,
        'outgrow_backfill_stale' => $backfillStale,
    ]);
}

relationships_respond(400, ['ok' => false, 'error' => 'Unknown action.']);
