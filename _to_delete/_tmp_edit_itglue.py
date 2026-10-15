#!/usr/bin/env python3
path = "api/itglue.php"
with open(path, "r", encoding="utf-8") as f:
    src = f.read()
orig = src

# ---------------------------------------------------------------------------
# 1. Pull in connectwise-meeting-activity.php for relationships_cw_create_general_todo_activity().
# ---------------------------------------------------------------------------
old1 = """require_once __DIR__ . '/_util.php';
require_once __DIR__ . '/territory-access.php';"""
assert src.count(old1) == 1, "old1 count=%d" % src.count(old1)
new1 = old1 + "\nrequire_once __DIR__ . '/connectwise-meeting-activity.php';"
src = src.replace(old1, new1, 1)

# ---------------------------------------------------------------------------
# 2. New helper functions: CW company id / user-by-name (local copies -- see comment -- of
#    meetings.php's own, since meetings.php can't be require_once'd without running its own
#    top-level request dispatch), the shared to-do-creation helper, and readers for the three
#    new tables. Inserted right before the "requests" dispatch section.
# ---------------------------------------------------------------------------
old2 = "// ------------------------------------------------------------------ requests"
assert src.count(old2) == 1, "old2 count=%d" % src.count(old2)
new2 = '''/** Minimal CW company id lookup, mirroring meetings.php's relationships_meetings_cw_id() --
 *  kept as a local copy rather than require_once'ing meetings.php, which runs its own
 *  top-level request dispatch at file scope and would hijack this request. */
function relationships_itglue_cw_company_id(PDO $pdo, int $customerId): ?string
{
    $stmt = $pdo->prepare('SELECT connectwise_id FROM customers WHERE id = :id');
    $stmt->execute([':id' => $customerId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $cwId = $row['connectwise_id'] ?? null;
    return ($cwId === null || $cwId === '' || str_starts_with((string) $cwId, 'MOCK-')) ? null : (string) $cwId;
}

/** crc_users (id, email) for a name, matched case-insensitively -- mirrors meetings.php's
 *  relationships_meetings_user_by_name() (see the comment above). */
function relationships_itglue_user_by_name(PDO $pdo, string $name): ?array
{
    $stmt = $pdo->prepare('SELECT id, email FROM crc_users WHERE LOWER(name) = LOWER(:name) LIMIT 1');
    $stmt->execute([':name' => $name]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row !== false ? ['id' => (int) $row['id'], 'email' => (string) $row['email']] : null;
}

/**
 * Creates a general to-do under a customer and tries to push it to ConnectWise as an Activity --
 * the SAME create workflow meetings.php's add_general_todo action uses (local save first,
 * unconditionally; then try ConnectWise; never let a ConnectWise failure block or revert the
 * local save). Used by every Asset Assignments action that records a change a rep made during a
 * meeting (a Microsoft License swap, a PC assignment, a manual column edit, ...) so there's
 * always a dated, attributed record of what was decided -- IT Glue itself is never written to
 * (see this file's header), so the to-do is what actually drives the follow-through.
 * $assignedToName defaults to the signed-in rep if they're on the fixed 7-name roster
 * (relationships_todo_roster()), else the roster's first name -- every signed-in CRC in practice
 * is one of the 7, so this should always resolve to the real rep who made the change.
 */
function relationships_itglue_create_todo(PDO $pdo, array $user, int $customerId, string $description, ?string $assignedToName = null): void
{
    $roster = relationships_todo_roster();
    if ($assignedToName === null || !in_array($assignedToName, $roster, true)) {
        $assignedToName = in_array((string) $user['name'], $roster, true) ? (string) $user['name'] : $roster[0];
    }
    if (mb_strlen($description) > 500) {
        $description = mb_substr($description, 0, 497) . '...';
    }
    $assignee = relationships_itglue_user_by_name($pdo, $assignedToName);
    $insert = $pdo->prepare(
        'INSERT INTO general_todos (customer_id, description, assigned_to_user_id, assigned_to_name, created_by_user_id, created_by_name, due_date)
         VALUES (:cid, :desc, :auid, :aname, :cuid, :cname, NULL)'
    );
    $insert->execute([
        ':cid' => $customerId, ':desc' => $description,
        ':auid' => $assignee['id'] ?? null, ':aname' => $assignedToName,
        ':cuid' => $user['id'], ':cname' => $user['name'],
    ]);
    $todoId = (int) $pdo->lastInsertId();

    $cwCompanyId = relationships_itglue_cw_company_id($pdo, $customerId);
    if ($cwCompanyId === null) {
        $pdo->prepare('UPDATE general_todos SET cw_push_status = :status WHERE id = :id')->execute([':status' => 'skipped', ':id' => $todoId]);
        return;
    }
    try {
        $nowEastern = new DateTimeImmutable('now', new DateTimeZone('America/New_York'));
        $result = relationships_cw_create_general_todo_activity($pdo, [
            'customer_id' => $customerId,
            'cw_company_id' => $cwCompanyId,
            'description' => $description,
            'assigned_to_name' => $assignedToName,
            'assigned_to_email' => relationships_todo_roster_cw_email($assignedToName),
            'created_by_name' => $user['name'],
            'created_at_display' => $nowEastern->format('M j, Y g:i A T'),
            'due_date' => null,
        ]);
        $pdo->prepare('UPDATE general_todos SET cw_activity_id = :aid, cw_push_status = :status, cw_push_error = NULL WHERE id = :id')
            ->execute([':aid' => $result['id'], ':status' => 'pushed', ':id' => $todoId]);
    } catch (Throwable $e) {
        $pdo->prepare('UPDATE general_todos SET cw_push_status = :status, cw_push_error = :err WHERE id = :id')
            ->execute([':status' => 'error', ':err' => substr($e->getMessage(), 0, 4000), ':id' => $todoId]);
    }
}

/** Manual overrides for one customer's grid cells: [ {contact_id, column_key, value, set_by_name, set_at} ]. */
function relationships_itglue_overrides(PDO $pdo, int $customerId): array
{
    $stmt = $pdo->prepare('SELECT contact_id, column_key, value, set_by_name, set_at FROM asset_overrides WHERE customer_id = :c');
    $stmt->execute([':c' => $customerId]);
    return array_map(static fn (array $r): array => [
        'contact_id' => (int) $r['contact_id'], 'column_key' => (string) $r['column_key'],
        'value' => (string) $r['value'], 'set_by_name' => (string) $r['set_by_name'], 'set_at' => (string) $r['set_at'],
    ], $stmt->fetchAll(PDO::FETCH_ASSOC));
}

/** Inactive flags for one customer's IT Glue contacts: [ {contact_id, inactive, set_by_name, set_at} ]. */
function relationships_itglue_contact_status(PDO $pdo, int $customerId): array
{
    $stmt = $pdo->prepare('SELECT contact_id, inactive, set_by_name, set_at FROM asset_contact_status WHERE customer_id = :c');
    $stmt->execute([':c' => $customerId]);
    return array_map(static fn (array $r): array => [
        'contact_id' => (int) $r['contact_id'], 'inactive' => (bool) $r['inactive'],
        'set_by_name' => (string) $r['set_by_name'], 'set_at' => (string) $r['set_at'],
    ], $stmt->fetchAll(PDO::FETCH_ASSOC));
}

/** The shared manual-column definitions (same across every customer), ordered for display. */
function relationships_itglue_grid_columns(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT column_key, label, options FROM asset_grid_columns ORDER BY position, id');
    return array_map(static fn (array $r): array => [
        'column_key' => (string) $r['column_key'], 'label' => (string) $r['label'],
        'options' => $r['options'] === null ? null : array_values(array_filter(array_map('trim', explode(',', (string) $r['options'])))),
    ], $stmt->fetchAll(PDO::FETCH_ASSOC));
}

''' + old2
assert src.count(old2) == 1
src = src.replace(old2, new2, 1)

# ---------------------------------------------------------------------------
# 3. assignments action: include overrides / contact_status / columns in the payload.
# ---------------------------------------------------------------------------
old3 = """        relationships_respond(200, [
            'ok' => true, 'configured' => true,
            'customer' => ['id' => (int) $cust['id'], 'name' => (string) $cust['name']],
            'matched_org' => ['id' => $match['id'], 'name' => $match['name']],
        ] + relationships_itglue_assignments((int) $match['id'], !empty($_GET['refresh'])) + ['links' => relationships_itglue_links($pdo, (int) $cust['id'])]);"""
assert src.count(old3) == 1, "old3 count=%d" % src.count(old3)
new3 = """        relationships_respond(200, [
            'ok' => true, 'configured' => true,
            'customer' => ['id' => (int) $cust['id'], 'name' => (string) $cust['name']],
            'matched_org' => ['id' => $match['id'], 'name' => $match['name']],
        ] + relationships_itglue_assignments((int) $match['id'], !empty($_GET['refresh'])) + [
            'links' => relationships_itglue_links($pdo, (int) $cust['id']),
            'overrides' => relationships_itglue_overrides($pdo, (int) $cust['id']),
            'contact_status' => relationships_itglue_contact_status($pdo, (int) $cust['id']),
            'columns' => relationships_itglue_grid_columns($pdo),
        ]);"""
src = src.replace(old3, new3, 1)

# ---------------------------------------------------------------------------
# 4. link_set: when a device that had NO prior owner (no IT Glue contact_id, no existing manual
#    link) gets assigned, or an owned device gets REASSIGNED to someone else, create the to-do
#    Michael asked for ("PC assignment ... was assigned to ...", extended for the reassignment
#    case so a change made during a meeting is never silently un-logged).
# ---------------------------------------------------------------------------
old4 = """        } else {
            $contactId = (int) ($body['contact_id'] ?? 0);
            $contact = null;
            foreach ($assign['contacts'] as $c) { if ($c['id'] === $contactId) { $contact = $c; break; } }
            if ($contact === null) {
                relationships_respond(400, ['ok' => false, 'error' => 'That person is not a contact in this customer\\'s IT Glue organization.']);
            }
            $stmt = $pdo->prepare('INSERT OR REPLACE INTO asset_links (customer_id, device_id, device_name, contact_id, contact_name, linked_by, linked_at) VALUES (:c, :d, :dn, :p, :pn, :by, datetime(\\'now\\'))');
            $stmt->execute([
                ':c' => (int) $cust['id'], ':d' => $deviceId, ':dn' => (string) $device['name'],
                ':p' => $contactId, ':pn' => (string) $contact['name'],
                ':by' => (string) ($user['name'] ?: $user['email']),
            ]);
        }"""
assert src.count(old4) == 1, "old4 count=%d" % src.count(old4)
new4 = """        } else {
            $contactId = (int) ($body['contact_id'] ?? 0);
            $contact = null;
            foreach ($assign['contacts'] as $c) { if ($c['id'] === $contactId) { $contact = $c; break; } }
            if ($contact === null) {
                relationships_respond(400, ['ok' => false, 'error' => 'That person is not a contact in this customer\\'s IT Glue organization.']);
            }

            // Added 2026-10-15, per Michael: figure out who (if anyone) owned this device BEFORE
            // this link, so an unassigned-PC assignment and a reassignment both get logged.
            $priorLinkStmt = $pdo->prepare('SELECT contact_name FROM asset_links WHERE customer_id = :c AND device_id = :d');
            $priorLinkStmt->execute([':c' => (int) $cust['id'], ':d' => $deviceId]);
            $priorLink = $priorLinkStmt->fetch(PDO::FETCH_ASSOC);
            $priorContactName = $priorLink !== false ? (string) $priorLink['contact_name'] : null;
            if ($priorContactName === null && (int) $device['contact_id'] > 0) {
                foreach ($assign['contacts'] as $c) { if ($c['id'] === (int) $device['contact_id']) { $priorContactName = $c['name']; break; } }
            }

            $stmt = $pdo->prepare('INSERT OR REPLACE INTO asset_links (customer_id, device_id, device_name, contact_id, contact_name, linked_by, linked_at) VALUES (:c, :d, :dn, :p, :pn, :by, datetime(\\'now\\'))');
            $stmt->execute([
                ':c' => (int) $cust['id'], ':d' => $deviceId, ':dn' => (string) $device['name'],
                ':p' => $contactId, ':pn' => (string) $contact['name'],
                ':by' => (string) ($user['name'] ?: $user['email']),
            ]);

            if ($priorContactName === null) {
                relationships_itglue_create_todo($pdo, $user, (int) $cust['id'],
                    'PC assignment - update friendly name in Automate. ' . $device['name'] . ' was assigned to ' . $contact['name'] . '.');
            } elseif ($priorContactName !== $contact['name']) {
                relationships_itglue_create_todo($pdo, $user, (int) $cust['id'],
                    'PC reassignment - update friendly name in Automate. ' . $device['name'] . ' was reassigned to ' . $contact['name'] . ' (previously ' . $priorContactName . ').');
            }
        }"""
assert src.count(old4) == 1
src = src.replace(old4, new4, 1)

# ---------------------------------------------------------------------------
# 5. New actions: contact_toggle_inactive, override_set, columns / column_add / column_remove.
#    Inserted right before the "discover" action.
# ---------------------------------------------------------------------------
old5 = "    if ($action === 'discover') {"
assert src.count(old5) == 1, "old5 count=%d" % src.count(old5)
new5 = '''    if ($action === 'contact_toggle_inactive') {
        // Mirrors app.js's contacts-toggle-inactive control (Account Contacts), scoped to this
        // screen's own IT Glue contact id -- see asset_contact_status's table comment in db.php
        // for why this isn't the same `contacts` row. Added 2026-10-15 per Michael.
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            relationships_respond(405, ['ok' => false, 'error' => 'Method not allowed.']);
        }
        if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'fetch') {
            relationships_respond(403, ['ok' => false, 'error' => 'Bad request.']);
        }
        [$match, $cust] = relationships_itglue_resolve($pdo);
        if ($match === null) {
            relationships_respond(404, ['ok' => false, 'error' => 'This customer is not matched to an IT Glue organization.']);
        }
        $body = relationships_read_json_body();
        $contactId = (int) ($body['contact_id'] ?? 0);
        $assign = relationships_itglue_assignments((int) $match['id'], false);
        $contact = null;
        foreach ($assign['contacts'] as $c) { if ($c['id'] === $contactId) { $contact = $c; break; } }
        if ($contact === null) {
            relationships_respond(400, ['ok' => false, 'error' => 'That person is not a contact in this customer\\'s IT Glue organization.']);
        }
        $curStmt = $pdo->prepare('SELECT inactive FROM asset_contact_status WHERE customer_id = :c AND contact_id = :p');
        $curStmt->execute([':c' => (int) $cust['id'], ':p' => $contactId]);
        $cur = $curStmt->fetch(PDO::FETCH_ASSOC);
        $next = ($cur !== false && (int) $cur['inactive'] === 1) ? 0 : 1;
        $pdo->prepare('INSERT OR REPLACE INTO asset_contact_status (customer_id, contact_id, contact_name, inactive, set_by_name, set_at) VALUES (:c, :p, :pn, :inactive, :by, datetime(\\'now\\'))')
            ->execute([':c' => (int) $cust['id'], ':p' => $contactId, ':pn' => (string) $contact['name'], ':inactive' => $next, ':by' => (string) ($user['name'] ?: $user['email'])]);
        relationships_respond(200, ['ok' => true, 'contact_status' => relationships_itglue_contact_status($pdo, (int) $cust['id'])]);
    }

    if ($action === 'override_set') {
        // One grid-cell edit: Microsoft License, Software, or any manual column (Firewall/VPN/
        // DUO/Guardz/custom). Always creates a to-do recording the change -- IT Glue is never
        // written to (read-only API, see file header), so the to-do is the actionable record of
        // what still needs doing elsewhere (Microsoft 365 admin center, the firewall, Automate,
        // ...). Added 2026-10-15 per Michael.
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            relationships_respond(405, ['ok' => false, 'error' => 'Method not allowed.']);
        }
        if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'fetch') {
            relationships_respond(403, ['ok' => false, 'error' => 'Bad request.']);
        }
        [$match, $cust] = relationships_itglue_resolve($pdo);
        if ($match === null) {
            relationships_respond(404, ['ok' => false, 'error' => 'This customer is not matched to an IT Glue organization.']);
        }
        $body = relationships_read_json_body();
        $contactId = (int) ($body['contact_id'] ?? 0);
        $columnKey = trim((string) ($body['column_key'] ?? ''));
        $columnLabel = trim((string) ($body['column_label'] ?? $columnKey));
        $value = trim((string) ($body['value'] ?? ''));
        $previousValue = trim((string) ($body['previous_value'] ?? ''));
        $assign = relationships_itglue_assignments((int) $match['id'], false);
        $contact = null;
        foreach ($assign['contacts'] as $c) { if ($c['id'] === $contactId) { $contact = $c; break; } }
        if ($contact === null || $columnKey === '') {
            relationships_respond(400, ['ok' => false, 'error' => 'Missing or invalid contact_id/column_key.']);
        }
        if (mb_strlen($value) > 200 || mb_strlen($previousValue) > 200) {
            relationships_respond(400, ['ok' => false, 'error' => 'That value is too long (200 characters max).']);
        }
        if ($value === '') {
            $pdo->prepare('DELETE FROM asset_overrides WHERE customer_id = :c AND contact_id = :p AND column_key = :k')
                ->execute([':c' => (int) $cust['id'], ':p' => $contactId, ':k' => $columnKey]);
        } else {
            $pdo->prepare('INSERT OR REPLACE INTO asset_overrides (customer_id, contact_id, column_key, value, set_by_name, set_at) VALUES (:c, :p, :k, :v, :by, datetime(\\'now\\'))')
                ->execute([':c' => (int) $cust['id'], ':p' => $contactId, ':k' => $columnKey, ':v' => $value, ':by' => (string) ($user['name'] ?: $user['email'])]);
        }
        if ($value !== $previousValue) {
            $fromText = $previousValue !== '' ? $previousValue : 'nothing';
            $toText = $value !== '' ? $value : 'nothing';
            $description = $columnKey === 'microsoft_license'
                ? 'Microsoft License Change - ' . $contact['name'] . ' was changed from ' . $fromText . ' to ' . $toText . '.'
                : $columnLabel . ' changed for ' . $contact['name'] . ' - from ' . $fromText . ' to ' . $toText . '.';
            relationships_itglue_create_todo($pdo, $user, (int) $cust['id'], $description);
        }
        relationships_respond(200, ['ok' => true, 'overrides' => relationships_itglue_overrides($pdo, (int) $cust['id'])]);
    }

    if ($action === 'columns') {
        relationships_respond(200, ['ok' => true, 'columns' => relationships_itglue_grid_columns($pdo)]);
    }

    if ($action === 'column_add') {
        // The manual column set is shared/global (same grid for every customer) -- see
        // asset_grid_columns' table comment in db.php. Added 2026-10-15 per Michael's
        // "configurable like a spreadsheet" ask.
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            relationships_respond(405, ['ok' => false, 'error' => 'Method not allowed.']);
        }
        if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'fetch') {
            relationships_respond(403, ['ok' => false, 'error' => 'Bad request.']);
        }
        $body = relationships_read_json_body();
        $label = trim((string) ($body['label'] ?? ''));
        $options = trim((string) ($body['options'] ?? ''));
        if ($label === '' || mb_strlen($label) > 60) {
            relationships_respond(400, ['ok' => false, 'error' => 'Column name is required (60 characters max).']);
        }
        $key = (string) preg_replace('/[^a-z0-9]+/', '_', mb_strtolower($label));
        $key = trim($key, '_');
        if ($key === '') {
            relationships_respond(400, ['ok' => false, 'error' => 'That column name has no usable characters.']);
        }
        $pos = (int) $pdo->query('SELECT COALESCE(MAX(position), 9) + 1 FROM asset_grid_columns')->fetchColumn();
        try {
            $pdo->prepare('INSERT INTO asset_grid_columns (column_key, label, options, position, created_by_name) VALUES (:k, :l, :o, :p, :by)')
                ->execute([':k' => $key, ':l' => $label, ':o' => ($options !== '' ? $options : null), ':p' => $pos, ':by' => (string) ($user['name'] ?: $user['email'])]);
        } catch (PDOException $e) {
            relationships_respond(409, ['ok' => false, 'error' => 'A column with that name already exists.']);
        }
        relationships_respond(200, ['ok' => true, 'columns' => relationships_itglue_grid_columns($pdo)]);
    }

    if ($action === 'column_remove') {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            relationships_respond(405, ['ok' => false, 'error' => 'Method not allowed.']);
        }
        if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'fetch') {
            relationships_respond(403, ['ok' => false, 'error' => 'Bad request.']);
        }
        $body = relationships_read_json_body();
        $key = trim((string) ($body['column_key'] ?? ''));
        if ($key === '') {
            relationships_respond(400, ['ok' => false, 'error' => 'Missing column_key.']);
        }
        $pdo->prepare('DELETE FROM asset_grid_columns WHERE column_key = :k')->execute([':k' => $key]);
        $pdo->prepare('DELETE FROM asset_overrides WHERE column_key = :k')->execute([':k' => $key]);
        relationships_respond(200, ['ok' => true, 'columns' => relationships_itglue_grid_columns($pdo)]);
    }

''' + old5
assert src.count(old5) == 1
src = src.replace(old5, new5, 1)

assert src != orig
with open(path, "w", encoding="utf-8") as f:
    f.write(src)
print("itglue.php updated: helpers + contact_toggle_inactive/override_set/columns actions + link_set to-do logging")
