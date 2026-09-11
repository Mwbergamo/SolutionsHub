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
require_once __DIR__ . '/connectwise-activity-create.php';

$pdo = relationships_db();
$user = relationships_require_login($pdo);

$action = $_GET['action'] ?? '';

if ($action === 'get') {
    $customerId = (int) ($_GET['customer_id'] ?? 0);
    $pillarId = (string) ($_GET['pillar_id'] ?? '');
    $serviceId = (string) ($_GET['service_id'] ?? '');
    if ($customerId <= 0 || $pillarId === '' || $serviceId === '') {
        relationships_respond(400, ['ok' => false, 'error' => 'Missing customer_id/pillar_id/service_id.']);
    }

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
    relationships_respond(200, ['ok' => true, 'steps' => $steps]);
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

if ($action === 'cw_date_probe') {
    // One-off diagnostic -- added 2026-09-11 after TWO guessed date formats
    // for the Activity create (ATOM with offset, then UTC+milliseconds+Z
    // per the docs PDF's response example) both got rejected by a real
    // POST /sales/activities with {"code":"UnsupportedFormat",...}. Rather
    // than guess a third time, fetch a handful of Activities that already
    // exist in CodeBlue's live ConnectWise and show their dateStart/dateEnd
    // fields exactly as ConnectWise itself returns them -- real ground
    // truth from this specific on-prem v4_6_release server, not a docs page
    // that may describe a newer cloud API version's expected format.
    // Read-only; same "just visit the URL, already-logged-in session covers
    // auth" pattern as ?action=cw_log. Remove once the date format is
    // confirmed fixed and no longer needed for debugging.
    try {
        $rows = relationships_cw_request('/sales/activities', [
            'fields' => 'id,name,dateStart,dateEnd,dateEndUtc,dateClosedUtc',
            'pageSize' => 5,
            'orderBy' => 'id desc',
        ]);
        relationships_respond(200, ['ok' => true, 'rows' => $rows]);
    } catch (Throwable $e) {
        relationships_respond(200, ['ok' => false, 'error' => $e->getMessage()]);
    }
}

if ($action === 'summary' || $action === 'queue') {
    $rows = relationships_missing_services_with_progress($pdo);

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
function relationships_missing_services_with_progress(PDO $pdo): array
{
    $catalog = relationships_catalog();
    $customers = $pdo->query('SELECT id, name, voip_hosted_elsewhere FROM customers ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);

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
