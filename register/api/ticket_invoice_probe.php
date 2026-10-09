<?php
/**
 * register/api/ticket_invoice_probe.php
 *
 * TEMPORARY, READ-ONLY diagnostic for Task 2 of Michael's 2026-09-30
 * Register request ("mark an existing service ticket as Closed and have
 * that ticket move to ConnectWise Invoicing..."). Per Michael's explicit
 * choice (AskUserQuestion: "Probe first, then build"), this makes ZERO
 * writes to ConnectWise -- it only reads real records and reports their
 * raw shape, so the actual ticket-invoice feature can be designed from
 * confirmed field names instead of guesses. Same discipline as every
 * other ConnectWise unknown in this codebase (see register-app.md's
 * "ConnectWise integration -- confirmed facts" section) -- and the same
 * reason this file has to be deleted once its findings are folded into
 * real code, same as agreements_probe.php was (see
 * register-agreement-billing-probe-findings.md).
 *
 * This build environment has no network path to
 * connect.codebluetechnology.com, so Claude cannot run this itself --
 * Michael needs to open each action below (logged into the register app)
 * and report back what comes back, especially any error text.
 *
 * KNOWN RISK this probe exists to investigate: register-agreement-
 * billing-probe-findings.md already confirmed ConnectWise's Invoicing API
 * explicitly REFUSES to create an Agreement-type invoice
 * ("Cannot create agreement invoices through the Invoicing API"). It is
 * NOT yet known whether a Standard/ticket-type invoice hits the same
 * block, or how a ticket's time entries/parts/notes actually surface for
 * review before an invoice would be created. This probe does not attempt
 * to CREATE an invoice at all (that would be a write, and a much higher-
 * risk one than the Agreement create-test was, given it touches billing/
 * QuickBooks sync) -- it only reads what already exists, to inform
 * whether attempting a create is even worth a follow-up round.
 *
 * Every action below is independent and wrapped so one bad guess (a wrong
 * field/endpoint name -- expected, this is exploratory) can't stop the
 * others from reporting back. Run with no `ticket_id` first
 * (action=probe-open-tickets-with-time) to find a real ticket id worth
 * inspecting, then re-run the other actions with that id.
 *
 * GET ?action=probe-open-tickets-with-time
 *   -> a handful of real OPEN tickets on the two Professional Services
 *      boards, each annotated with whether it has any time entries at
 *      all -- so Michael doesn't have to already know a good ticket_id.
 * GET ?action=probe-ticket&ticket_id=N
 *   -> the full, unrestricted field set ConnectWise returns for one real
 *      ticket (no `fields` restriction sent at all) -- looking for any
 *      billing-status / invoice-reference field on the ticket itself.
 * GET ?action=probe-time-entries&ticket_id=N
 *   -> tries a couple of candidate condition field names
 *      (chargeToId/ticket) for that ticket's time entries, full field set,
 *      each attempt reported separately.
 * GET ?action=probe-ticket-products&ticket_id=N
 *   -> tries a couple of candidate endpoints for a ticket's Products/parts
 *      tab (ConnectWise's REST shape for this was never confirmed
 *      anywhere in this codebase before).
 * GET ?action=probe-invoices
 *   -> a small, unrestricted-fields page of real /finance/invoices rows
 *      (to see a real Standard-type example alongside the already-known
 *      Agreement-type ones, and check for any ticket-reference field).
 * GET ?action=probe-invoice-reference-lists
 *   -> tries /finance/invoices/types and /finance/invoices/statuses (the
 *      companion list endpoints to the already-confirmed
 *      /finance/agreements/types from the Agreement probe).
 */

declare(strict_types=1);

require_once __DIR__ . '/_util.php';
require_once __DIR__ . '/connectwise.php';

register_install_error_handlers();

$pdo = register_db();
register_require_login($pdo);

$action = $_GET['action'] ?? '';

const REGISTER_PROBE_BOARD_CONDITION =
    "(board/name='Professional Services - RIC' or board/name='Professional Services - WAR')";

/**
 * Runs one probe attempt and always returns a result row instead of
 * letting an exception kill the whole response -- so a wrong guess just
 * shows up as ok:false with the real ConnectWise error text, and every
 * other attempt in the same request still runs and reports back.
 */
function register_probe_try(string $label, callable $fn): array
{
    try {
        return ['label' => $label, 'ok' => true, 'result' => $fn()];
    } catch (Throwable $e) {
        return ['label' => $label, 'ok' => false, 'error' => $e->getMessage()];
    }
}

if ($action === 'probe-open-tickets-with-time') {
    $out = register_probe_try('open tickets + time-entry check', function () {
        $tickets = register_cw_list(
            '/service/tickets',
            'closedFlag=false and ' . REGISTER_PROBE_BOARD_CONDITION,
            ['id', 'summary', 'company', 'status'],
            25
        );
        $tickets = array_slice($tickets, 0, 10); // keep this probe cheap -- just need a few candidates
        $annotated = [];
        foreach ($tickets as $t) {
            $id = (int) ($t['id'] ?? 0);
            $hasTime = null;
            $timeError = null;
            try {
                $entries = register_cw_request('/time/entries', [
                    'conditions' => "chargeToId=$id",
                    'fields' => 'id',
                    'pageSize' => 1,
                ]);
                $hasTime = count($entries) > 0;
            } catch (Throwable $e) {
                $timeError = $e->getMessage();
            }
            $annotated[] = [
                'id' => $id,
                'summary' => $t['summary'] ?? '',
                'company_name' => $t['company']['name'] ?? '',
                'status_name' => $t['status']['name'] ?? '',
                'has_time_entries_via_chargeToId' => $hasTime,
                'chargeToId_probe_error' => $timeError,
            ];
        }
        return $annotated;
    });
    register_respond(200, ['ok' => true, 'probe' => $out]);
}

if ($action === 'probe-ticket') {
    $ticketId = (int) ($_GET['ticket_id'] ?? 0);
    if ($ticketId <= 0) {
        register_respond(400, ['ok' => false, 'error' => 'ticket_id is required.']);
    }
    $out = register_probe_try('full ticket record, no fields restriction', function () use ($ticketId) {
        return register_cw_request("/service/tickets/$ticketId", [], 'GET', null, 20, 6);
    });
    register_respond(200, ['ok' => true, 'probe' => $out]);
}

if ($action === 'probe-time-entries') {
    $ticketId = (int) ($_GET['ticket_id'] ?? 0);
    if ($ticketId <= 0) {
        register_respond(400, ['ok' => false, 'error' => 'ticket_id is required.']);
    }
    $attempts = [
        'chargeToId=' . $ticketId,
        'ticket/id=' . $ticketId,
    ];
    $results = [];
    foreach ($attempts as $condition) {
        $results[] = register_probe_try("time/entries?conditions=$condition", function () use ($condition) {
            return register_cw_request('/time/entries', [
                'conditions' => $condition,
                'pageSize' => 25,
            ], 'GET', null, 20, 6);
        });
    }
    register_respond(200, ['ok' => true, 'probe' => $results]);
}

if ($action === 'probe-ticket-products') {
    $ticketId = (int) ($_GET['ticket_id'] ?? 0);
    if ($ticketId <= 0) {
        register_respond(400, ['ok' => false, 'error' => 'ticket_id is required.']);
    }
    // None of these endpoint guesses have ever been confirmed against this
    // ConnectWise instance -- ConnectWise's "Products" tab on a ticket is
    // new territory for this codebase. Trying several plausible shapes in
    // one pass rather than guessing one at a time across several rounds.
    $attempts = [
        'GET /procurement/products?conditions=ticket/id=' . $ticketId =>
            static fn () => register_cw_request('/procurement/products', ['conditions' => 'ticket/id=' . $ticketId, 'pageSize' => 25], 'GET', null, 20, 6),
        'GET /service/tickets/' . $ticketId . '/products' =>
            static fn () => register_cw_request("/service/tickets/$ticketId/products", [], 'GET', null, 20, 6),
        'GET /procurement/catalog?conditions=ticket/id=' . $ticketId =>
            static fn () => register_cw_request('/procurement/catalog', ['conditions' => 'ticket/id=' . $ticketId, 'pageSize' => 25], 'GET', null, 20, 6),
    ];
    $results = [];
    foreach ($attempts as $label => $fn) {
        $results[] = register_probe_try($label, $fn);
    }
    register_respond(200, ['ok' => true, 'probe' => $results]);
}

if ($action === 'probe-invoices') {
    $out = register_probe_try('recent invoices, no fields restriction', function () {
        return register_cw_request('/finance/invoices', [
            'pageSize' => 10,
            'orderBy' => 'id desc',
        ], 'GET', null, 20, 6);
    });
    register_respond(200, ['ok' => true, 'probe' => $out]);
}

if ($action === 'probe-invoice-reference-lists') {
    $attempts = [
        'GET /finance/invoices/types' => static fn () => register_cw_request('/finance/invoices/types', [], 'GET', null, 20, 6),
        'GET /finance/invoices/statuses' => static fn () => register_cw_request('/finance/invoices/statuses', [], 'GET', null, 20, 6),
    ];
    $results = [];
    foreach ($attempts as $label => $fn) {
        $results[] = register_probe_try($label, $fn);
    }
    register_respond(200, ['ok' => true, 'probe' => $results]);
}

if ($action === 'probe-board-statuses') {
    // Confirms the real "Closed" status id(s) on each Professional
    // Services board -- needed before this app can push a status change
    // to ConnectWise, since each board has its own status list and a
    // status's own `closedFlag` (not just the ticket's top-level
    // `closedFlag`) is what actually marks a ticket closed on this board.
    //
    // NOTE (fixed after first live run): /service/boards is queried by
    // the board's OWN `name` field, not the related-entity `board/name`
    // condition used against /service/tickets -- a board has no "board"
    // relation to itself. The first attempt sent board/name and got back
    // ConnectWise's real error: {"code":"ApiFindCondition","message":
    // "board\/name is not a recognized name."} -- confirming the fix
    // rather than guessing it.
    $out = register_probe_try('both Professional Services boards + their status lists', function () {
        $boards = register_cw_request('/service/boards', [
            'conditions' => "(name='Professional Services - RIC' or name='Professional Services - WAR')",
            'fields' => 'id,name',
        ], 'GET', null, 20, 6);
        $result = [];
        foreach ($boards as $b) {
            $statuses = register_cw_request('/service/boards/' . $b['id'] . '/statuses', [
                'pageSize' => 50,
            ], 'GET', null, 20, 6);
            $result[] = ['board' => $b, 'statuses' => $statuses];
        }
        return $result;
    });
    register_respond(200, ['ok' => true, 'probe' => $out]);
}

if ($action === 'probe-invoice-time-entries') {
    // Checks whether a real, already-closed Standard invoice actually has
    // any ticket-sourced time entries linked to it (invoice/id=N) -- the
    // 8-invoice sample from probe-invoices were all applyToType=SalesOrder,
    // so this checks a specific real invoice id (pass one from that probe)
    // for whether ticket time ever lands on a plain Standard invoice at
    // all, or only ever flows through Agreement billing.
    $invoiceId = (int) ($_GET['invoice_id'] ?? 0);
    if ($invoiceId <= 0) {
        register_respond(400, ['ok' => false, 'error' => 'invoice_id is required.']);
    }
    $out = register_probe_try("time/entries?conditions=invoice/id=$invoiceId", function () use ($invoiceId) {
        return register_cw_request('/time/entries', [
            'conditions' => 'invoice/id=' . $invoiceId,
            'pageSize' => 25,
        ], 'GET', null, 20, 6);
    });
    register_respond(200, ['ok' => true, 'probe' => $out]);
}

if ($action === 'probe-tickets-no-agreement') {
    // probe-ticket&ticket_id=906874 turned out to be tied to a real
    // Agreement ("Voice Agreement", ActualRates billing) -- so it's NOT a
    // clean example of the plain Standard/T&M path Michael chose to scope
    // "Create Invoice" to first. This looks for an open ticket on either
    // board that has NO agreement attached at all, a better candidate for
    // that path. Pulls a modest page of open tickets with their `agreement`
    // field included and filters client-side (never confirmed whether
    // `agreement=null`/`agreement/id=null` is valid ConnectWise condition
    // syntax, so this avoids guessing at one).
    $out = register_probe_try('open tickets with no agreement attached', function () {
        $tickets = register_cw_list(
            '/service/tickets',
            'closedFlag=false and ' . REGISTER_PROBE_BOARD_CONDITION,
            ['id', 'summary', 'company', 'agreement', 'billingMethod'],
            100
        );
        $noAgreement = array_values(array_filter($tickets, static function (array $t): bool {
            return empty($t['agreement']);
        }));
        return array_slice($noAgreement, 0, 15);
    });
    register_respond(200, ['ok' => true, 'probe' => $out]);
}

if ($action === 'probe-invoiced-ticket-time') {
    // probe-invoice-time-entries&invoice_id=124363 came back empty --
    // that one sampled Standard invoice has no linked time entries, same
    // as the earlier 8-invoice sample (all applyToType=SalesOrder). Rather
    // than keep guessing invoice ids one at a time, this searches
    // /time/entries directly for ANY entry that already has a real
    // invoice attached (invoice/id>0), newest first, to find a genuine
    // ticket-sourced example (chargeToType should read 'ServiceTicket' or
    // 'Ticket' for one) and see what invoice type it actually landed on --
    // still unconfirmed whether ticket time ever reaches a plain Standard
    // invoice at all, or only ever flows through Agreement billing.
    $out = register_probe_try('time/entries?conditions=invoice/id>0, newest first', function () {
        return register_cw_request('/time/entries', [
            'conditions' => 'invoice/id>0',
            'orderBy' => 'id desc',
            'pageSize' => 25,
        ], 'GET', null, 20, 6);
    });
    register_respond(200, ['ok' => true, 'probe' => $out]);
}

if ($action === 'probe-invoice-detail') {
    // probe-invoiced-ticket-time found 25 real ticket time entries with
    // real invoices attached (ids 124323-124352), 24 of 25 with NO
    // agreement field at all (the one exception, a PeopleFirst Support
    // Agreement-billed entry, still got its own invoice too). This fetches
    // one invoice's own record directly -- specifically its `type` field
    // -- to confirm what kind of invoice ticket time actually lands on.
    // Pass invoice_id for a clean no-agreement example (e.g. 124347) and,
    // separately, the one agreement-billed example (124352) for
    // comparison -- do NOT assume they're the same type.
    $invoiceId = (int) ($_GET['invoice_id'] ?? 0);
    if ($invoiceId <= 0) {
        register_respond(400, ['ok' => false, 'error' => 'invoice_id is required.']);
    }
    $out = register_probe_try("finance/invoices/$invoiceId", function () use ($invoiceId) {
        return register_cw_request('/finance/invoices/' . $invoiceId, [], 'GET', null, 20, 6);
    });
    register_respond(200, ['ok' => true, 'probe' => $out]);
}

if ($action === 'probe-invoice-statuses-sample') {
    // /finance/invoices/types and /finance/invoices/statuses both 404
    // ("The endpoint does not exist.") -- unlike boards/tax codes/billing
    // terms, this ConnectWise instance has no dedicated reference-list
    // endpoint for invoice status/type. The two real invoices probed
    // directly so far (124347, 124352) were both already status id 3
    // "Closed" or id 6 "Closed - Emailed" -- same-day closed, so neither
    // shows what a brand-new, not-yet-closed invoice's status actually
    // is. This instead samples a wider, most-recent page of real invoices
    // (fields kept light) and reports every distinct status {id,name}
    // actually seen, hoping to catch one still open.
    $out = register_probe_try('finance/invoices, pageSize 50, id desc, status field only', function () {
        $rows = register_cw_request('/finance/invoices', [
            'pageSize' => 50,
            'orderBy' => 'id desc',
            'fields' => 'id,invoiceNumber,status,type,applyToType,date,total',
        ], 'GET', null, 25, 6);
        $seen = [];
        foreach ($rows as $r) {
            $s = $r['status'] ?? null;
            if (is_array($s) && isset($s['id'])) {
                $seen[$s['id']] = $s['name'] ?? ('#' . $s['id']);
            }
        }
        return ['distinct_statuses_seen' => $seen, 'sample_count' => count($rows), 'rows' => $rows];
    });
    register_respond(200, ['ok' => true, 'probe' => $out]);
}

register_respond(400, ['ok' => false, 'error' => 'Unknown action.']);
