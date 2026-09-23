<?php
/**
 * relationships/api/outgrow.php
 *
 * "OutGrow Last Touch" tracking for a customer -- added 2026-09-15 per
 * Michael. A CRC-editable date, shown on that customer's dashboard, with a
 * full append-only history (who set it to what, and when they set it) --
 * unlike PeopleFirst's checkin/scan fields (peoplefirst.php), which only
 * ever keep the single most recent value. Every update also tries to push
 * the same date into ConnectWise's "OutGrow Last Touch" Company custom
 * field -- see connectwise-outgrow.php for that half, including the real
 * uncertainty around its exact field/PATCH mechanics.
 *
 * GET  /relationships/api/outgrow.php?action=get&customer_id=1
 *   -> { ok: true,
 *        current: { touch_date, set_by_name, source, created_at } | null,
 *        history: [ { id, touch_date, set_by_name, source, cw_push_status,
 *                      cw_push_error, created_at }, ... ] (newest first) }
 *
 *   Before reading, this ALSO does the one-time ConnectWise backfill Michael
 *   asked for ("for any customer with a Last Touch date already in
 *   ConnectWise, show that as their OutGrow Last Touch and count that as
 *   their first historical entry"): if this customer has a real ConnectWise
 *   id AND zero rows in outgrow_last_touch_history yet, it does a live GET
 *   of that Company's customFields, and if ConnectWise already has a
 *   non-empty "OutGrow Last Touch" value, inserts ONE seed row (source =
 *   'connectwise_seed') before returning. Because it only ever fires when
 *   history is empty, it can only ever run once per customer -- after that,
 *   history is non-empty and this app becomes the source of truth going
 *   forward (a later edit made directly in ConnectWise, bypassing this
 *   tool, is NOT picked up automatically). A failed backfill attempt
 *   (ConnectWise unreachable, field not found, etc.) is swallowed --
 *   this action still returns whatever local history exists rather than
 *   erroring the whole customer dashboard over a best-effort read.
 *
 * POST /relationships/api/outgrow.php?action=set
 *   { customer_id, touch_date: "YYYY-MM-DD", source?: "manual"|"call"|"email" }
 *   -> { ok: true, current: {...}, history: [...], cw_push: { attempted, status, error } }
 *
 *   Always saves locally first (a new history row -- source defaults to
 *   'manual', or 'call'/'email' when this came from the Relationships
 *   contact card's confirm-after-tap flow (added 2026-09-23, per Michael --
 *   see contact-card.php's file header), attributed to the signed-in CRC)
 *   -- that save can never fail because of
 *   ConnectWise. Then tries to push the same date into ConnectWise (skipped
 *   entirely, cw_push.status = 'skipped', for a mock/unsynced customer with
 *   no real ConnectWise id); the outcome is recorded on the new history row
 *   (cw_push_status/cw_push_error) and also returned directly as cw_push so
 *   the UI can show a "saved here, but didn't reach ConnectWise" warning
 *   without the CRC having to open the history list to notice.
 *
 * GET  /relationships/api/outgrow.php?action=cw_probe&customer_id=1
 *   -> { ok: true, connectwise_id, custom_fields: [ {caption, value, ...}, ... ] }
 *   Diagnostic only -- dumps this customer's real ConnectWise Company
 *   customFields so the "OutGrow Last Touch" caption/value shape can be
 *   eyeballed against a live customer before trusting the read/write above.
 *   Same one-time-use-then-delete pattern as this integration's earlier
 *   (now-removed) cw_date_probe action -- remove this once Michael has
 *   confirmed a real customer's output looks right.
 */

declare(strict_types=1);

require_once __DIR__ . '/_util.php';
require_once __DIR__ . '/territory-access.php';
require_once __DIR__ . '/connectwise-outgrow.php';

$pdo = relationships_db();
$user = relationships_require_login($pdo);
$allowedTerritories = relationships_allowed_territories($pdo);

$action = $_GET['action'] ?? '';

/**
 * This customer's connectwise_id, or null if it's a mock/unsynced customer
 * (no real ConnectWise company to read/write against) -- same "MOCK-%"
 * convention activity.php's relationships_activity_cw_id() already uses.
 */
function relationships_outgrow_cw_id(PDO $pdo, int $customerId): ?string
{
    $stmt = $pdo->prepare('SELECT connectwise_id FROM customers WHERE id = :id');
    $stmt->execute([':id' => $customerId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $cwId = $row['connectwise_id'] ?? null;
    return ($cwId === null || $cwId === '' || str_starts_with((string) $cwId, 'MOCK-')) ? null : (string) $cwId;
}

function relationships_outgrow_history(PDO $pdo, int $customerId): array
{
    $stmt = $pdo->prepare(
        'SELECT id, touch_date, source, set_by_name, cw_push_status, cw_push_error, created_at
         FROM outgrow_last_touch_history WHERE customer_id = :id ORDER BY id DESC'
    );
    $stmt->execute([':id' => $customerId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['id'] = (int) $r['id'];
    }
    unset($r);
    return $rows;
}

/**
 * One-time backfill from ConnectWise -- see file header. Never throws;
 * any failure is swallowed since this is a best-effort enrichment of the
 * 'get' response, not something that should ever break loading a
 * customer's dashboard.
 */
function relationships_outgrow_maybe_backfill(PDO $pdo, int $customerId): void
{
    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM outgrow_last_touch_history WHERE customer_id = :id');
    $countStmt->execute([':id' => $customerId]);
    if ((int) $countStmt->fetchColumn() > 0) {
        return; // already has history -- nothing to backfill
    }

    $cwId = relationships_outgrow_cw_id($pdo, $customerId);
    if ($cwId === null) {
        return; // mock/unsynced customer -- nothing in ConnectWise to read
    }

    try {
        $existing = relationships_cw_outgrow_read($cwId);
    } catch (Throwable $e) {
        return; // ConnectWise unreachable/error -- try again next 'get' call
    }
    if ($existing === null) {
        return; // ConnectWise has no value for this customer either
    }

    $pdo->prepare(
        'INSERT INTO outgrow_last_touch_history (customer_id, touch_date, source, set_by_user_id, set_by_name, cw_push_status, cw_push_error)
         VALUES (:cid, :date, \'connectwise_seed\', NULL, :by, NULL, NULL)'
    )->execute([':cid' => $customerId, ':date' => $existing, ':by' => 'Synced from ConnectWise']);
}

if ($action === 'get') {
    $customerId = (int) ($_GET['customer_id'] ?? 0);
    if ($customerId <= 0) {
        relationships_respond(400, ['ok' => false, 'error' => 'Missing customer_id.']);
    }
    relationships_require_territory_scope($allowedTerritories, relationships_customer_territory($pdo, $customerId));

    relationships_outgrow_maybe_backfill($pdo, $customerId);

    $history = relationships_outgrow_history($pdo, $customerId);
    $current = $history[0] ?? null;
    relationships_respond(200, [
        'ok' => true,
        'current' => $current !== null ? [
            'touch_date' => $current['touch_date'],
            'set_by_name' => $current['set_by_name'],
            'source' => $current['source'],
            'created_at' => $current['created_at'],
        ] : null,
        'history' => $history,
    ]);
}

if ($action === 'cw_probe') {
    $customerId = (int) ($_GET['customer_id'] ?? 0);
    if ($customerId <= 0) {
        relationships_respond(400, ['ok' => false, 'error' => 'Missing customer_id.']);
    }
    relationships_require_territory_scope($allowedTerritories, relationships_customer_territory($pdo, $customerId));
    $cwId = relationships_outgrow_cw_id($pdo, $customerId);
    if ($cwId === null) {
        relationships_respond(200, ['ok' => true, 'connectwise_id' => null, 'custom_fields' => []]);
    }
    try {
        $fields = relationships_cw_company_custom_fields($cwId);
        relationships_respond(200, ['ok' => true, 'connectwise_id' => $cwId, 'custom_fields' => $fields]);
    } catch (RelationshipsConnectWiseError $e) {
        relationships_respond(502, ['ok' => false, 'error' => $e->getMessage()]);
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    relationships_respond(405, ['ok' => false, 'error' => 'Method not allowed.']);
}

if ($action === 'set') {
    $data = relationships_read_json_body();
    $customerId = (int) ($data['customer_id'] ?? 0);
    $touchDate = (string) ($data['touch_date'] ?? '');
    if ($customerId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $touchDate)) {
        relationships_respond(400, ['ok' => false, 'error' => 'Missing/invalid customer_id or touch_date (expected YYYY-MM-DD).']);
    }
    $d = DateTimeImmutable::createFromFormat('Y-m-d', $touchDate);
    if ($d === false || $d->format('Y-m-d') !== $touchDate) {
        relationships_respond(400, ['ok' => false, 'error' => 'Invalid touch_date.']);
    }

    $custStmt = $pdo->prepare('SELECT id, territory_name FROM customers WHERE id = :id');
    $custStmt->execute([':id' => $customerId]);
    $custRow = $custStmt->fetch(PDO::FETCH_ASSOC);
    if ($custRow === false) {
        relationships_respond(404, ['ok' => false, 'error' => 'Customer not found.']);
    }
    relationships_require_territory_scope($allowedTerritories, $custRow['territory_name']);

    // Local save first, unconditionally -- per Michael's standing "save
    // locally, log the ConnectWise failure" instruction for this whole
    // integration (see connectwise-activity-create.php). This can never
    // fail because of ConnectWise.
    // Source distinguishes a manual date-edit from a touch logged right
    // after a rep taps a synced contact's phone/email on the Relationships
    // contact card (2026-09-23, per Michael: "Both actions should trigger
    // the same outgrow workflow and log an outgrow touch" -- see
    // contact-card.php). Whitelisted rather than trusted verbatim from the
    // request body; anything unrecognized falls back to 'manual'.
    $source = (string) ($data['source'] ?? 'manual');
    if (!in_array($source, ['manual', 'call', 'email'], true)) {
        $source = 'manual';
    }

    $insert = $pdo->prepare(
        'INSERT INTO outgrow_last_touch_history (customer_id, touch_date, source, set_by_user_id, set_by_name)
         VALUES (:cid, :date, :source, :uid, :name)'
    );
    $insert->execute([':cid' => $customerId, ':date' => $touchDate, ':source' => $source, ':uid' => $user['id'], ':name' => $user['name']]);
    $historyId = (int) $pdo->lastInsertId();

    $cwPush = ['attempted' => false, 'status' => 'skipped', 'error' => null];
    $cwId = relationships_outgrow_cw_id($pdo, $customerId);
    if ($cwId !== null) {
        $cwPush['attempted'] = true;
        try {
            relationships_cw_outgrow_write($cwId, $touchDate);
            $cwPush['status'] = 'pushed';
        } catch (RelationshipsConnectWiseError $e) {
            $cwPush['status'] = 'error';
            $cwPush['error'] = $e->getMessage();
        }
    }

    $pdo->prepare('UPDATE outgrow_last_touch_history SET cw_push_status = :status, cw_push_error = :err WHERE id = :id')
        ->execute([':status' => $cwPush['attempted'] ? $cwPush['status'] : null, ':err' => $cwPush['error'], ':id' => $historyId]);

    $history = relationships_outgrow_history($pdo, $customerId);
    $current = $history[0] ?? null;
    relationships_respond(200, [
        'ok' => true,
        'current' => $current !== null ? [
            'touch_date' => $current['touch_date'],
            'set_by_name' => $current['set_by_name'],
            'source' => $current['source'],
            'created_at' => $current['created_at'],
        ] : null,
        'history' => $history,
        'cw_push' => $cwPush,
    ]);
}

relationships_respond(400, ['ok' => false, 'error' => 'Unknown action.']);
