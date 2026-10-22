<?php
/**
 * relationships/api/connectwise-ytd-billing-sync-core.php
 *
 * "Dollars Billed YTD" per customer -- added 2026-10-22 per Michael's "Top
 * 50" clients request: "Every year, we need to know who our top 50 clients
 * are by invoiced dollars." See claude/relationships-top-50-clients.md for
 * the full feature writeup and the open assumptions (which customers count
 * as "clients," how a Credit Memo nets out, etc.).
 *
 * Deliberately NOT the same shape as connectwise-billing-sync-core.php's
 * customer_monthly_billing/customer_yearly_billing sync just above it in
 * db.php: those two are scoped to Agreement invoices only (applyToType
 * contains "Agreement") because they feed a recurring-revenue trend chart.
 * "Dollars Billed YTD" is explicitly broader -- it has to count every
 * invoice type (one-off product sales, non-agreement service invoices,
 * everything) or the ranking would silently miss real revenue. So this is
 * its own sync, its own table (customer_ytd_billing), and its own single
 * global /finance/invoices pull rather than a reuse of the existing
 * per-customer batched queue.
 *
 * Also deliberately NOT a queue/start()/step() pair like every sibling
 * sync in this file's directory: a single /finance/invoices call, scoped
 * to "this calendar year," covers EVERY customer in one round-trip (no
 * per-company condition needed -- the response's own `company` field is
 * grouped in PHP after the fact), so there is no per-item work to
 * checkpoint or retry. relationships_cw_ytd_billing_sync() below runs to
 * completion in one call; connectwise-cron.php and sync.php's
 * ytd-billing-sync action both call it directly.
 *
 * Invoice classification (closed vs. void) mirrors commissions/api/calc.php's
 * commissions_status_is_closed()/commissions_status_is_void() -- the one
 * other place in this codebase that classifies ConnectWise invoice
 * statuses, confirmed live-tested there. "Closed" invoices only (an open/
 * draft invoice isn't final yet); void/cancelled invoices are dropped.
 * Credit Memos are NOT special-cased: ConnectWise records a Credit Memo's
 * `total` as negative, so summing every closed invoice's signed total
 * already nets a credit against the original invoice it offsets, same as
 * commissions' own invoice totals do -- there is no separate "type"
 * filter here, same as commissions/api/calc.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/connectwise.php';

/** @see commissions_status_is_closed() in commissions/api/calc.php -- identical logic, duplicated rather than shared across apps. */
function relationships_ytd_billing_status_is_closed(string $statusName): bool
{
    $s = strtolower(trim($statusName));
    return $s === 'closed' || $s === 'closed - emailed' || $s === 'closed-emailed';
}

/** @see commissions_status_is_void() in commissions/api/calc.php -- identical logic, duplicated rather than shared across apps. */
function relationships_ytd_billing_status_is_void(string $statusName): bool
{
    $s = strtolower($statusName);
    return str_contains($s, 'void') || str_contains($s, 'cancel');
}

/**
 * Pulls every invoice dated this calendar year (any applyToType), sums
 * closed/non-void totals by ConnectWise company id, matches that back to
 * this app's own `customers` rows, and rewrites customer_ytd_billing for
 * the current year from scratch.
 *
 * "Clients" for this ranking = the same base population as the front-page
 * Total Customers + Total Residential gauges (cw_bucket != 'excluded',
 * not a de-duped-away row) MINUS pure prospects (is_prospect_only) -- a
 * prospect has no real billing relationship, so a stray invoice found
 * against one is far more likely stale/reassigned ConnectWise data than a
 * real client to put on an envelope. See
 * claude/relationships-top-50-clients.md for this exact call and why it
 * was made without asking Michael first (a defensible default, easy to
 * correct if wrong).
 *
 * @return array{year:string, invoices_seen:int, invoices_counted:int, companies_billed:int, customers_matched:int}
 */
function relationships_cw_ytd_billing_sync(PDO $pdo): array
{
    $year = date('Y');
    $conditions = 'date>=[' . $year . '-01-01T00:00:00Z]';

    $rows = relationships_cw_list(
        '/finance/invoices',
        $conditions,
        ['id', 'date', 'total', 'status', 'company'],
        200
    );

    $totalsByCompany = []; // ConnectWise company id (string) => signed dollar total
    $invoicesCounted = 0;
    foreach ($rows as $r) {
        $statusVal = $r['status'] ?? null;
        $statusName = is_array($statusVal) ? (string) ($statusVal['name'] ?? '') : (string) $statusVal;
        if (relationships_ytd_billing_status_is_void($statusName)) {
            continue;
        }
        if (!relationships_ytd_billing_status_is_closed($statusName)) {
            continue; // still open/draft -- not final yet, don't count it
        }
        $companyVal = $r['company'] ?? null;
        $cwCompanyId = is_array($companyVal) ? (string) ($companyVal['id'] ?? '') : '';
        if ($cwCompanyId === '') {
            continue;
        }
        $totalsByCompany[$cwCompanyId] = ($totalsByCompany[$cwCompanyId] ?? 0.0) + (float) ($r['total'] ?? 0);
        $invoicesCounted++;
    }

    $matched = 0;
    $pdo->beginTransaction();
    try {
        $delete = $pdo->prepare('DELETE FROM customer_ytd_billing WHERE year = :year');
        $delete->execute([':year' => $year]);

        if ($totalsByCompany !== []) {
            $upsert = $pdo->prepare(
                "INSERT INTO customer_ytd_billing (customer_id, year, total, synced_at)
                 VALUES (:cid, :year, :total, datetime('now'))
                 ON CONFLICT(customer_id, year) DO UPDATE SET total = excluded.total, synced_at = excluded.synced_at"
            );

            // array_keys() can hand back PHP integers here (PHP
            // normalizes a purely-decimal string array key to an int), but
            // customers.connectwise_id is a TEXT column -- strval() keeps
            // every bound parameter a string rather than relying on
            // SQLite's column-affinity coercion to paper over the mismatch.
            $cwIds = array_map('strval', array_keys($totalsByCompany));
            $placeholders = implode(',', array_fill(0, count($cwIds), '?'));
            $match = $pdo->prepare(
                "SELECT id, connectwise_id FROM customers
                 WHERE connectwise_id IN ($placeholders)
                 AND COALESCE(cw_bucket, '') != 'excluded'
                 AND COALESCE(is_duplicate_hidden, 0) = 0
                 AND COALESCE(is_prospect_only, 0) = 0"
            );
            $match->execute($cwIds);
            foreach ($match->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $cwId = (string) $row['connectwise_id'];
                if (!isset($totalsByCompany[$cwId])) {
                    continue;
                }
                $upsert->execute([
                    ':cid' => (int) $row['id'],
                    ':year' => $year,
                    ':total' => $totalsByCompany[$cwId],
                ]);
                $matched++;
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return [
        'year' => $year,
        'invoices_seen' => count($rows),
        'invoices_counted' => $invoicesCounted,
        'companies_billed' => count($totalsByCompany),
        'customers_matched' => $matched,
    ];
}
