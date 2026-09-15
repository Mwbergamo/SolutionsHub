<?php
/**
 * relationships/api/activity.php
 *
 * Live ConnectWise "Service Tickets" + "Monthly Billing" panels for a
 * single customer's dashboard -- see connectwise-activity.php for the
 * actual queries and, importantly, its file-header note on which parts
 * of this are/aren't verified against CBT's real ConnectWise data yet.
 * Every action here is read-only and requires the customer to already
 * have a connectwise_id (i.e. be a real, synced customer -- mock
 * customers have nothing to look up and get a plain "not available").
 *
 * GET /relationships/api/activity.php?action=summary&customer_id=1
 *   -> { ok: true, ticket_count_ytd: int,
 *        billing: { mode: 'monthly'|'annual', series: [{month,label,total}] x6 OR [{year,label,total}] x3, trend: {direction, percent} },
 *        billing_synced_at: string|null }
 *
 * ticket_count_ytd is a live ConnectWise query on every call (see
 * connectwise-activity.php). billing is read from the nightly-synced
 * customer_monthly_billing/customer_yearly_billing tables instead
 * (connectwise-billing-sync-core.php) -- added 2026-09-10 per Michael, to
 * stop paying a ConnectWise round-trip on every single dashboard open for
 * a number that doesn't need to be second-by-second current.
 * billing_synced_at is null when this customer hasn't been covered by a
 * billing sync run yet (new customer, or the billing sync has never run)
 * -- the UI uses that to show "not yet synced" instead of a chart that
 * looks like a confirmed $0.
 *
 * `billing.mode` (added 2026-09-15, per Michael: "For accounts that are
 * only being billed annually, I want to see their last 3 years of
 * billings, just like the last 3 months for normal monthly customers.")
 * is 'annual' for a customer whose Agreement billing only shows up once
 * a year (see connectwise-billing-sync-core.php's cadence-detection
 * step) -- `series` then has 3 { year, label, total } entries instead of
 * 6 { month, label, total } ones. Every customer before 2026-09-15
 * defaults to 'monthly'.
 *
 * GET /relationships/api/activity.php?action=tickets&customer_id=1
 *   -> { ok: true, tickets: [{ id, ticket_number, date, summary, engineer, hours, status }, ...] }
 *
 * GET /relationships/api/activity.php?action=invoices&customer_id=1&month=2026-08
 *   -> { ok: true, month: "2026-08",
 *        invoices: [{ id, invoice_number, date, type, agreement_id, agreement_name, agreement_type, total }, ...] }
 *
 * GET /relationships/api/activity.php?action=invoices&customer_id=1&year=2026
 *   (added 2026-09-15 for the annual-cadence bars' drill-down -- mutually
 *   exclusive with `month` above, same response shape otherwise)
 *   -> { ok: true, year: "2026",
 *        invoices: [{ id, invoice_number, date, type, agreement_id, agreement_name, agreement_type, total }, ...] }
 *
 * GET /relationships/api/activity.php?action=invoice-detail&invoice_id=12345
 *   -> { ok: true, invoice: { invoice_number, date, total, agreement_name, agreement_type,
 *                               line_items: [{description, qty}, ...],
 *                               line_items_source: 'invoice'|'agreement_additions'|null,
 *                               hours_remaining, raw_hour_fields } }
 *
 * line_items_source is 'agreement_additions' whenever the invoice's own
 * ConnectWise record carried no line items (confirmed 2026-09-10: it
 * never does, for a normal Agreement invoice) -- those cases fall back to
 * this app's own synced customer_services for that agreement, which is
 * the agreement's CURRENT active additions, not a per-invoice historical
 * snapshot. See connectwise-activity.php's relationships_cw_activity_invoice_detail()
 * doc comment.
 */

declare(strict_types=1);

require_once __DIR__ . '/_util.php';
require_once __DIR__ . '/connectwise-activity.php';
require_once __DIR__ . '/connectwise-billing-sync-core.php';

$pdo = relationships_db();
relationships_require_login($pdo);

$action = $_GET['action'] ?? '';

function relationships_activity_cw_id(PDO $pdo, int $customerId): ?string
{
    $stmt = $pdo->prepare('SELECT connectwise_id FROM customers WHERE id = :id');
    $stmt->execute([':id' => $customerId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $cwId = $row['connectwise_id'] ?? null;
    return ($cwId === null || $cwId === '' || str_starts_with((string) $cwId, 'MOCK-')) ? null : (string) $cwId;
}

if ($action === 'summary') {
    $customerId = (int) ($_GET['customer_id'] ?? 0);
    $cwId = relationships_activity_cw_id($pdo, $customerId);
    if ($cwId === null) {
        relationships_respond(200, ['ok' => true, 'available' => false, 'ticket_count_ytd' => null, 'billing' => null, 'billing_synced_at' => null]);
    }
    // Billing is read from the nightly sync (no live ConnectWise call here
    // -- see file header); only the ticket count is still live.
    $billing = relationships_cw_billing_stored_series($pdo, $customerId);
    $billingSyncedAt = relationships_cw_billing_last_synced_at($pdo, $customerId);
    try {
        $ticketCount = relationships_cw_activity_ticket_count_ytd($cwId);
        relationships_respond(200, [
            'ok' => true, 'available' => true,
            'ticket_count_ytd' => $ticketCount,
            'billing' => $billing,
            'billing_synced_at' => $billingSyncedAt,
        ]);
    } catch (RelationshipsConnectWiseError $e) {
        relationships_respond(502, ['ok' => false, 'error' => $e->getMessage()]);
    }
}

if ($action === 'tickets') {
    $customerId = (int) ($_GET['customer_id'] ?? 0);
    $cwId = relationships_activity_cw_id($pdo, $customerId);
    if ($cwId === null) {
        relationships_respond(200, ['ok' => true, 'tickets' => []]);
    }
    try {
        $tickets = relationships_cw_activity_tickets_ytd($cwId);
        relationships_respond(200, ['ok' => true, 'tickets' => $tickets]);
    } catch (RelationshipsConnectWiseError $e) {
        relationships_respond(502, ['ok' => false, 'error' => $e->getMessage()]);
    }
}

if ($action === 'invoices') {
    $customerId = (int) ($_GET['customer_id'] ?? 0);
    $month = (string) ($_GET['month'] ?? '');
    $year = (string) ($_GET['year'] ?? '');

    // 'year' added 2026-09-15 for the annual-billing-cadence bars' own
    // drill-down -- mutually exclusive with 'month' (a request never
    // needs both; the frontend sends exactly one depending on which kind
    // of bar was clicked).
    if ($year !== '') {
        if (!preg_match('/^\d{4}$/', $year)) {
            relationships_respond(400, ['ok' => false, 'error' => 'year must be "YYYY".']);
        }
        $cwId = relationships_activity_cw_id($pdo, $customerId);
        if ($cwId === null) {
            relationships_respond(200, ['ok' => true, 'year' => $year, 'invoices' => []]);
        }
        try {
            $invoices = relationships_cw_activity_invoices_for_year($cwId, $year);
            relationships_respond(200, ['ok' => true, 'year' => $year, 'invoices' => $invoices]);
        } catch (RelationshipsConnectWiseError $e) {
            relationships_respond(502, ['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        relationships_respond(400, ['ok' => false, 'error' => 'month must be "YYYY-MM" (or pass year=YYYY instead).']);
    }
    $cwId = relationships_activity_cw_id($pdo, $customerId);
    if ($cwId === null) {
        relationships_respond(200, ['ok' => true, 'month' => $month, 'invoices' => []]);
    }
    try {
        $invoices = relationships_cw_activity_invoices_for_month($cwId, $month);
        relationships_respond(200, ['ok' => true, 'month' => $month, 'invoices' => $invoices]);
    } catch (RelationshipsConnectWiseError $e) {
        relationships_respond(502, ['ok' => false, 'error' => $e->getMessage()]);
    }
}

if ($action === 'invoice-detail') {
    $invoiceId = (int) ($_GET['invoice_id'] ?? 0);
    if ($invoiceId <= 0) {
        relationships_respond(400, ['ok' => false, 'error' => 'Missing invoice_id.']);
    }
    try {
        $invoice = relationships_cw_activity_invoice_detail($pdo, $invoiceId);
        relationships_respond(200, ['ok' => true, 'invoice' => $invoice]);
    } catch (RelationshipsConnectWiseError $e) {
        relationships_respond(502, ['ok' => false, 'error' => $e->getMessage()]);
    }
}

relationships_respond(400, ['ok' => false, 'error' => 'Unknown action.']);
