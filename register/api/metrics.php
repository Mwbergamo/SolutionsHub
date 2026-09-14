<?php
/**
 * register/api/metrics.php
 *
 * Backs the register front screen's "Metrics" square button (added
 * 2026-09-14). Read-only aggregates over this app's own local sales/
 * sale_items tables -- no ConnectWise calls.
 *
 * Definitions (Michael's spec, plus the choices needed to make them
 * concrete -- flagged here rather than silently assumed):
 *   - "Today" / "This Week" are both TO-DATE (through right now), in
 *     America/New_York (Richmond, VA) local time, not full/completed
 *     calendar periods. A week runs Monday 00:00 through now.
 *   - "Number of Sales" -- count of completed sales (rows in `sales`) in
 *     the period.
 *   - "Number of Customers" -- count of DISTINCT ConnectWise Companies
 *     (cw_company_id) sold to in the period, not distinct Contacts (a
 *     Company is "the customer" for this purpose, matching how checkout's
 *     Company/Contact resolution and Sale History already group sales).
 *   - "Protection Plan Sales" -- count of individual sale_items rows
 *     flagged is_protection_plan=1 in the period (i.e. how many times a
 *     protection-plan product was rung up as a line item, not units/
 *     quantity-weighted). See db.php's sale_items migration comment for
 *     why this is a stored flag rather than a live join/lookup.
 *   - "Return Customers" -- among Companies that bought THIS WEEK, how
 *     many had never bought before this week (New) vs. had at least one
 *     earlier sale (Returning). Computed against this app's own sales
 *     history, which only goes back to whenever the register app itself
 *     went live -- a company's first-ever purchase from CBT predating that
 *     history would incorrectly count as "New" here. Flagged as a known
 *     limitation; there's no other CBT-wide purchase-history source this
 *     app can check against today.
 *
 * A sale with no cw_company_id (pre-2026-09-14 sales recorded before every
 * sale had to resolve to a real Company) is excluded from customer counts
 * and Return Customers, but still counted in Number of Sales.
 *
 * GET /register/api/metrics.php?action=summary
 *   -> { ok: true,
 *        today:  { sales_count, customers_count, protection_plan_sales },
 *        week:   { sales_count, customers_count, protection_plan_sales },
 *        return_customers_week: { new_customers, returning_customers },
 *        week_start: "YYYY-MM-DD" }   // for display, e.g. "Week of Sep 8"
 */

declare(strict_types=1);

require_once __DIR__ . '/_util.php';

register_install_error_handlers();

$pdo = register_db();
register_require_login($pdo);

$action = $_GET['action'] ?? '';

if ($action === 'summary') {
    $tz = new DateTimeZone('America/New_York');
    $utc = new DateTimeZone('UTC');
    $now = new DateTime('now', $tz);

    $todayStart = (clone $now)->setTime(0, 0, 0);
    $todayStartUtc = (clone $todayStart)->setTimezone($utc)->format('Y-m-d H:i:s');

    $weekStart = (clone $now)->setTime(0, 0, 0);
    $isoDow = (int) $weekStart->format('N'); // 1 (Mon) .. 7 (Sun)
    if ($isoDow > 1) {
        $weekStart->modify('-' . ($isoDow - 1) . ' days');
    }
    $weekStartUtc = (clone $weekStart)->setTimezone($utc)->format('Y-m-d H:i:s');

    register_respond(200, [
        'ok' => true,
        'today' => register_metrics_period($pdo, $todayStartUtc),
        'week' => register_metrics_period($pdo, $weekStartUtc),
        'return_customers_week' => register_metrics_return_customers($pdo, $weekStartUtc),
        'week_start' => $weekStart->format('Y-m-d'),
    ]);
}

register_respond(400, ['ok' => false, 'error' => 'Unknown action.']);

function register_metrics_period(PDO $pdo, string $startUtc): array
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) AS sales_count, COUNT(DISTINCT cw_company_id) AS customers_count
         FROM sales WHERE created_at >= :start'
    );
    $stmt->execute([':start' => $startUtc]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $ppStmt = $pdo->prepare(
        'SELECT COUNT(*) AS pp_count
         FROM sale_items si JOIN sales s ON s.id = si.sale_id
         WHERE si.is_protection_plan = 1 AND s.created_at >= :start'
    );
    $ppStmt->execute([':start' => $startUtc]);
    $pp = $ppStmt->fetch(PDO::FETCH_ASSOC);

    return [
        'sales_count' => (int) $row['sales_count'],
        'customers_count' => (int) $row['customers_count'],
        'protection_plan_sales' => (int) $pp['pp_count'],
    ];
}

function register_metrics_return_customers(PDO $pdo, string $weekStartUtc): array
{
    $stmt = $pdo->prepare(
        'SELECT DISTINCT cw_company_id FROM sales WHERE created_at >= :start AND cw_company_id IS NOT NULL'
    );
    $stmt->execute([':start' => $weekStartUtc]);
    $companyIds = array_map(static fn (array $r): int => (int) $r['cw_company_id'], $stmt->fetchAll(PDO::FETCH_ASSOC));

    $newCount = 0;
    $returningCount = 0;
    if ($companyIds !== []) {
        $priorStmt = $pdo->prepare(
            'SELECT 1 FROM sales WHERE cw_company_id = :cid AND created_at < :start LIMIT 1'
        );
        foreach ($companyIds as $cid) {
            $priorStmt->execute([':cid' => $cid, ':start' => $weekStartUtc]);
            if ($priorStmt->fetch() !== false) {
                $returningCount++;
            } else {
                $newCount++;
            }
        }
    }

    return ['new_customers' => $newCount, 'returning_customers' => $returningCount];
}
