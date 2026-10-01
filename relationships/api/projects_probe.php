<?php
/**
 * relationships/api/projects_probe.php
 *
 * TEMPORARY, READ-ONLY diagnostic for Michael's 2026-09-30 "Projects"
 * request (claude/relationships-projects-module-plan.md): a new
 * Relationships nav button listing every ConnectWise Project (Pre-Sale
 * board + Services Projects board), with a per-row coordinator assignment
 * and a checklist whose textbox is meant to post a real ConnectWise
 * Project Note.
 *
 * We've never touched ConnectWise's Projects module in this codebase --
 * same situation Register's ticket_invoice_probe.php and this app's own
 * cw-catalog-probe.php were each written to resolve before writing real
 * code. Per this project's standing rule (see register-app.md's
 * "ConnectWise integration -- confirmed facts" and the board-name saga in
 * relationships-connectwise-sync.md), nothing below is guessed into real
 * code -- it only reads real records and reports their raw shape /
 * ConnectWise's own error text, so the module gets designed from confirmed
 * field and endpoint names instead of assumptions.
 *
 * Unresolved questions this probe exists to answer:
 *   1. Exact real names of the two project boards Michael means by
 *      "Pre-Sale board" and "Services Projects" (for the list's board
 *      filter condition).
 *   2. A real Project record's full, unrestricted field set -- which of
 *      estimatedStart/scheduledStart/actualStart is the "Date Start"
 *      Michael wants, and confirm company/status/name field shapes.
 *   3. Whether a Project-level Notes sub-resource exists and is reachable
 *      at all (public API research found documented "project TICKET
 *      notes" tools but no documented Project-level notes resource --
 *      this instance may differ, so several candidate shapes are tried
 *      rather than assumed absent).
 *
 * Gated behind relationships_require_login() like every other endpoint in
 * this app -- not public. Makes ZERO writes to ConnectWise. DELETE THIS
 * FILE once the real board names / date field / notes endpoint are
 * confirmed and the actual Projects module is built against them (same
 * lifecycle as cw-catalog-probe.php and the now-removed
 * checklist.php?action=cw_date_probe).
 *
 * This build environment has no network path to
 * connect.codebluetechnology.com, so Claude cannot run this itself --
 * Michael needs to open each action below (logged into the Relationships
 * app) and report back what comes back, especially any error text.
 *
 * GET ?action=probe-boards
 *   -> every board in the Project module, so the real "Pre-Sale"/
 *      "Services Projects" spelling can be confirmed (Michael's own
 *      wording may not match ConnectWise's stored board name exactly).
 * GET ?action=probe-projects[&board_id=N]
 *   -> a small page of real projects, FULL field set (no `fields`
 *      restriction), optionally narrowed to one board id from the boards
 *      probe above. Looking for: company, name, status, and which of the
 *      three start-date fields is populated/meaningful.
 * GET ?action=probe-project&project_id=N
 *   -> one real project's complete record, unrestricted -- a closer look
 *      once probe-projects has surfaced a real id worth inspecting.
 * GET ?action=probe-project-notes&project_id=N
 *   -> tries several candidate shapes for project-level notes (a
 *      sub-resource, a top-level /project/notes list filtered by project,
 *      and -- since public docs only confirm project-TICKET notes, not
 *      project notes -- a related /project/projects/{id}/tickets lookup
 *      so a ticket id is available for a follow-up ticket-notes probe).
 * GET ?action=probe-ticket-notes&ticket_id=N
 *   -> candidate ticket-notes endpoint for a ticket id found via
 *      probe-project-notes above (confirms/refutes the "notes live on a
 *      project's ticket, not the project itself" possibility).
 */

declare(strict_types=1);

require_once __DIR__ . '/_util.php';
require_once __DIR__ . '/connectwise.php';

$pdo = relationships_db();
relationships_require_login($pdo);

$action = $_GET['action'] ?? '';

/**
 * Runs one candidate GET and captures success or the exact ConnectWise
 * error, never throwing -- so one bad guess doesn't stop the rest of the
 * candidates in the same pass from reporting back. Same shape as
 * cw-catalog-probe.php's helper of the same name (kept local to each
 * probe file rather than shared, since both are meant to be deleted once
 * their findings are folded into real code).
 */
function relationships_cw_probe_attempt(string $label, string $path, array $query = []): array
{
    try {
        $result = relationships_cw_request($path, $query);
        return ['label' => $label, 'ok' => true, 'result' => $result];
    } catch (Throwable $e) {
        return ['label' => $label, 'ok' => false, 'error' => $e->getMessage()];
    }
}

if ($action === 'probe-boards') {
    $out = relationships_cw_probe_attempt('GET /project/boards', '/project/boards', ['pageSize' => '100']);
    relationships_respond(200, ['ok' => true, 'probe' => $out]);
}

if ($action === 'probe-projects') {
    $boardId = $_GET['board_id'] ?? '';
    $query = ['pageSize' => '10', 'orderBy' => 'id desc'];
    $label = 'GET /project/projects (unrestricted fields)';
    if ($boardId !== '') {
        $query['conditions'] = 'board/id=' . (int) $boardId;
        $label .= ' conditions=board/id=' . (int) $boardId;
    }
    $out = relationships_cw_probe_attempt($label, '/project/projects', $query);
    relationships_respond(200, ['ok' => true, 'probe' => $out]);
}

if ($action === 'probe-project') {
    $projectId = (int) ($_GET['project_id'] ?? 0);
    if ($projectId <= 0) {
        relationships_respond(400, ['ok' => false, 'error' => 'project_id is required.']);
    }
    $out = relationships_cw_probe_attempt(
        "GET /project/projects/$projectId (unrestricted fields)",
        "/project/projects/$projectId"
    );
    relationships_respond(200, ['ok' => true, 'probe' => $out]);
}

if ($action === 'probe-project-notes') {
    $projectId = (int) ($_GET['project_id'] ?? 0);
    if ($projectId <= 0) {
        relationships_respond(400, ['ok' => false, 'error' => 'project_id is required.']);
    }
    $attempts = [
        relationships_cw_probe_attempt(
            "sub-resource GET /project/projects/$projectId/notes",
            "/project/projects/$projectId/notes"
        ),
        relationships_cw_probe_attempt(
            'top-level GET /project/notes?conditions=projectId=' . $projectId,
            '/project/notes',
            ['conditions' => 'projectId=' . $projectId]
        ),
        relationships_cw_probe_attempt(
            'top-level GET /project/notes?conditions=project/id=' . $projectId,
            '/project/notes',
            ['conditions' => 'project/id=' . $projectId]
        ),
        relationships_cw_probe_attempt(
            "project's own tickets GET /project/projects/$projectId/tickets (for a follow-up probe-ticket-notes call)",
            "/project/projects/$projectId/tickets",
            ['pageSize' => '5']
        ),
    ];
    relationships_respond(200, ['ok' => true, 'probe' => $attempts]);
}

if ($action === 'probe-ticket-notes') {
    $ticketId = (int) ($_GET['ticket_id'] ?? 0);
    if ($ticketId <= 0) {
        relationships_respond(400, ['ok' => false, 'error' => 'ticket_id is required.']);
    }
    $attempts = [
        relationships_cw_probe_attempt(
            "sub-resource GET /service/tickets/$ticketId/notes",
            "/service/tickets/$ticketId/notes"
        ),
    ];
    relationships_respond(200, ['ok' => true, 'probe' => $attempts]);
}

relationships_respond(400, [
    'ok' => false,
    'error' => 'Unknown action.',
    'valid_actions' => [
        'probe-boards',
        'probe-projects',
        'probe-project',
        'probe-project-notes',
        'probe-ticket-notes',
    ],
]);
