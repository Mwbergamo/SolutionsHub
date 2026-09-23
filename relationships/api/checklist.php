<?php
/**
 * relationships/api/checklist.php
 *
 * The 7-step cross-sell checklist (relationships_checklist_steps() in
 * catalog.php) for a customer's missing services, plus the step-queue
 * reporting view: how many customers are currently sitting at each step,
 * per service, and who they are.
 *
 * A customer/pillar/service's "pending step" is simply
 * (count of completed steps) + 1 -- there's no separate status column,
 * the steps are strictly ordered, and a row in checklist_progress only
 * exists once a step has actually been checked off (see db.php's
 * migration comment). A service with all 7 steps done is "closed" rather
 * than pending anything -- CodeBlue's process re-addresses it after 180
 * days, but nothing here auto-resets that; it's a manual re-open if/when
 * that phase gets built.
 *
 * Only the services relationships_is_cross_sell_eligible() (catalog.php)
 * says yes to are tracked here at all -- 'set' rejects any other service,
 * and 'summary'/'queue' never include one. Everything else in the catalog
 * still shows as missing in the customer detail (customers.php), it just
 * isn't pushed through this blanket checklist/marketing mechanism.
 *
 * GET  /relationships/api/checklist.php?action=get&customer_id=1&pillar_id=it&service_id=vcio
 *   -> { ok: true, steps: [{ step_number, label, completed, completed_at, completed_by_name }, ...] }  (7 entries)
 *
 * POST /relationships/api/checklist.php?action=set
 *   { customer_id, pillar_id, service_id, service_name, step_number, completed }
 *   -> { ok: true }
 *
 * GET  /relationships/api/checklist.php?action=summary
 *   -> { ok: true, rows: [{ pillar_id, pillar_name, service_id, service_name,
 *                            total, closed, steps: { "1": n, ..., "7": n } }, ...] }
 *
 * GET  /relationships/api/checklist.php?action=queue&pillar_id=it&service_id=vcio&step=4
 *   (step is 1-7, or the literal string "closed")
 *   -> { ok: true, customers: [{ customer_id, customer_name }, ...] }
 */

declare(strict_types=1);

require_once __DIR__ . '/_util.php';
require_once __DIR__ . '/territory-access.php';
require_once __DIR__ . '/connectwise-activity-create.php';

$pdo = relationships_db();
$user = relationships_require_login($pdo);
$allowedTerritories = relationships_allowed_territories($pdo);

$action = $_GET['action'] ?? '';

/**
 * Whether a (customer, pillar, service) triple has been marked "Kill
 * Opportunity" -- see the 'kill' action below. Shared by the 'get' action
 * (so the checklist panel can show the killed state) and
 * relationships_missing_services_with_progress() (so a killed opportunity
 * drops out of the Cross-Sell Report roster entirely).
 */
function relationships_is_cross_sell_killed(PDO $pdo, int $customerId, string $pillarId, string $serviceId): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1 FROM cross_sell_killed WHERE customer_id = :c AND pillar_id = :p AND service_id = :s'
    );
    $stmt->execute([':c' => $customerId, ':p' => $pillarId, ':s' => $serviceId]);
    return $stmt->fetchColumn() !== false;
}

if ($action === 'get') {
    $customerId = (int) ($_GET['customer_id'] ?? 0);
    $pillarId = (string) ($_GET['pillar_id'] ?? '');
    $serviceId = (string) ($_GET['service_id'] ?? '');
    if ($customerId <= 0 || $pillarId === '' || $serviceId === '') {
        relationships_respond(400, ['ok' => false, 'error' => 'Missing customer_id/pillar_id/service_id.']);
    }
    relationships_require_territory_scope($allowedTerritories, relationships_customer_territory($pdo, $customerId));

    $stmt = $pdo->prepare(
        'SELECT step_number, completed_at, completed_by_name FROM checklist_progress
         WHERE customer_id = :c AND pillar_id = :p AND service_id = :s'
    );
    $stmt->execute([':c' => $customerId, ':p' => $pillarId, ':s' => $serviceId]);
    $byStep = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $byStep[(int) $r['step_number']] = $r;
    }

    $steps = [];
    foreach (relationships_checklist_steps() as $num => $label) {
        $row = $byStep[$num] ?? null;
        $steps[] = [
            'step_number' => $num,
            'label' => $label,
            'completed' => $row !== null,
            'completed_at' => $row['completed_at'] ?? null,
            'completed_by_name' => $row['completed_by_name'] ?? null,
        ];
    }
    relationships_respond(200, [
        'ok' => true,
        'steps' => $steps,
        'killed' => relationships_is_cross_sell_killed($pdo, $customerId, $pillarId, $serviceId),
    ]);
}

if ($action === 'set') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        relationships_respond(405, ['ok' => false, 'error' => 'Method not allowed.']);
    }
    $data = relationships_read_json_body();
    $customerId = (int) ($data['customer_id'] ?? 0);
    $pillarId = (string) ($data['pillar_id'] ?? '');
    $serviceId = (string) ($data['service_id'] ?? '');
    $serviceName = (string) ($data['service_name'] ?? '');
    $stepNumber = (int) ($data['step_number'] ?? 0);
    $completed = !empty($data['completed']);

    $steps = relationships_checklist_steps();
    if ($customerId <= 0 || $pillarId === '' || $serviceId === '' || !isset($steps[$stepNumber])) {
        relationships_respond(400, ['ok' => false, 'error' => 'Invalid checklist step.']);
    }
    relationships_require_territory_scope($allowedTerritories, relationships_customer_territory($pdo, $customerId));
    if (!relationships_is_cross_sell_eligible($pillarId, $serviceId)) {
        relationships_respond(400, ['ok' => false, 'error' => 'This service is not tracked for cross-sell.']);
    }

    // Delete-then-insert rather than an UPSERT: simpler, and the UNIQUE
    // constraint on (customer_id, pillar_id, service_id, step_number)
    // already guarantees at most one row either way. $wasCompleted (was
    // there already a row for this step before the delete?) is what tells
    // the ConnectWise Activity trigger below apart a genuine new completion
    // from a redundant "set completed=true" on a step that was already
    // done -- added 2026-09-11, see connectwise-activity-create.php.
    $del = $pdo->prepare(
        'DELETE FROM checklist_progress WHERE customer_id = :c AND pillar_id = :p AND service_id = :s AND step_number = :step'
    );
    $del->execute([':c' => $customerId, ':p' => $pillarId, ':s' => $serviceId, ':step' => $stepNumber]);
    $wasCompleted = $del->rowCount() > 0;

    if ($completed) {
        $ins = $pdo->prepare(
            "INSERT INTO checklist_progress
                (customer_id, pillar_id, service_id, service_name, step_number, completed_at, completed_by_user_id, completed_by_name)
             VALUES (:c, :p, :s, :sn, :step, datetime('now'), :uid, :uname)"
        );
        $ins->execute([
            ':c' => $customerId, ':p' => $pillarId, ':s' => $serviceId, ':sn' => $serviceName,
            ':step' => $stepNumber, ':uid' => $user['id'], ':uname' => $user['name'],
        ]);
    }

    // Fire the ConnectWise Activity create ONLY on a genuine unchecked ->
    // checked transition, not a redundant re-save of an already-completed
    // step -- added 2026-09-11 per Michael's cross-sell-checklist request.
    // relationships_checklist_completion_create_cw_activity() never throws
    // (every failure is caught and logged internally), so this can't turn
    // a successful local checklist save into a 500 -- the response below
    // always reflects the local save, regardless of what ConnectWise did.
    if ($completed && !$wasCompleted) {
        relationships_checklist_completion_create_cw_activity(
            $pdo, $customerId, $pillarId, $serviceId, $serviceName, $stepNumber, $user
        );
    }

    relationships_respond(200, ['ok' => true]);
}

if ($action === 'cw_log') {
    // Quick diagnostic view over checklist_cw_activity_log -- added
    // 2026-09-11 right after the first real-server test of ConnectWise
    // Activity creation (Agnihotri Cosmetic Surgery) came back with no
    // Activity created, and no easy way to see WHY without a phpMyAdmin/
    // SQLite-browser login. Any logged-in CRC can view it (read-only,
    // no secrets in these rows) -- just visit this URL directly in a
    // browser (already-signed-in session cookie covers auth); Chrome/Edge
    // render JSON readably on their own. No dedicated UI view built for
    // this yet -- revisit if this becomes a regular need rather than a
    // one-off debugging aid.
    $rows = $pdo->query(
        'SELECT l.id, l.customer_id, c.name AS customer_name, l.pillar_id, l.service_id, l.step_number,
                l.status, l.cw_activity_id, l.payload_variant, l.error_message, l.completed_by_user_id, l.created_at
         FROM checklist_cw_activity_log l
         LEFT JOIN customers c ON c.id = l.customer_id
         ORDER BY l.id DESC LIMIT 50'
    )->fetchAll(PDO::FETCH_ASSOC);
    relationships_respond(200, ['ok' => true, 'rows' => $rows]);
}

// Cross-sell step notes, and the Recycle / Kill Opportunity actions --
// added 2026-09-23 per Michael's request to add rep-entered notes per
// step, a contact-selection-driven outreach flow, and a way to close out
// a fully-worked opportunity (see relationships_checklist_steps() -- step
// 7 is "Close-Out -- Re-Address in 180 Days"). All three actions below
// share the same territory/eligibility guards as 'get'/'set' above.

if ($action === 'notes_get') {
    $customerId = (int) ($_GET['customer_id'] ?? 0);
    $pillarId = (string) ($_GET['pillar_id'] ?? '');
    $serviceId = (string) ($_GET['service_id'] ?? '');
    if ($customerId <= 0 || $pillarId === '' || $serviceId === '') {
        relationships_respond(400, ['ok' => false, 'error' => 'Missing customer_id/pillar_id/service_id.']);
    }
    relationships_require_territory_scope($allowedTerritories, relationships_customer_territory($pdo, $customerId));

    $stmt = $pdo->prepare(
        'SELECT id, step_number, note_text, created_by_name, created_at FROM checklist_step_notes
         WHERE customer_id = :c AND pillar_id = :p AND service_id = :s
         ORDER BY created_at DESC, id DESC'
    );
    $stmt->execute([':c' => $customerId, ':p' => $pillarId, ':s' => $serviceId]);
    relationships_respond(200, ['ok' => true, 'notes' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

if ($action === 'notes_add') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        relationships_respond(405, ['ok' => false, 'error' => 'Method not allowed.']);
    }
    $data = relationships_read_json_body();
    $customerId = (int) ($data['customer_id'] ?? 0);
    $pillarId = (string) ($data['pillar_id'] ?? '');
    $serviceId = (string) ($data['service_id'] ?? '');
    $stepNumber = (int) ($data['step_number'] ?? 0);
    $noteText = trim((string) ($data['note_text'] ?? ''));
    $steps = relationships_checklist_steps();
    if ($customerId <= 0 || $pillarId === '' || $serviceId === '' || !isset($steps[$stepNumber]) || $noteText === '') {
        relationships_respond(400, ['ok' => false, 'error' => 'Missing or invalid note fields.']);
    }
    relationships_require_territory_scope($allowedTerritories, relationships_customer_territory($pdo, $customerId));

    $ins = $pdo->prepare(
        'INSERT INTO checklist_step_notes (customer_id, pillar_id, service_id, step_number, note_text, created_by_user_id, created_by_name)
         VALUES (:c, :p, :s, :step, :note, :uid, :uname)'
    );
    $ins->execute([
        ':c' => $customerId, ':p' => $pillarId, ':s' => $serviceId, ':step' => $stepNumber,
        ':note' => $noteText, ':uid' => $user['id'], ':uname' => $user['name'],
    ]);
    relationships_respond(200, ['ok' => true]);
}

if ($action === 'recycle') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        relationships_respond(405, ['ok' => false, 'error' => 'Method not allowed.']);
    }
    $data = relationships_read_json_body();
    $customerId = (int) ($data['customer_id'] ?? 0);
    $pillarId = (string) ($data['pillar_id'] ?? '');
    $serviceId = (string) ($data['service_id'] ?? '');
    if ($customerId <= 0 || $pillarId === '' || $serviceId === '') {
        relationships_respond(400, ['ok' => false, 'error' => 'Missing customer_id/pillar_id/service_id.']);
    }
    relationships_require_territory_scope($allowedTerritories, relationships_customer_territory($pdo, $customerId));
    if (!relationships_is_cross_sell_eligible($pillarId, $serviceId)) {
        relationships_respond(400, ['ok' => false, 'error' => 'This service is not tracked for cross-sell.']);
    }

    // Reset back to Step 1 -- delete-then-recompute, same idiom as every
    // other reset in this app (is_peoplefirst, is_prospect_only, etc.).
    // No automatic 180-day timer anywhere -- a rep does this manually once
    // they judge the cycle has run its course, per Michael's own wording
    // ("Recycle in 180 days") describing WHEN a rep should press it, not a
    // scheduled job.
    $del = $pdo->prepare('DELETE FROM checklist_progress WHERE customer_id = :c AND pillar_id = :p AND service_id = :s');
    $del->execute([':c' => $customerId, ':p' => $pillarId, ':s' => $serviceId]);

    // A quiet audit note, filed the same way a rep's own note would be --
    // so "who recycled this and when" shows up right in the notes feed
    // instead of needing a separate log table just for this one event.
    $note = $pdo->prepare(
        'INSERT INTO checklist_step_notes (customer_id, pillar_id, service_id, step_number, note_text, created_by_user_id, created_by_name)
         VALUES (:c, :p, :s, 1, :note, :uid, :uname)'
    );
    $note->execute([
        ':c' => $customerId, ':p' => $pillarId, ':s' => $serviceId,
        ':note' => 'Recycled — restarting the 180-day outreach cycle at Step 1.',
        ':uid' => $user['id'], ':uname' => $user['name'],
    ]);

    relationships_respond(200, ['ok' => true]);
}

if ($action === 'kill') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        relationships_respond(405, ['ok' => false, 'error' => 'Method not allowed.']);
    }
    $data = relationships_read_json_body();
    $customerId = (int) ($data['customer_id'] ?? 0);
    $pillarId = (string) ($data['pillar_id'] ?? '');
    $serviceId = (string) ($data['service_id'] ?? '');
    $killed = !empty($data['killed']);
    if ($customerId <= 0 || $pillarId === '' || $serviceId === '') {
        relationships_respond(400, ['ok' => false, 'error' => 'Missing customer_id/pillar_id/service_id.']);
    }
    relationships_require_territory_scope($allowedTerritories, relationships_customer_territory($pdo, $customerId));
    if (!relationships_is_cross_sell_eligible($pillarId, $serviceId)) {
        relationships_respond(400, ['ok' => false, 'error' => 'This service is not tracked for cross-sell.']);
    }

    // Delete-then-insert, same as everywhere else in this file -- also
    // doubles as the "un-kill" path: kill:false just leaves the row
    // deleted. Reversible on purpose -- a rep marking something dead by
    // mistake shouldn't need a database fix to undo it.
    $del = $pdo->prepare('DELETE FROM cross_sell_killed WHERE customer_id = :c AND pillar_id = :p AND service_id = :s');
    $del->execute([':c' => $customerId, ':p' => $pillarId, ':s' => $serviceId]);

    if ($killed) {
        $ins = $pdo->prepare(
            'INSERT INTO cross_sell_killed (customer_id, pillar_id, service_id, killed_by_user_id, killed_by_name)
             VALUES (:c, :p, :s, :uid, :uname)'
        );
        $ins->execute([':c' => $customerId, ':p' => $pillarId, ':s' => $serviceId, ':uid' => $user['id'], ':uname' => $user['name']]);
    }

    relationships_respond(200, ['ok' => true, 'killed' => $killed]);
}

if ($action === 'summary' || $action === 'queue') {
    $rows = relationships_missing_services_with_progress($pdo, $allowedTerritories);

    if ($action === 'summary') {
        $byKey = [];
        foreach ($rows as $r) {
            $key = $r['pillar_id'] . '::' . $r['service_id'];
            if (!isset($byKey[$key])) {
                $byKey[$key] = [
                    'pillar_id' => $r['pillar_id'], 'pillar_name' => $r['pillar_name'],
                    'service_id' => $r['service_id'], 'service_name' => $r['service_name'],
                    'total' => 0, 'closed' => 0,
                    'steps' => ['1' => 0, '2' => 0, '3' => 0, '4' => 0, '5' => 0, '6' => 0, '7' => 0],
                ];
            }
            $byKey[$key]['total']++;
            if ($r['pending_step'] === null) {
                $byKey[$key]['closed']++;
            } else {
                $byKey[$key]['steps'][(string) $r['pending_step']]++;
            }
        }
        relationships_respond(200, ['ok' => true, 'rows' => array_values($byKey)]);
    }

    // action === 'queue'
    $pillarId = (string) ($_GET['pillar_id'] ?? '');
    $serviceId = (string) ($_GET['service_id'] ?? '');
    $step = (string) ($_GET['step'] ?? '');
    if ($pillarId === '' || $serviceId === '' || $step === '') {
        relationships_respond(400, ['ok' => false, 'error' => 'Missing pillar_id/service_id/step.']);
    }

    $matches = array_values(array_filter($rows, function (array $r) use ($pillarId, $serviceId, $step): bool {
        if ($r['pillar_id'] !== $pillarId || $r['service_id'] !== $serviceId) {
            return false;
        }
        return $step === 'closed' ? $r['pending_step'] === null : $r['pending_step'] === (int) $step;
    }));

    $customers = array_map(
        static fn (array $r): array => ['customer_id' => $r['customer_id'], 'customer_name' => $r['customer_name']],
        $matches
    );
    relationships_respond(200, ['ok' => true, 'pillar_id' => $pillarId, 'service_id' => $serviceId, 'step' => $step, 'customers' => $customers]);
}

relationships_respond(400, ['ok' => false, 'error' => 'Unknown action.']);

/**
 * Every (customer, missing service) pair, with how many checklist steps
 * are done and which step is next. Recomputed per request rather than
 * cached -- fine at mock-data scale (a handful of customers x ~30
 * services); if that stops being true once real ConnectWise data is
 * wired in, this is the place to push the aggregation into SQL instead.
 */
function relationships_missing_services_with_progress(PDO $pdo, ?array $allowedTerritories = null): array
{
    $catalog = relationships_catalog();
    // Rep-based territory filtering (see territory-access.php) -- this
    // feeds both the Cross-Sell Report summary and its drill-down queue,
    // so a restricted rep only ever sees their own customers in either.
    $territoryFilter = $allowedTerritories === null
        ? ['sql' => '', 'params' => []]
        : relationships_territory_filter_sql($allowedTerritories, 'customers');
    $customerStmt = $pdo->prepare(
        "SELECT id, name, voip_hosted_elsewhere FROM customers WHERE 1=1 {$territoryFilter['sql']} ORDER BY name ASC"
    );
    $customerStmt->execute($territoryFilter['params']);
    $customers = $customerStmt->fetchAll(PDO::FETCH_ASSOC);

    $activeSet = [];
    foreach ($pdo->query('SELECT DISTINCT customer_id, pillar_id, service_id FROM customer_services') as $r) {
        $activeSet[$r['customer_id'] . '::' . $r['pillar_id'] . '::' . $r['service_id']] = true;
    }

    $progressMap = [];
    $progressStmt = $pdo->query(
        'SELECT customer_id, pillar_id, service_id, COUNT(*) AS completed_count
         FROM checklist_progress GROUP BY customer_id, pillar_id, service_id'
    );
    foreach ($progressStmt as $r) {
        $progressMap[$r['customer_id'] . '::' . $r['pillar_id'] . '::' . $r['service_id']] = (int) $r['completed_count'];
    }

    // "Kill Opportunity" -- added 2026-09-23, see the 'kill' action above.
    // A killed (customer, pillar, service) never appears in the Cross-Sell
    // Report roster or its step-queue drill-down, same exclusion shape as
    // activeSet just above (already has the service -- nothing to sell)
    // and the voip_hosted_elsewhere skip below (won't market phones there).
    $killedSet = [];
    foreach ($pdo->query('SELECT customer_id, pillar_id, service_id FROM cross_sell_killed') as $r) {
        $killedSet[$r['customer_id'] . '::' . $r['pillar_id'] . '::' . $r['service_id']] = true;
    }

    $rows = [];
    foreach ($customers as $cust) {
        // Manufacturer-hosted voice platform (Zultys Hosted, etc.) -- an
        // empty ConnectWise Voice Agreement CBT isn't selling against, so
        // this customer never gets a VoIP/phone cross-sell prompt. Same
        // override as customers.php's detail action.
        $voipHostedElsewhere = (bool) $cust['voip_hosted_elsewhere'];
        foreach ($catalog as $pillarId => $pillarDef) {
            if ($pillarId === 'voip' && $voipHostedElsewhere) {
                continue;
            }
            foreach ($pillarDef['services'] as $serviceId => $serviceName) {
                if (!relationships_is_cross_sell_eligible($pillarId, $serviceId)) {
                    continue; // not part of the blanket cross-sell push
                }
                $key = $cust['id'] . '::' . $pillarId . '::' . $serviceId;
                if (isset($activeSet[$key])) {
                    continue; // customer already has this service -- nothing to cross-sell
                }
                if (isset($killedSet[$key])) {
                    continue; // rep marked this opportunity dead -- see 'kill' action above
                }
                $completed = $progressMap[$key] ?? 0;
                $rows[] = [
                    'customer_id' => (int) $cust['id'],
                    'customer_name' => $cust['name'],
                    'pillar_id' => $pillarId,
                    'pillar_name' => $pillarDef['name'],
                    'service_id' => $serviceId,
                    'service_name' => $serviceName,
                    'completed_steps' => $completed,
                    'pending_step' => $completed >= 7 ? null : $completed + 1,
                ];
            }
        }
    }
    return $rows;
}
