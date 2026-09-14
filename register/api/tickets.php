<?php
/**
 * register/api/tickets.php
 *
 * Pending Service Tickets screen (added 2026-09-14, front-screen redesign).
 * Per Michael: a list of OPEN tickets on CBT's two Professional Services
 * boards, searchable by ticket number, company name, or telephone number.
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

register_respond(400, ['ok' => false, 'error' => 'Unknown action.']);
