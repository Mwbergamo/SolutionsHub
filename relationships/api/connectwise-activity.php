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
 * IMPORTANT / not fully live-verified: every other file in this ConnectWise
 * integration (connectwise.php, connectwise-classify.php) was built by
 * inspecting real sampled data from CodeBlue's live instance before
 * writing the classification rules. This file could NOT be verified that
 * way when it was first written -- the live instance
 * (connect.codebluetechnology.com) wasn't reachable from the build
 * environment. Errors are surfaced to the UI verbatim (never silently
 * swallowed into a wrong number) specifically so a field-name mismatch is
 * obvious and fixable rather than quietly showing bad figures.
 *
 * 2026-09-10: exactly that happened. The Monthly Billing panel's first
 * real-data use returned a real ConnectWise 400 -- {"code":"ApiFindCondition",
 * "message":"invoiceDate is not a recognized name."} -- against
 * /finance/invoices. Confirmed against ConnectWise's own documented Invoice
 * field list: the invoice date field is `date`, not `invoiceDate`. Fixed
 * throughout this file (conditions + fields + result field reads).
 *
 * While fixing that, a second and more serious latent problem in the same
 * code path was caught: this file originally assumed each invoice carries
 * a nested `agreement: {id, name}` object and used the invoice's own `type`
 * field (its *document* type, e.g. "Standard"/"Progress"/"Down Payment") to
 * decide whether it's an Agreement invoice. Neither matches ConnectWise's
 * documented Invoice fields. ConnectWise instead represents "what this
 * invoice was generated against" via `applyToType`/`applyToId` (the API
 * field behind the "Apply To" concept in the Finance > Invoice Search UI --
 * Agreement, Project, Sales Order, etc.). Unlike the `invoiceDate` mistake,
 * this one would NOT have thrown an error -- an unrecognized name in the
 * `fields` selector is silently dropped by ConnectWise rather than
 * rejected, so `agreement` would have just come back empty and every
 * invoice would have silently failed the "is this an Agreement invoice"
 * filter, showing a real, error-free, but WRONG $0 for every month. Fixed
 * to use `applyToType`/`applyToId` throughout, with the agreement's name
 * resolved via the same batched /finance/agreements lookup already used
 * for agreement type (that lookup already requests `name`).
 *
 * `applyToType`/`applyToId` is corroborated by ConnectWise's own "Apply To"
 * field on the invoice (agreement/project/other), and by a documented
 * third-party field enumeration of the Invoice object -- but, like the
 * ticket fields (board/name, owner, actualHours) still used unchanged
 * below, it has NOT been confirmed against a real invoice payload from
 * CBT's own instance. Treat the Monthly Billing panel's next real use
 * (after this fix deploys) as that confirmation: if agreement names/types
 * come back sensible instead of "Unknown", applyToType/applyToId is
 * correct; if invoice totals still look off, that's the next thing to
 * check.
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
    $conditions = "company/id=$cwCompanyId and date>=[" . $start->format('Y-m-d') . "T00:00:00Z]";
    if ($end !== null) {
        $conditions .= " and date<[" . $end->format('Y-m-d') . "T00:00:00Z]";
    }
    $rows = relationships_cw_list(
        '/finance/invoices',
        $conditions,
        ['id', 'invoiceNumber', 'date', 'total', 'type', 'applyToType', 'applyToId'],
        200
    );

    // Only invoices generated FROM an agreement -- the user's own framing
    // ("dollars billed on Agreement invoices"), excluding one-off Time/
    // Product/Project/Sales-Order invoices. `applyToType` is ConnectWise's
    // field for what the invoice was generated against (see file header --
    // NOT the invoice's own `type`, which is its document type and
    // unrelated to this).
    $agreementInvoices = [];
    foreach ($rows as $r) {
        $applyToType = (string) ($r['applyToType'] ?? '');
        if (stripos($applyToType, 'agreement') === false) {
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
        $date = (string) ($inv['date'] ?? '');
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
        static fn (array $inv): ?int => isset($inv['applyToId']) ? (int) $inv['applyToId'] : null,
        $invoices
    ))));

    $agreementTypes = []; // agreementId => type name
    $agreementNames = []; // agreementId => name
    if ($agreementIds !== []) {
        $idList = implode(',', $agreementIds);
        $rows = relationships_cw_list('/finance/agreements', "id in ($idList)", ['id', 'name', 'type'], 200);
        foreach ($rows as $a) {
            $typeName = is_array($a['type'] ?? null) ? ($a['type']['name'] ?? 'Unknown') : (string) ($a['type'] ?? 'Unknown');
            $agreementTypes[(int) $a['id']] = $typeName;
            $agreementNames[(int) $a['id']] = (string) ($a['name'] ?? '');
        }
    }

    $result = array_map(static function (array $inv) use ($agreementTypes, $agreementNames): array {
        $agreementId = isset($inv['applyToId']) ? (int) $inv['applyToId'] : null;
        return [
            'id' => (int) ($inv['id'] ?? 0),
            'invoice_number' => $inv['invoiceNumber'] ?? (string) ($inv['id'] ?? ''),
            'date' => $inv['date'] ?? null,
            'type' => is_array($inv['type'] ?? null) ? ($inv['type']['name'] ?? '') : (string) ($inv['type'] ?? ''),
            'agreement_id' => $agreementId,
            'agreement_name' => $agreementId !== null ? ($agreementNames[$agreementId] ?? null) : null,
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

    // See file header: invoices reference what they were generated against
    // via applyToType/applyToId, not a nested `agreement` object.
    $appliesToAgreement = stripos((string) ($invoice['applyToType'] ?? ''), 'agreement') !== false;
    $agreementId = ($appliesToAgreement && isset($invoice['applyToId'])) ? (int) $invoice['applyToId'] : null;
    $agreementName = null;
    $agreementType = null;
    $hoursRemaining = null;
    $rawHourFields = [];

    if ($agreementId !== null) {
        $agreement = relationships_cw_request("/finance/agreements/$agreementId");
        $agreementName = $agreement['name'] ?? null;
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
        'date' => $invoice['date'] ?? null,
        'total' => round((float) ($invoice['total'] ?? 0), 2),
        'agreement_name' => $agreementName,
        'agreement_type' => $agreementType,
        'line_items' => $lineItems,
        'hours_remaining' => $hoursRemaining,
        'raw_hour_fields' => $rawHourFields, // shown in the UI only when hours_remaining is null, to help diagnose the real field name
    ];
}
