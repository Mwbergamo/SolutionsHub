<?php
/**
 * myday/api/digest.php
 *
 * Backend for "My Day" -- a personal daily dashboard added 2026-11-12
 * per Michael's request for a feature "personalized to the person
 * logged into the portal... their daily dashboard of tasks, to-do's,
 * projects and their statuses, finance in their territory, instability
 * within their territories... a 10,000 foot view of their work here at
 * CodeBlue... trends, must-do's for that day, and be able to click into
 * each metric to take them to the item that needs attention."
 *
 * Deliberately NOT a new self-contained app with its own database --
 * every number here already exists somewhere else in SolutionsHub
 * (Relationships' to-dos/Projects/Account Opportunity ranking, Client
 * Finance's revenue gauges). This file is purely a cross-app READ-ONLY
 * aggregator: it requires relationships/api/*.php and
 * client-finance/api/*.php directly and calls their existing functions
 * against their existing SQLite files -- the same cross-app-PDO pattern
 * client-finance/api/gauges.php already established for company-name
 * lookups (see that file's client_finance_company_names(), which opens
 * relationships.sqlite directly). Nothing here re-implements a scoring
 * formula, a trend calculation, or a territory filter a second time --
 * every one of those is called from the one place it already lives, so
 * this view can never drift from what the Overview list, the Projects
 * screen, or the Client Finance gauges themselves show.
 *
 * Identity comes from the SAME shared Microsoft 365 session every app in
 * SolutionsHub already uses (auth/session.php, two levels up) -- signing
 * in once at the root is enough; this never runs its own login. A rep
 * who has signed in but never personally opened Relationships before
 * still gets a dashboard (falls back to the shared session's own name
 * rather than requiring a relationships crc_users row to exist first).
 *
 * GET ?action=digest
 *   -> { ok: true,
 *        user: { name, email },
 *        restricted: bool,
 *        todos: { overdue, due_today, future },
 *        projects: { total, red, yellow, green },
 *        territory_risk: { danger, stable, opportunity, total, territories: [name, ...] },
 *        finance_access: bool,
 *        finance: { recurring_revenue: {month, total, customers_with_revenue},
 *                   annual_revenue: {year, total, invoice_count},
 *                   sales_order_revenue: {year, total, order_count} } | null }
 *
 * `finance_access` / `finance` -- added 2026-11-12: Client Finance's own
 * numbers are restricted to the same 4-person allow-list everywhere else
 * in SolutionsHub (client-finance/api/_util.php's
 * client_finance_require_access(), auth/client-finance-access.php) --
 * territory-scoping these gauges for My Day does not relax that, so a
 * rep outside that list gets `finance: null` and the frontend simply
 * leaves the Territory Finance card off their dashboard, same as the
 * Finance hub tile itself only renders for these people.
 *
 * `restricted` is false for a rep with no assigned territory (e.g.
 * Michael, or any other unrestricted CRC) -- territory_risk and finance
 * then reflect the WHOLE portfolio, the same "unrestricted = sees
 * everything" rule relationships/api/territory-access.php already uses
 * everywhere else, rather than showing an empty dashboard for anyone not
 * explicitly assigned a territory. todos/projects are always scoped to
 * the signed-in rep by name regardless of territory restriction -- a
 * to-do or project assignment isn't a territory concept.
 *
 * territory_risk/finance deliberately exclude Prospect and Residential
 * rows -- "instability within their territories" and "finance in their
 * territory" read as being about their real, billable book, not
 * not-yet-converted leads or residential accounts, which don't carry
 * Agreement/invoice revenue the same way. This differs from the raw
 * Overview list (which computes an opportunity score for every row
 * regardless of bucket) -- flagged in the project doc as a judgment
 * call, not something Michael specified either way.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../auth/session.php';
require_once __DIR__ . '/../../relationships/api/_util.php';
require_once __DIR__ . '/../../relationships/api/territory-access.php';
require_once __DIR__ . '/../../relationships/api/connectwise-activity.php';
require_once __DIR__ . '/../../relationships/api/connectwise-billing-sync-core.php';
require_once __DIR__ . '/../../relationships/api/connectwise-ticket-history-sync-core.php';
require_once __DIR__ . '/../../relationships/api/connectwise-contacts-sync-core.php';
require_once __DIR__ . '/../../relationships/api/opportunity-scoring.php';
require_once __DIR__ . '/../../client-finance/api/db.php';
require_once __DIR__ . '/../../client-finance/api/gauges-core.php';
require_once __DIR__ . '/../../auth/client-finance-access.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

auth_start_session();
$sessionUser = auth_current_user();
if ($sessionUser === null) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not signed in.']);
    exit;
}

$action = $_GET['action'] ?? '';
if ($action !== 'digest') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Unknown action.']);
    exit;
}

/** One COUNT(*) query, bound params, returned as int. */
function myday_count(PDO $pdo, string $sql, array $params): int
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

try {
    $rpdo = relationships_db();
    // relationships_current_user() needs a crc_user_id in the shared
    // session, which only exists once this rep has signed into
    // Relationships at least once (auth/callback.php provisions it on
    // first visit, same as every other app's own user table) -- fall
    // back to the shared session's own name directly so a rep who has
    // never opened Relationships still gets a dashboard instead of a
    // hard failure.
    $rUser = relationships_current_user($rpdo);
    $myName = $rUser['name'] ?? (string) ($sessionUser['name'] ?? '');

    $allowedTerritories = relationships_allowed_territories($rpdo); // null = unrestricted
    $restricted = $allowedTerritories !== null;

    // ---------------------------------------------------------------
    // Today's Must-Dos -- my own open to-dos, same overdue/due-today/
    // future bucket rule as the front-page gauge (meetings.php's
    // todo_overview action), just counted for me instead of for the
    // whole company. An undated to-do is "future" (same rule as
    // everywhere else this app buckets to-dos).
    // ---------------------------------------------------------------
    $today = (new DateTimeImmutable('today', new DateTimeZone('America/New_York')))->format('Y-m-d');
    $bucketClauses = [
        'overdue' => 'due_date IS NOT NULL AND due_date < :today',
        'due_today' => 'due_date = :today',
        'future' => '(due_date IS NULL OR due_date > :today)',
    ];
    $todos = ['overdue' => 0, 'due_today' => 0, 'future' => 0];
    foreach ($bucketClauses as $bucket => $clause) {
        $params = [':today' => $today, ':me' => $myName];
        $general = myday_count(
            $rpdo,
            "SELECT COUNT(*) FROM general_todos WHERE completed_at IS NULL AND removed_at IS NULL AND assigned_to_name = :me AND {$clause}",
            $params
        );
        $meeting = myday_count(
            $rpdo,
            "SELECT COUNT(*) FROM meeting_tasks WHERE completed_at IS NULL AND assigned_to_name = :me AND {$clause}",
            $params
        );
        $todos[$bucket] = $general + $meeting;
    }

    // ---------------------------------------------------------------
    // My Projects -- counts only (names/status are one click away on
    // the real Projects screen, which fetches those live from
    // ConnectWise). Red/yellow/green glow tiers mirror the exact
    // thresholds already shipped on a rep's own to-do screen (0 steps
    // done = red, 1-3 = yellow, 4-5 = green) -- see
    // relationships-projects-module-plan.md's fifth follow-up.
    // ---------------------------------------------------------------
    $projAssignStmt = $rpdo->prepare('SELECT cw_project_id FROM project_assignments WHERE assigned_to_name = :me');
    $projAssignStmt->execute([':me' => $myName]);
    $myProjectIds = array_map('intval', array_column($projAssignStmt->fetchAll(PDO::FETCH_ASSOC), 'cw_project_id'));

    $projects = ['total' => count($myProjectIds), 'red' => 0, 'yellow' => 0, 'green' => 0];
    if ($myProjectIds !== []) {
        $placeholders = implode(',', array_fill(0, count($myProjectIds), '?'));
        $stepStmt = $rpdo->prepare(
            "SELECT cw_project_id, COUNT(*) AS n FROM project_checklist_progress WHERE cw_project_id IN ({$placeholders}) GROUP BY cw_project_id"
        );
        $stepStmt->execute($myProjectIds);
        $stepsDone = [];
        foreach ($stepStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $stepsDone[(int) $row['cw_project_id']] = (int) $row['n'];
        }
        foreach ($myProjectIds as $pid) {
            $n = $stepsDone[$pid] ?? 0;
            if ($n <= 0) {
                $projects['red']++;
            } elseif ($n <= 3) {
                $projects['yellow']++;
            } else {
                $projects['green']++;
            }
        }
    }

    // ---------------------------------------------------------------
    // My Territory Health + the company-id list My Territory Finance
    // needs below -- one pass over this rep's real (non-prospect,
    // non-residential) customers, scoring each with the exact same
    // relationships_account_opportunity_score() the Overview list and
    // a customer's own profile report use (opportunity-scoring.php),
    // fed by the exact same trend helpers dashboard.php's 'overview'
    // action calls. Unrestricted rep (allowedTerritories === null) ->
    // every real customer, same as the Overview list shows them.
    // ---------------------------------------------------------------
    $territoryFilter = $allowedTerritories === null
        ? ['sql' => '', 'params' => []]
        : relationships_territory_filter_sql($allowedTerritories, 'customers');

    $custStmt = $rpdo->prepare(
        "SELECT id, connectwise_id, territory_name, cx_issue_ticket_count_90d
         FROM customers
         WHERE COALESCE(cw_bucket, '') != 'excluded' AND COALESCE(is_duplicate_hidden, 0) = 0
           AND COALESCE(is_prospect_only, 0) = 0 AND COALESCE(is_residential, 0) = 0
           {$territoryFilter['sql']}"
    );
    $custStmt->execute($territoryFilter['params']);
    $custRows = $custStmt->fetchAll(PDO::FETCH_ASSOC);

    $territoryRisk = ['danger' => 0, 'stable' => 0, 'opportunity' => 0, 'total' => 0, 'territories' => []];
    $myCompanyIds = [];
    $seenTerritories = [];
    foreach ($custRows as $r) {
        $customerId = (int) $r['id'];
        $cwId = (string) ($r['connectwise_id'] ?? '');
        if ($cwId !== '' && ctype_digit($cwId)) {
            $myCompanyIds[] = (int) $cwId;
        }
        if (!empty($r['territory_name'])) {
            $seenTerritories[(string) $r['territory_name']] = true;
        }

        $billingTrend = relationships_cw_billing_stored_series($rpdo, $customerId)['trend'];
        $ticketTrend = relationships_cw_ticket_history_trend($rpdo, $customerId);
        $contactTrend = relationships_cw_contacts_trend($rpdo, $customerId);
        $cxIssueCount = (int) ($r['cx_issue_ticket_count_90d'] ?? 0);
        $opportunity = relationships_account_opportunity_score($billingTrend, $ticketTrend, $contactTrend, $cxIssueCount);

        $territoryRisk['total']++;
        if ($opportunity['label'] === 'Account in danger') {
            $territoryRisk['danger']++;
        } elseif ($opportunity['label'] === 'Likely to need services') {
            $territoryRisk['opportunity']++;
        } else {
            $territoryRisk['stable']++;
        }
    }
    $territoryRisk['territories'] = array_values(array_keys($seenTerritories));

    // ---------------------------------------------------------------
    // My Territory Finance -- the exact same Recurring/Annual/Sales
    // Order Revenue definitions as Client Finance's own gauges
    // (gauges-core.php, extracted 2026-11-12 from gauges.php so both
    // callers share one formula), scoped to this rep's territory via
    // the ConnectWise company ids collected above. Unrestricted rep ->
    // null (no filter), same whole-portfolio numbers Client Finance's
    // own gauges show. Gated behind the same allow-list Client Finance
    // itself enforces -- see the doc comment above.
    // ---------------------------------------------------------------
    $financeAllowed = client_finance_email_allowed($sessionUser['email'] ?? null);
    $finance = null;
    if ($financeAllowed) {
        $cpdo = client_finance_db();
        $financeCompanyIds = $restricted ? $myCompanyIds : null;
        $finance = [
            'recurring_revenue' => client_finance_recurring_revenue_core($cpdo, $financeCompanyIds),
            'annual_revenue' => client_finance_annual_revenue_core($cpdo, $financeCompanyIds),
            'sales_order_revenue' => client_finance_sales_order_revenue_core($cpdo, $financeCompanyIds),
        ];
    }

    echo json_encode([
        'ok' => true,
        'user' => ['name' => $myName, 'email' => (string) ($sessionUser['email'] ?? '')],
        'restricted' => $restricted,
        'todos' => $todos,
        'projects' => $projects,
        'territory_risk' => $territoryRisk,
        'finance_access' => $financeAllowed,
        'finance' => $finance,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
