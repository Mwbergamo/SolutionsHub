<?php
/**
 * register/api/tickets.php
 *
 * Pending Service Tickets screen (added 2026-09-14, front-screen redesign).
 * Per Michael: a list of OPEN tickets on CBT's two Professional Services
 * boards, searchable by ticket number, company name, or telephone number.
 *
 * Extended 2026-10-09 (Part 2 of Michael's 2026-09-30 Register request,
 * scoped down to the ticket detail popover + status-change piece -- the
 * "Create Invoice" half was found to be a hard ConnectWise platform
 * limitation with no REST equivalent, see claude/register-app.md's Part 2
 * section, and was dropped from scope per Michael's explicit choice):
 * clicking a ticket now opens a detail popover (notes, time entries broken
 * out by billable/non-billable/no-charge with per-type and ticket totals,
 * applied hourly rate per line) and lets the tech change the ticket's
 * status, pushed live to ConnectWise.
 *
 * ---- Board condition (reused, already confirmed) ----
 * The two-board OR condition and its exact spelling --
 * "Professional Services - RIC" / "Professional Services - WAR" (space on
 * BOTH sides of the hyphen) -- comes from relationships/api/
 * connectwise-activity.php's relationships_cw_activity_board_condition(),
 * confirmed live against this same ConnectWise instance on 2026-09-10 after
 * three wrong-guess passes (see claude/relationships-connectwise-sync.md's
 * board-name saga -- the short version: a bad related-entity condition
 * matches NOTHING and returns an empty list, not an error, so it's silently
 * wrong rather than loudly broken). Combining it with "and <another single
 * condition>" is ALSO already proven working in that same file
 * (company/id=X and <board condition> and dateEntered>=[...]), so
 * `closedFlag=false and (<board condition>)` below follows an established,
 * not a guessed, pattern.
 *
 * `closedFlag` and the contact fields (contactName/contactPhoneNumber/
 * contactEmailAddress) are standard, direct (non-related-entity) fields on
 * ConnectWise's ServiceTicket object -- the same class of field as
 * Contact.inactiveFlag, which IS confirmed reliable elsewhere in this
 * codebase, unlike a related-entity field like board/name. This build
 * environment has no network path to connect.codebluetechnology.com (same
 * confirmed restriction noted throughout this project) or to
 * developer.connectwise.com's public spec, and the ConnectWise Manage web
 * UI itself turned out to call an obfuscated internal service (not the
 * public REST API), not something this session could snoop a request from
 * either -- so unlike the board-name condition, these fields could NOT be
 * independently re-confirmed against a real ticket before shipping. Treat
 * the first real "Pending Service Tickets" screen open as this feature's
 * live check: if it errors, or every ticket's phone comes back empty, that
 * points at one of these field names being wrong for this instance and
 * needs a real ConnectWise ticket screenshot to fix, same as the
 * board-name saga was eventually fixed.
 *
 * All ConnectWise work here is a single bounded list call (register_cw_list
 * loops pages internally, capped at 200 pages of up to 200 rows) -- open
 * tickets across two boards for one company is expected to be dozens, not
 * thousands, so unlike the ~14,600-item catalog sync this is not expected
 * to risk a hosting execution-time limit. No server-side text search is
 * attempted (no compound condition beyond the two already-proven ones is
 * sent to ConnectWise, per this project's "diagnose before guessing"
 * discipline) -- ticket number/company name/phone matching all happens
 * client-side in app.js against this one fetched list instead.
 *
 * GET /register/api/tickets.php?action=open
 *   -> { ok: true, tickets: [{ id, ticket_number, summary, date_entered,
 *        board_name, status_name, company_id, company_name, company_phone,
 *        contact_name, contact_phone, contact_email }] }
 *
 * GET /register/api/tickets.php?action=detail&ticket_id=N
 *   -> { ok: true, ticket: { id, summary, board_id, board_name,
 *        status_id, status_name, status_options: [{id,name,closed_flag}],
 *        company_name, contact_name, date_entered, closed_flag,
 *        notes: [{ text, label, member_name }],
 *        time_entries: [{ id, member_name, date_entered, billable_option,
 *          billable_label, hours, hourly_rate, extended_amount, notes,
 *          work_role_name }],
 *        time_totals: { <billable_option>: { label, hours, amount } },
 *        ticket_total } }
 *   Ticket notes (`/service/tickets/{id}/notes`) were NOT independently
 *   confirmed against this ConnectWise instance before shipping (same
 *   build-environment network restriction as above) -- the real field
 *   shape (text/detailDescriptionFlag/internalAnalysisFlag/resolutionFlag/
 *   member) comes from inspecting a real, independently-generated
 *   ConnectWise Manage REST API client's model (github.com/HealthITAU/
 *   pyconnectwise), not a blind guess, but treat the first real ticket
 *   popover open as this feature's live check for the Notes section
 *   specifically -- everything else on this action (time entries, board
 *   statuses) reuses fields already confirmed live earlier in this project
 *   (see claude/register-app.md's Part 2 section).
 *
 * POST /register/api/tickets.php?action=update-status
 *   body: { ticket_id, status_id }
 *   -> { ok: true, ticket: { id, status_id, status_name } }
 *   Pushes the status change to ConnectWise -- tries a plain PATCH first
 *   (confirmed working live, 2026-10-09, against Service Ticket #953916),
 *   falling back to the proven PUT-fetch-modify-send-back-with-auto-strip
 *   approach if PATCH ever fails for a status/ticket combination it
 *   hasn't been tried against yet (same register_cw_patch_then_put()
 *   helper in connectwise.php, shared with any future write of this
 *   shape). Refuses if status_id isn't actually one of the target
 *   ticket's own board's statuses, as a guard against a stale dropdown.
 */

declare(strict_types=1);

require_once __DIR__ . '/_util.php';
require_once __DIR__ . '/connectwise.php';

register_install_error_handlers();

$pdo = register_db();
register_require_login($pdo);

$action = $_GET['action'] ?? '';

const REGISTER_TICKETS_BOARD_CONDITION =
    "(board/name='Professional Services - RIC' or board/name='Professional Services - WAR')";

if ($action === 'open') {
    try {
        $rows = register_cw_list(
            '/service/tickets',
            'closedFlag=false and ' . REGISTER_TICKETS_BOARD_CONDITION,
            ['id', 'summary', 'dateEntered', 'board', 'status', 'company', 'contactName', 'contactPhoneNumber', 'contactEmailAddress'],
            100
        );

        $tickets = array_map(static function (array $t): array {
            $company = is_array($t['company'] ?? null) ? $t['company'] : [];
            $board = is_array($t['board'] ?? null) ? $t['board'] : [];
            $status = is_array($t['status'] ?? null) ? $t['status'] : [];
            return [
                'id' => (int) ($t['id'] ?? 0),
                'ticket_number' => (int) ($t['id'] ?? 0), // ConnectWise's ticket "number" IS its id
                'summary' => (string) ($t['summary'] ?? ''),
                'date_entered' => $t['dateEntered'] ?? null,
                'board_name' => $board['name'] ?? '',
                'status_name' => $status['name'] ?? '',
                'company_id' => isset($company['id']) ? (int) $company['id'] : null,
                'company_name' => $company['name'] ?? '',
                'company_phone' => $company['phoneNumber'] ?? '',
                'contact_name' => $t['contactName'] ?? '',
                'contact_phone' => $t['contactPhoneNumber'] ?? '',
                'contact_email' => $t['contactEmailAddress'] ?? '',
            ];
        }, $rows);

        usort($tickets, static fn (array $a, array $b): int => strcmp((string) $b['date_entered'], (string) $a['date_entered']));

        register_respond(200, ['ok' => true, 'tickets' => $tickets]);
    } catch (Throwable $e) {
        register_respond(502, ['ok' => false, 'error' => 'Could not load service tickets from ConnectWise: ' . $e->getMessage()]);
    }
}

// Human-friendly labels for the three ConnectWise billableOption values
// expected on a time entry. "Billable" is confirmed live; "DoNotBill" and
// "NoCharge" are the documented ConnectWise enum values for the other two
// but have not yet been observed on a real entry against this instance --
// any other/unexpected value still displays (using the raw value itself
// as its own label) rather than silently disappearing from the popover.
function register_ticket_billable_label(string $billableOption): string
{
    return match ($billableOption) {
        'Billable' => 'Billable',
        'DoNotBill' => 'Non-Billable',
        'NoCharge' => 'No Charge',
        default => $billableOption !== '' ? $billableOption : 'Unspecified',
    };
}

if ($action === 'detail') {
    $ticketId = (int) ($_GET['ticket_id'] ?? 0);
    if ($ticketId <= 0) {
        register_respond(400, ['ok' => false, 'error' => 'ticket_id is required.']);
    }

    try {
        $ticket = register_cw_request('/service/tickets/' . $ticketId, [
            'fields' => 'id,summary,dateEntered,closedFlag,board,status,company,contactName,contactPhoneNumber,contactEmailAddress',
        ], 'GET', null, 20, 6);

        $board = is_array($ticket['board'] ?? null) ? $ticket['board'] : [];
        $status = is_array($ticket['status'] ?? null) ? $ticket['status'] : [];
        $company = is_array($ticket['company'] ?? null) ? $ticket['company'] : [];
        $boardId = isset($board['id']) ? (int) $board['id'] : 0;

        $statusOptions = [];
        if ($boardId > 0) {
            $statusRows = register_cw_request('/service/boards/' . $boardId . '/statuses', [
                'pageSize' => 50,
                'fields' => 'id,name,closedStatus,inactive,sortOrder',
            ], 'GET', null, 20, 6);
            usort($statusRows, static fn (array $a, array $b): int => ((int) ($a['sortOrder'] ?? 0)) <=> ((int) ($b['sortOrder'] ?? 0)));
            foreach ($statusRows as $s) {
                if (!empty($s['inactive'])) {
                    continue; // don't offer a retired status in the dropdown
                }
                $statusOptions[] = [
                    'id' => (int) ($s['id'] ?? 0),
                    'name' => (string) ($s['name'] ?? ''),
                    'closed_flag' => (bool) ($s['closedStatus'] ?? false),
                ];
            }
        }

        $notes = [];
        try {
            $noteRows = register_cw_request('/service/tickets/' . $ticketId . '/notes', ['pageSize' => 50], 'GET', null, 20, 6);
            foreach ($noteRows as $n) {
                $text = trim((string) ($n['text'] ?? ''));
                if ($text === '') {
                    continue;
                }
                $label = 'Note';
                if (!empty($n['detailDescriptionFlag'])) {
                    $label = 'Initial Description';
                } elseif (!empty($n['resolutionFlag'])) {
                    $label = 'Resolution';
                } elseif (!empty($n['internalAnalysisFlag'])) {
                    $label = 'Internal Analysis';
                }
                $member = is_array($n['member'] ?? null) ? $n['member'] : [];
                $notes[] = [
                    'text' => $text,
                    'label' => $label,
                    'member_name' => $member['name'] ?? $member['identifier'] ?? '',
                ];
            }
        } catch (Throwable $e) {
            // Read-only, non-critical to the rest of the popover -- if the
            // notes sub-resource's real shape turns out to differ on this
            // instance, surface that as an empty Notes section rather than
            // failing the whole detail fetch.
            $notes = [];
        }

        $timeRows = register_cw_request('/time/entries', [
            'conditions' => "chargeToId=$ticketId",
            'pageSize' => 100,
            'fields' => 'id,member,dateEntered,billableOption,hoursBilled,actualHours,hourlyRate,extendedInvoiceAmount,notes,workRole',
        ], 'GET', null, 20, 6);

        $timeEntries = [];
        $totals = []; // billableOption -> ['label','hours','amount']
        $ticketTotal = 0.0;
        foreach ($timeRows as $t) {
            $billableOption = (string) ($t['billableOption'] ?? '');
            $label = register_ticket_billable_label($billableOption);
            $hours = (float) ($t['hoursBilled'] ?? $t['actualHours'] ?? 0);
            $rate = (float) ($t['hourlyRate'] ?? 0);
            $amount = (float) ($t['extendedInvoiceAmount'] ?? ($hours * $rate));
            $member = is_array($t['member'] ?? null) ? $t['member'] : [];
            $workRole = is_array($t['workRole'] ?? null) ? $t['workRole'] : [];

            $timeEntries[] = [
                'id' => (int) ($t['id'] ?? 0),
                'member_name' => $member['name'] ?? $member['identifier'] ?? '',
                'date_entered' => $t['dateEntered'] ?? null,
                'billable_option' => $billableOption,
                'billable_label' => $label,
                'hours' => $hours,
                'hourly_rate' => $rate,
                'extended_amount' => $amount,
                'notes' => (string) ($t['notes'] ?? ''),
                'work_role_name' => $workRole['name'] ?? '',
            ];

            if (!isset($totals[$billableOption])) {
                $totals[$billableOption] = ['label' => $label, 'hours' => 0.0, 'amount' => 0.0];
            }
            $totals[$billableOption]['hours'] += $hours;
            $totals[$billableOption]['amount'] += $amount;
            $ticketTotal += $amount;
        }

        register_respond(200, ['ok' => true, 'ticket' => [
            'id' => $ticketId,
            'summary' => (string) ($ticket['summary'] ?? ''),
            'board_id' => $boardId,
            'board_name' => $board['name'] ?? '',
            'status_id' => isset($status['id']) ? (int) $status['id'] : 0,
            'status_name' => $status['name'] ?? '',
            'status_options' => $statusOptions,
            'company_name' => $company['name'] ?? '',
            'contact_name' => $ticket['contactName'] ?? '',
            'date_entered' => $ticket['dateEntered'] ?? null,
            'closed_flag' => (bool) ($ticket['closedFlag'] ?? false),
            'notes' => $notes,
            'time_entries' => $timeEntries,
            'time_totals' => array_values($totals),
            'ticket_total' => $ticketTotal,
        ]]);
    } catch (Throwable $e) {
        register_respond(502, ['ok' => false, 'error' => 'Could not load this ticket from ConnectWise: ' . $e->getMessage()]);
    }
}

if ($action === 'update-status') {
    $body = json_decode(file_get_contents('php://input') ?: '[]', true);
    $ticketId = (int) ($body['ticket_id'] ?? 0);
    $statusId = (int) ($body['status_id'] ?? 0);
    if ($ticketId <= 0 || $statusId <= 0) {
        register_respond(400, ['ok' => false, 'error' => 'ticket_id and status_id are required.']);
    }

    try {
        $ticket = register_cw_request('/service/tickets/' . $ticketId, [
            'fields' => 'id,board,status',
        ], 'GET', null, 20, 6);
        $board = is_array($ticket['board'] ?? null) ? $ticket['board'] : [];
        $boardId = isset($board['id']) ? (int) $board['id'] : 0;
        if ($boardId <= 0) {
            register_respond(502, ['ok' => false, 'error' => 'This ticket has no board on file -- cannot change its status.']);
        }

        $statusRows = register_cw_request('/service/boards/' . $boardId . '/statuses', [
            'pageSize' => 50,
            'fields' => 'id,name',
        ], 'GET', null, 20, 6);
        $statusName = null;
        foreach ($statusRows as $s) {
            if ((int) ($s['id'] ?? 0) === $statusId) {
                $statusName = (string) ($s['name'] ?? '');
                break;
            }
        }
        if ($statusName === null) {
            register_respond(400, ['ok' => false, 'error' => 'That status is not valid for this ticket\'s board. Reload the ticket and try again.']);
        }

        register_cw_patch_then_put('/service/tickets/' . $ticketId, '/status', ['status' => ['id' => $statusId]]);

        register_respond(200, ['ok' => true, 'ticket' => [
            'id' => $ticketId,
            'status_id' => $statusId,
            'status_name' => $statusName,
        ]]);
    } catch (Throwable $e) {
        register_respond(502, ['ok' => false, 'error' => 'Could not update this ticket\'s status in ConnectWise: ' . $e->getMessage()]);
    }
}

register_respond(400, ['ok' => false, 'error' => 'Unknown action.']);
