<?php
/**
 * relationships/api/connectwise-activity.php
 *
 * Live (uncached, on-demand) ConnectWise queries backing the "Service
 * Tickets" and "Monthly Billing" panels on a single customer's dashboard --
 * unlike connectwise-sync-core.php, nothing here is written to SQLite.
 * These numbers are only ever fetched for the ONE customer currently open
 * on someone's screen, so a couple of live API round-trips per dashboard
 * open is an acceptable trade for always-current figures, without a second
 * sync/staleness story to maintain.
 *
 * IMPORTANT / not yet live-verified: every other file in this ConnectWise
 * integration (connectwise.php, connectwise-classify.php) was built by
 * inspecting real sampled data from CodeBlue's live instance before
 * writing the classification rules. This file could NOT be verified that
 * way -- the live instance (connect.codebluetechnology.com) wasn't
 * reachable from this build environment when it was written. The ticket
 * fields (board/name, owner, actualHours) and invoice fields (type,
 * agreement) below are standard ConnectWise Manage REST API v3 fields per
 * their public schema, but haven't been checked against CBT's actual data
 * the way the agreement/addition sync was. Treat the first real use of
 * each action here as its real-world test -- errors are surfaced to the
 * UI verbatim (never silently swallowed into a wrong number) specifically
 * so a field-name mismatch is obvious and fixable rather than quietly
 * showing bad figures.
 */

declare(strict_types=1);

require_once __DIR__ . '/connectwise.php';

/**
 * Count of Professional Services board tickets opened this calendar year
 * for one company. Uses ConnectWise's /count endpoint (mirrors the list
 * endpoint's conditions but returns just {count: N}) rather than paging
 * through every ticket just to total them.
 */
function relationships_cw_activity_ticket_count_ytd(string $cwCompanyId): int
{
    $sinceIso = date('Y') . '-01-01T00:00:00Z';
    $conditions = "company/id=$cwCompanyId and board/name=\"Professional Services\" and dateEntered>=[$sinceIso]";
    $result = relationships_cw_request('/service/tickets/count', ['conditions' => $conditions]);
    return (int) ($result['count'] ?? 0);
}

/**
 * The actual YTD ticket list for the "click the total to see the list"
 * drill-down: date, ticket #, summary, assigned engineer, hours worked.
 * ConnectWise's own "actualHours" field on the ticket (aggregated from
 * that ticket's time entries) is used directly for hours worked, rather
 * than re-summing /time/entries ourselves.
 */
function relationships_cw_activity_tickets_ytd(string $cwCompanyId): array
{
    $sinceIso = date('Y') . '-01-01T00:00:00Z';
    $conditions = "company/id=$cwCompanyId and board/name=\"Professional Services\" and dateEntered>=[$sinceIso]";
    $rows = relationships_cw_list(
        '/service/tickets',
        $conditions,
        ['id', 'summary', 'dateEntered', 'actualHours', 'owner', 'status'],
        200
    );

    $tickets = array_map(static function (array $t): array {
        $owner = $t['owner'] ?? null;
        $engineer = null;
        if (is_array($owner)) {
            $engineer = $owner['name'] ?? $owner['identifier'] ?? null;
        }
        return [
            'id' => (int) ($t['id'] ?? 0),
            'ticket_number' => (int) ($t['id'] ?? 0), // ConnectWise's ticket "number" IS its id
            'date' => $t['dateEntered'] ?? null,
            'summary' => (string) ($t['summary'] ?? ''),
            'engineer' => $engineer ?? 'Unassigned',
            'hours' => isset($t['actualHours']) ? (float) $t['actualHours'] : 0.0,
            'status' => is_array($t['status'] ?? null) ? ($t['status']['name'] ?? null) : null,
        ];
    }, $rows);

    usort($tickets, static fn (array $a, array $b): int => strcmp((string) $b['date'], (string) $a['date']));
    return $tickets;
}

/**
 * Raw "Agreement"-type invoices for one company between $start (inclusive)
 * and $end (exclusive, defaults to open-ended/"through now"). Every other
 * function in this file that deals with invoices/billing builds on this
 * same fetch so the date-range and Agreement-type filtering only live in
 * one place.
 */
function relationships_cw_activity_agreement_invoices(string $cwCompanyId, DateTimeImmutable $start, ?DateTimeImmutable $end = null): array
{
    $conditions = "company/id=$cwCompanyId and invoiceDate>=[" . $start->format('Y-m-d') . "T00:00:00Z]";
    if ($end !== null) {
        $conditions .= " and invoiceDate<[" . $end->format('Y-m-d') . "T00:00:00Z]";
    }
    $rows = relationships_cw_list(
        '/finance/invoices',
        $conditions,
        ['id', 'invoiceNumber', 'invoiceDate', 'total', 'type', 'agreement'],
        200
    );

    // Only "Agreement" invoices -- the user's own framing ("dollars billed
    // on Agreement invoices"), excluding one-off Time/Product/Miscellaneous
    // invoices that aren't tied to a recurring agreement. `type` can come
    // back as a plain string or a {id, name} reference depending on the
    // fields requested -- handle both rather than assume one shape.
    $agreementInvoices = [];
    foreach ($rows as $r) {
        $typeName = is_array($r['type'] ?? null) ? ($r['type']['name'] ?? '') : (string) ($r['type'] ?? '');
        if (stripos($typeName, 'agreement') === false) {
            continue;
        }
        $agreementInvoices[] = $r;
    }
    return $agreementInvoices;
}

/**
 * 6 months (oldest -> newest) of { month: "YYYY-MM", label, total } for
 * the monthly billing bar/trend widget, plus an "on average, up or down
 * and by how much" trend: average of the most recent 3 months vs the
 * average of the 3 before that. This specific split (recent-3 vs prior-3,
 * rather than e.g. a linear regression or first-vs-last month) is this
 * build's interpretation of "going up or down on average" -- flagged to
 * Michael as an assumption, not something he specified exactly.
 */
function relationships_cw_activity_monthly_billing(string $cwCompanyId, int $months = 6): array
{
    $start = new DateTimeImmutable('first day of -' . ($months - 1) . ' months 00:00:00');
    $invoices = relationships_cw_activity_agreement_invoices($cwCompanyId, $start);

    $byMonth = [];
    foreach ($invoices as $inv) {
        $date = (string) ($inv['invoiceDate'] ?? '');
        if ($date === '') {
            continue;
        }
        $key = substr($date, 0, 7); // "YYYY-MM"
        $byMonth[$key] = ($byMonth[$key] ?? 0.0) + (float) ($inv['total'] ?? 0);
    }

    $series = [];
    for ($i = $months - 1; $i >= 0; $i--) {
        $d = new DateTimeImmutable("first day of -$i months");
        $key = $d->format('Y-m');
        $series[] = [
            'month' => $key,
            'label' => $d->format('M Y'),
            'total' => round($byMonth[$key] ?? 0.0, 2),
        ];
    }

    $recent = array_slice($series, -3);
    $prior = array_slice($series, -6, 3);
    $recentAvg = array_sum(array_column($recent, 'total')) / max(1, count($recent));
    $priorAvg = array_sum(array_column($prior, 'total')) / max(1, count($prior));

    if ($priorAvg <= 0.0) {
        $trend = ['direction' => $recentAvg > 0 ? 'up' : 'flat', 'percent' => null];
    } else {
        $percent = (($recentAvg - $priorAvg) / $priorAvg) * 100;
        $direction = abs($percent) < 1.0 ? 'flat' : ($percent > 0 ? 'up' : 'down');
        $trend = ['direction' => $direction, 'percent' => round(abs($percent), 1)];
    }

    return ['series' => $series, 'trend' => $trend];
}

/**
 * The invoice list for one specific "YYYY-MM" month (the "click on the
 * total dollars billed" drill-down): invoice #, date, type, and which
 * agreement it's against -- grouped by that agreement's type (IT
 * Services, Voice, etc.) so a CRC can see where the month's expense is
 * actually recognized, per Michael's request. One extra batched lookup
 * against /finance/agreements resolves each invoice's agreement type
 * (the invoice's own "agreement" field is just an {id, name} reference).
 */
function relationships_cw_activity_invoices_for_month(string $cwCompanyId, string $yearMonth): array
{
    $start = DateTimeImmutable::createFromFormat('Y-m-d', $yearMonth . '-01') ?: new DateTimeImmutable('first day of this month');
    $end = $start->modify('+1 month');
    $invoices = relationships_cw_activity_agreement_invoices($cwCompanyId, $start, $end);

    $agreementIds = array_values(array_unique(array_filter(array_map(
        static fn (array $inv): ?int => isset($inv['agreement']['id']) ? (int) $inv['agreement']['id'] : null,
        $invoices
    ))));

    $agreementTypes = []; // agreementId => type name
    if ($agreementIds !== []) {
        $idList = implode(',', $agreementIds);
        $rows = relationships_cw_list('/finance/agreements', "id in ($idList)", ['id', 'name', 'type'], 200);
        foreach ($rows as $a) {
            $typeName = is_array($a['type'] ?? null) ? ($a['type']['name'] ?? 'Unknown') : (string) ($a['type'] ?? 'Unknown');
            $agreementTypes[(int) $a['id']] = $typeName;
        }
    }

    $result = array_map(static function (array $inv) use ($agreementTypes): array {
        $agreementId = isset($inv['agreement']['id']) ? (int) $inv['agreement']['id'] : null;
        return [
            'id' => (int) ($inv['id'] ?? 0),
            'invoice_number' => $inv['invoiceNumber'] ?? (string) ($inv['id'] ?? ''),
            'date' => $inv['invoiceDate'] ?? null,
            'type' => is_array($inv['type'] ?? null) ? ($inv['type']['name'] ?? '') : (string) ($inv['type'] ?? ''),
            'agreement_id' => $agreementId,
            'agreement_name' => $inv['agreement']['name'] ?? null,
            'agreement_type' => $agreementId !== null ? ($agreementTypes[$agreementId] ?? 'Unknown') : 'Unknown',
            'total' => round((float) ($inv['total'] ?? 0), 2),
        ];
    }, $invoices);

    usort($result, static fn (array $a, array $b): int => strcmp((string) $a['date'], (string) $b['date']));
    return $result;
}

/**
 * Line-item detail for one invoice: the agreement additions it billed,
 * or -- for a Block Time / prepaid-hours agreement -- the hours-remaining
 * balance instead of a line-item list.
 *
 * NOT VERIFIED against real ConnectWise data (see file header). ConnectWise
 * doesn't expose one universal, stable field for "this invoice's line
 * items" the way it does for agreements/additions, and CBT's actual
 * instance/customizations may shape this differently. Deliberately
 * defensive: pulls the full, unfiltered invoice and agreement records
 * (no `fields` restriction) and looks for the likely keys under a few
 * plausible names/shapes rather than assuming one exact schema, and
 * reports back which raw keys it found so a mismatch is diagnosable
 * instead of silently blank.
 */
function relationships_cw_activity_invoice_detail(int $invoiceId): array
{
    $invoice = relationships_cw_request("/finance/invoices/$invoiceId");

    $lineItems = [];
    foreach (['invoiceLineItems', 'detailItems', 'lineItems', 'details'] as $key) {
        if (isset($invoice[$key]) && is_array($invoice[$key])) {
            foreach ($invoice[$key] as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $desc = $item['description'] ?? $item['invoiceDescription'] ?? $item['product']['description'] ?? null;
                $qty = $item['quantity'] ?? $item['qty'] ?? null;
                if ($desc !== null) {
                    $lineItems[] = ['description' => (string) $desc, 'qty' => $qty !== null ? (float) $qty : null];
                }
            }
            if ($lineItems !== []) {
                break;
            }
        }
    }

    $agreementId = isset($invoice['agreement']['id']) ? (int) $invoice['agreement']['id'] : null;
    $agreementName = $invoice['agreement']['name'] ?? null;
    $agreementType = null;
    $hoursRemaining = null;
    $rawHourFields = [];

    if ($agreementId !== null) {
        $agreement = relationships_cw_request("/finance/agreements/$agreementId");
        $agreementType = is_array($agreement['type'] ?? null) ? ($agreement['type']['name'] ?? null) : ($agreement['type'] ?? null);

        // Block Time / prepaid-hours agreements track a running hours
        // balance somewhere on the agreement record -- the exact field
        // name isn't confirmed, so surface every key that looks
        // hour-related rather than guess one and risk showing nothing
        // (or the wrong number) for a real block-time agreement.
        foreach ($agreement as $k => $v) {
            if (stripos((string) $k, 'hour') !== false && !is_array($v)) {
                $rawHourFields[$k] = $v;
            }
        }
        $hoursRemaining = $rawHourFields['hoursRemaining'] ?? $rawHourFields['availableHours'] ?? null;
    }

    return [
        'invoice_number' => $invoice['invoiceNumber'] ?? (string) $invoiceId,
        'date' => $invoice['invoiceDate'] ?? null,
        'total' => round((float) ($invoice['total'] ?? 0), 2),
        'agreement_name' => $agreementName,
        'agreement_type' => $agreementType,
        'line_items' => $lineItems,
        'hours_remaining' => $hoursRemaining,
        'raw_hour_fields' => $rawHourFields, // shown in the UI only when hours_remaining is null, to help diagnose the real field name
    ];
}
