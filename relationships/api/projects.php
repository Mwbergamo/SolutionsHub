<?php
/**
 * relationships/api/projects.php
 *
 * Backend for the Relationships app's new Projects nav button -- added
 * 2026-10-02 per Michael's "Projects Follow Up" request (see
 * claude/relationships-projects-module-plan.md for the full request, the
 * three clarifying answers, and every ConnectWise fact below as confirmed
 * live against real data, not guessed):
 *
 *   - The list is every ConnectWise Project on the Pre-Sales (board id 45)
 *     or Services Projects (board id 3) boards, read LIVE on every screen
 *     open -- no local project sync/cache, same precedent as Register's
 *     Pending Service Tickets (a project list is small, dozens not
 *     thousands, and changes status often).
 *   - Company name = company.name, Project Name = name, Status
 *     Description = status.name, Date Start = estimatedStart.
 *   - Coordinator assignment is LOCAL-ONLY bookkeeping (project_assignments)
 *     -- no ConnectWise write, same as the Risk Scan assignment pattern
 *     this mirrors (risk-scans.php).
 *   - The 5-step checklist (project_checklist_progress) is also local-only;
 *     step 4 additionally stores a kickoff date (project_kickoff_dates).
 *   - The Notes textbox pushes a REAL ConnectWise Project Note
 *     (GET/POST /project/projects/{id}/notes, type id 2 = "Comment") --
 *     confirmed live, including a real test write (project 1333, note id
 *     4047). ConnectWise's own `updatedBy` cannot be set to the actual
 *     coordinator -- it always stamps this integration's shared API
 *     member ("Clyde") -- so the coordinator's name is prefixed onto the
 *     note TEXT itself instead, and project_notes keeps a local audit row
 *     (with real created_by_user_id) regardless of whether the live push
 *     to ConnectWise succeeds, same fail-open spirit as Agreement sync's
 *     agreement_warning / tax lookup's tax_warning.
 *
 * GET  ?action=list
 *   -> { ok: true, projects: [ { id, company_name, name, status_name,
 *        status_id, is_closed, board_id, board_name, start_date, contact_id,
 *        contact_name, assigned_to_name, checklist: { step_number:
 *        {completed_at, completed_by_name} }, kickoff_date }, ... ],
 *        roster: [name, ...] }
 *        Sorting and the "hide closed projects by default" status filter
 *        (added 2026-10-02, see plan doc) both happen client-side over this
 *        same already-loaded list -- is_closed (from the real ProjectStatus
 *        entity's closedFlag, see relationships_projects_status_closed_map())
 *        is what the frontend's default filter checks.
 * POST ?action=assign      { project_id, assigned_to_name }
 * POST ?action=unassign    { project_id }
 * POST ?action=checklist_toggle  { project_id, step_number, completed }
 * POST ?action=kickoff_date_set  { project_id, kickoff_date }  ("" clears it)
 * GET  ?action=contact_info&contact_id=N
 *   -> { ok: true, email, phone }  (live ConnectWise lookup, for the
 *      checklist's mailto:/tel: icons -- a project's own `contact` field
 *      only carries an id/name, never an email, so this is a live,
 *      on-demand fetch, not a cached one)
 * GET  ?action=notes_list&project_id=N
 *   -> { ok: true, notes: [...], source: 'connectwise' | 'local' }
 *      (falls back to the local audit log, flagged source:'local', if the
 *      live ConnectWise read fails -- never errors the whole notes panel)
 * POST ?action=notes_add   { project_id, note_text }
 *   -> { ok: true, cw_note_id } or { ok: true, cw_warning }  (the local
 *      row is written FIRST and always succeeds if this returns 200 at
 *      all -- cw_warning means ConnectWise itself rejected the push, not
 *      that the note was lost)
 *
 * Gated behind relationships_require_login() like every other endpoint in
 * this app. Not territory-scoped -- unlike the per-customer dashboard
 * views, this is a project-management module open to any signed-in
 * coordinator, same access model as the Sync/Prospecting screens.
 */

declare(strict_types=1);

require_once __DIR__ . '/_util.php';
require_once __DIR__ . '/connectwise.php';
require_once __DIR__ . '/catalog.php';

const RELATIONSHIPS_PROJECTS_BOARD_CONDITION = '(board/id=45 or board/id=3)';

$pdo = relationships_db();
$user = relationships_require_login($pdo);

$action = $_GET['action'] ?? '';

/**
 * Batch local lookups for a page of project ids -- one query per table
 * covering every id on screen at once, rather than one query per row.
 */
function relationships_projects_in_clause(array $ids): string
{
    return implode(',', array_fill(0, count($ids), '?'));
}

function relationships_projects_assignments_for(PDO $pdo, array $ids): array
{
    if ($ids === []) {
        return [];
    }
    $stmt = $pdo->prepare(
        'SELECT cw_project_id, assigned_to_name FROM project_assignments WHERE cw_project_id IN (' .
        relationships_projects_in_clause($ids) . ')'
    );
    $stmt->execute($ids);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[(int) $row['cw_project_id']] = $row['assigned_to_name'];
    }
    return $out;
}

function relationships_projects_checklist_for(PDO $pdo, array $ids): array
{
    if ($ids === []) {
        return [];
    }
    $stmt = $pdo->prepare(
        'SELECT cw_project_id, step_number, completed_at, completed_by_name FROM project_checklist_progress
         WHERE cw_project_id IN (' . relationships_projects_in_clause($ids) . ')'
    );
    $stmt->execute($ids);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $pid = (int) $row['cw_project_id'];
        if (!isset($out[$pid])) {
            $out[$pid] = [];
        }
        $out[$pid][(int) $row['step_number']] = [
            'completed_at' => $row['completed_at'],
            'completed_by_name' => $row['completed_by_name'],
        ];
    }
    return $out;
}

function relationships_projects_kickoff_dates_for(PDO $pdo, array $ids): array
{
    if ($ids === []) {
        return [];
    }
    $stmt = $pdo->prepare(
        'SELECT cw_project_id, kickoff_date FROM project_kickoff_dates WHERE cw_project_id IN (' .
        relationships_projects_in_clause($ids) . ')'
    );
    $stmt->execute($ids);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[(int) $row['cw_project_id']] = $row['kickoff_date'];
    }
    return $out;
}

/**
 * Mirrors contact-card.php's relationships_cw_contacts_extract_phone() /
 * connectwise-contacts-sync-core.php's relationships_cw_contacts_extract_email()
 * -- same communicationItems shape, duplicated per this codebase's
 * existing convention of a small per-file copy of this exact lookup
 * rather than a shared helper.
 */
function relationships_projects_extract_email(array $contact): ?string
{
    $items = $contact['communicationItems'] ?? [];
    if (!is_array($items)) {
        return null;
    }
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $typeName = is_array($item['type'] ?? null) ? (string) ($item['type']['name'] ?? '') : (string) ($item['type'] ?? '');
        if (stripos($typeName, 'email') !== false && !empty($item['value'])) {
            return (string) $item['value'];
        }
    }
    return null;
}

function relationships_projects_extract_phone(array $contact): ?string
{
    $items = $contact['communicationItems'] ?? [];
    if (!is_array($items)) {
        return null;
    }
    foreach (['direct', 'mobile', 'cell', 'phone'] as $needle) {
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $typeName = is_array($item['type'] ?? null) ? (string) ($item['type']['name'] ?? '') : (string) ($item['type'] ?? '');
            if (stripos($typeName, $needle) !== false && !empty($item['value'])) {
                return (string) $item['value'];
            }
        }
    }
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $typeName = is_array($item['type'] ?? null) ? (string) ($item['type']['name'] ?? '') : (string) ($item['type'] ?? '');
        if (stripos($typeName, 'email') === false && stripos($typeName, 'fax') === false && !empty($item['value'])) {
            return (string) $item['value'];
        }
    }
    return null;
}

/**
 * Added 2026-10-02 per Michael's follow-up: "By default, closed projects
 * should not show in the list." A project's embedded `status` reference
 * only reliably carries id/name (confirmed in the original live probe --
 * see claude/relationships-projects-module-plan.md), not whether that
 * status counts as closed, so this does one extra GET /project/statuses
 * (the real ProjectStatus entity, which does carry `closedFlag`) and maps
 * id -> closedFlag. Fails open: if this call errors for any reason, every
 * status falls back to a plain name-contains-"closed" guess rather than
 * losing the whole list over it.
 */
function relationships_projects_status_closed_map(): array
{
    try {
        $statuses = relationships_cw_request('/project/statuses', ['pageSize' => '200']);
    } catch (RelationshipsConnectWiseError $e) {
        return [];
    }
    $out = [];
    foreach ($statuses as $s) {
        if (isset($s['id'])) {
            $out[(int) $s['id']] = !empty($s['closedFlag']);
        }
    }
    return $out;
}

if ($action === 'list') {
    try {
        $projects = relationships_cw_request('/project/projects', [
            'conditions' => RELATIONSHIPS_PROJECTS_BOARD_CONDITION,
            'pageSize' => '200',
            'orderBy' => 'estimatedStart desc',
        ]);
    } catch (RelationshipsConnectWiseError $e) {
        relationships_respond(502, ['ok' => false, 'error' => $e->getMessage()]);
    }

    $ids = [];
    foreach ($projects as $p) {
        $id = (int) ($p['id'] ?? 0);
        if ($id > 0) {
            $ids[] = $id;
        }
    }

    $assignments = relationships_projects_assignments_for($pdo, $ids);
    $checklist = relationships_projects_checklist_for($pdo, $ids);
    $kickoffDates = relationships_projects_kickoff_dates_for($pdo, $ids);
    $statusClosedById = relationships_projects_status_closed_map();

    $out = [];
    foreach ($projects as $p) {
        $id = (int) ($p['id'] ?? 0);
        $statusId = isset($p['status']['id']) ? (int) $p['status']['id'] : null;
        $statusName = $p['status']['name'] ?? '';
        $isClosed = $statusId !== null && isset($statusClosedById[$statusId])
            ? $statusClosedById[$statusId]
            : (stripos($statusName, 'closed') !== false);
        $out[] = [
            'id' => $id,
            'company_name' => $p['company']['name'] ?? '',
            'name' => $p['name'] ?? '',
            'status_name' => $statusName,
            'status_id' => $statusId,
            'is_closed' => $isClosed,
            'board_id' => $p['board']['id'] ?? null,
            'board_name' => $p['board']['name'] ?? '',
            'start_date' => $p['estimatedStart'] ?? null,
            'contact_id' => isset($p['contact']['id']) ? (string) $p['contact']['id'] : null,
            'contact_name' => $p['contact']['name'] ?? null,
            'assigned_to_name' => $assignments[$id] ?? null,
            'checklist' => $checklist[$id] ?? [],
            'kickoff_date' => $kickoffDates[$id] ?? null,
        ];
    }
    relationships_respond(200, ['ok' => true, 'projects' => $out, 'roster' => relationships_todo_roster()]);
}

if ($action === 'assign' || $action === 'unassign') {
    $data = relationships_read_json_body();
    $projectId = (int) ($data['project_id'] ?? 0);
    if ($projectId <= 0) {
        relationships_respond(400, ['ok' => false, 'error' => 'Missing/invalid project_id.']);
    }

    if ($action === 'assign') {
        $assignedToName = trim((string) ($data['assigned_to_name'] ?? ''));
        $roster = relationships_todo_roster();
        if (!in_array($assignedToName, $roster, true)) {
            relationships_respond(400, ['ok' => false, 'error' => 'assigned_to_name must be one of the roster names.']);
        }
        // LOCAL bookkeeping only -- resolves a real crc_users row when
        // that roster member has registered a Relationships login, same
        // as risk-scans.php's identical 'assign' action; null is fine,
        // nothing downstream requires it.
        $assigneeUserStmt = $pdo->prepare('SELECT id FROM crc_users WHERE LOWER(name) = LOWER(:name) LIMIT 1');
        $assigneeUserStmt->execute([':name' => $assignedToName]);
        $assigneeUserId = $assigneeUserStmt->fetchColumn();

        $pdo->prepare(
            "INSERT OR REPLACE INTO project_assignments (cw_project_id, assigned_to_user_id, assigned_to_name, assigned_at)
             VALUES (:id, :uid, :uname, datetime('now'))"
        )->execute([
            ':id' => $projectId,
            ':uid' => $assigneeUserId !== false ? (int) $assigneeUserId : null,
            ':uname' => $assignedToName,
        ]);
    } else {
        $pdo->prepare('DELETE FROM project_assignments WHERE cw_project_id = :id')->execute([':id' => $projectId]);
    }
    relationships_respond(200, ['ok' => true]);
}

if ($action === 'checklist_toggle') {
    $data = relationships_read_json_body();
    $projectId = (int) ($data['project_id'] ?? 0);
    $stepNumber = (int) ($data['step_number'] ?? 0);
    $completed = !empty($data['completed']);
    if ($projectId <= 0 || $stepNumber < 1 || $stepNumber > 5) {
        relationships_respond(400, ['ok' => false, 'error' => 'Invalid project_id/step_number.']);
    }

    // Delete-then-insert, same pattern as checklist.php's 'set' action --
    // the PRIMARY KEY on (cw_project_id, step_number) guarantees at most
    // one row either way.
    $pdo->prepare('DELETE FROM project_checklist_progress WHERE cw_project_id = :p AND step_number = :s')
        ->execute([':p' => $projectId, ':s' => $stepNumber]);
    if ($completed) {
        $pdo->prepare(
            "INSERT INTO project_checklist_progress (cw_project_id, step_number, completed_at, completed_by_user_id, completed_by_name)
             VALUES (:p, :s, datetime('now'), :uid, :uname)"
        )->execute([':p' => $projectId, ':s' => $stepNumber, ':uid' => $user['id'], ':uname' => $user['name']]);
    }
    relationships_respond(200, ['ok' => true]);
}

if ($action === 'kickoff_date_set') {
    $data = relationships_read_json_body();
    $projectId = (int) ($data['project_id'] ?? 0);
    $date = trim((string) ($data['kickoff_date'] ?? ''));
    if ($projectId <= 0) {
        relationships_respond(400, ['ok' => false, 'error' => 'Missing/invalid project_id.']);
    }
    if ($date === '') {
        $pdo->prepare('DELETE FROM project_kickoff_dates WHERE cw_project_id = :p')->execute([':p' => $projectId]);
    } else {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            relationships_respond(400, ['ok' => false, 'error' => 'kickoff_date must be YYYY-MM-DD.']);
        }
        $pdo->prepare(
            "INSERT OR REPLACE INTO project_kickoff_dates (cw_project_id, kickoff_date, set_by_user_id, set_by_name, set_at)
             VALUES (:p, :d, :uid, :uname, datetime('now'))"
        )->execute([':p' => $projectId, ':d' => $date, ':uid' => $user['id'], ':uname' => $user['name']]);
    }
    relationships_respond(200, ['ok' => true]);
}

if ($action === 'contact_info') {
    $contactId = trim((string) ($_GET['contact_id'] ?? ''));
    if ($contactId === '') {
        relationships_respond(400, ['ok' => false, 'error' => 'Missing contact_id.']);
    }
    try {
        $contact = relationships_cw_request('/company/contacts/' . rawurlencode($contactId), [
            'fields' => 'firstName,lastName,communicationItems',
        ]);
    } catch (RelationshipsConnectWiseError $e) {
        relationships_respond(502, ['ok' => false, 'error' => $e->getMessage()]);
    }
    relationships_respond(200, [
        'ok' => true,
        'email' => relationships_projects_extract_email($contact),
        'phone' => relationships_projects_extract_phone($contact),
    ]);
}

if ($action === 'notes_list') {
    $projectId = (int) ($_GET['project_id'] ?? 0);
    if ($projectId <= 0) {
        relationships_respond(400, ['ok' => false, 'error' => 'Missing/invalid project_id.']);
    }
    try {
        $notes = relationships_cw_request("/project/projects/$projectId/notes", [
            'pageSize' => '50',
            'orderBy' => 'id desc',
        ]);
        $out = array_map(static function (array $n): array {
            return [
                'id' => $n['id'] ?? null,
                'text' => $n['text'] ?? '',
                'type_name' => $n['type']['name'] ?? '',
                'updated_by' => $n['_info']['updatedBy'] ?? '',
                'last_updated' => $n['_info']['lastUpdated'] ?? null,
            ];
        }, $notes);
        relationships_respond(200, ['ok' => true, 'notes' => $out, 'source' => 'connectwise']);
    } catch (RelationshipsConnectWiseError $e) {
        // Fail open -- show the local audit log instead of erroring the
        // whole notes panel over a transient ConnectWise/network problem.
        $stmt = $pdo->prepare(
            'SELECT note_text, created_by_name, created_at FROM project_notes
             WHERE cw_project_id = :p ORDER BY created_at DESC, id DESC LIMIT 50'
        );
        $stmt->execute([':p' => $projectId]);
        $local = $stmt->fetchAll(PDO::FETCH_ASSOC);
        relationships_respond(200, [
            'ok' => true,
            'notes' => array_map(static function (array $n): array {
                return [
                    'id' => null,
                    'text' => $n['note_text'],
                    'type_name' => 'Comment',
                    'updated_by' => $n['created_by_name'],
                    'last_updated' => $n['created_at'],
                ];
            }, $local),
            'source' => 'local',
            'cw_error' => $e->getMessage(),
        ]);
    }
}

if ($action === 'notes_add') {
    $data = relationships_read_json_body();
    $projectId = (int) ($data['project_id'] ?? 0);
    $noteText = trim((string) ($data['note_text'] ?? ''));
    if ($projectId <= 0 || $noteText === '') {
        relationships_respond(400, ['ok' => false, 'error' => 'Missing project_id/note_text.']);
    }

    // Written FIRST, before the ConnectWise push is even attempted -- a
    // coordinator's note is never lost locally regardless of what
    // ConnectWise does with it next.
    $ins = $pdo->prepare(
        'INSERT INTO project_notes (cw_project_id, note_text, created_by_user_id, created_by_name)
         VALUES (:p, :t, :uid, :uname)'
    );
    $ins->execute([':p' => $projectId, ':t' => $noteText, ':uid' => $user['id'], ':uname' => $user['name']]);
    $localId = (int) $pdo->lastInsertId();

    // ConnectWise's own `updatedBy` always stamps this integration's
    // shared API member ("Clyde"), never the real coordinator -- confirmed
    // live (project 1333, note id 4047). The coordinator's name goes into
    // the note TEXT itself instead, since that's the only place it will
    // actually be visible to anyone reading the note in ConnectWise.
    $cwText = '[' . $user['name'] . '] ' . $noteText;
    try {
        $created = relationships_cw_request("/project/projects/$projectId/notes", [], 'POST', [
            'text' => $cwText,
            'type' => ['id' => 2], // "Comment" -- confirmed live, see plan doc
        ]);
        $cwNoteId = isset($created['id']) ? (string) $created['id'] : null;
        $pdo->prepare("UPDATE project_notes SET cw_note_id = :nid, cw_push_status = 'pushed' WHERE id = :id")
            ->execute([':nid' => $cwNoteId, ':id' => $localId]);
        relationships_respond(200, ['ok' => true, 'cw_note_id' => $cwNoteId]);
    } catch (RelationshipsConnectWiseError $e) {
        $pdo->prepare("UPDATE project_notes SET cw_push_status = 'failed', cw_push_error = :err WHERE id = :id")
            ->execute([':err' => $e->getMessage(), ':id' => $localId]);
        relationships_respond(200, [
            'ok' => true,
            'cw_warning' => 'Saved here, but ConnectWise did not accept the note: ' . $e->getMessage(),
        ]);
    }
}

relationships_respond(400, ['ok' => false, 'error' => 'Unknown action.']);
