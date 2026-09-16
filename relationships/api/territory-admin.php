<?php
/**
 * relationships/api/territory-admin.php
 *
 * Rep -> territory assignment management for the new "Territory Admin"
 * screen -- added 2026-09-16 per Michael, who asked (via AskUserQuestion)
 * for an admin screen to manage this now rather than hardcoding it, and
 * asked that the screen itself be restricted to just him for now (by
 * email -- see RELATIONSHIPS_TERRITORY_ADMIN_EMAILS in territory-access.php).
 *
 * Every action here requires relationships_current_user_is_territory_admin()
 * -- a non-admin (including an unauthenticated request) gets 403, same
 * status customers.php's detail gate uses for "not yours to see", so a
 * curious CRC poking this URL directly learns nothing beyond "not allowed".
 *
 * GET  /relationships/api/territory-admin.php?action=list
 *   -> { ok: true,
 *        assignments: [ { id, email, territory_name, created_at }, ... ]
 *          (every crc_territory_reps row, ordered by email then territory),
 *        territory_options: [ "Arcus + Chester Sienko", ... ]
 *          (distinct territory_name values actually seen on `customers` by
 *          the last completed territory sync -- see
 *          relationships_cw_synced_territory_names() in
 *          connectwise-territory-sync-core.php) }
 *
 * POST /relationships/api/territory-admin.php?action=add
 *   { email, territory_name }
 *   -> { ok: true, assignment: { id, email, territory_name, created_at } }
 *   territory_name does NOT have to be one of territory_options -- Michael
 *   may need to assign a territory before the first sync has run, or one
 *   the sync hasn't seen recently (e.g. a company with zero current
 *   customers). The picker in app.js suggests territory_options but never
 *   blocks a manually typed value; this endpoint only rejects blank
 *   input, not an unrecognized one.
 *
 * POST /relationships/api/territory-admin.php?action=remove
 *   { id }
 *   -> { ok: true }
 */

declare(strict_types=1);

require_once __DIR__ . '/_util.php';
require_once __DIR__ . '/territory-access.php';
require_once __DIR__ . '/connectwise-territory-sync-core.php';

$pdo = relationships_db();
relationships_require_login($pdo);

if (!relationships_current_user_is_territory_admin($pdo)) {
    relationships_respond(403, ['ok' => false, 'error' => 'You do not have access to territory management.']);
}

$action = $_GET['action'] ?? '';

if ($action === 'list') {
    $assignments = $pdo->query(
        'SELECT id, email, territory_name, created_at FROM crc_territory_reps ORDER BY email ASC, territory_name ASC'
    )->fetchAll(PDO::FETCH_ASSOC);
    $assignments = array_map(
        static fn (array $r): array => [
            'id' => (int) $r['id'],
            'email' => $r['email'],
            'territory_name' => $r['territory_name'],
            'created_at' => $r['created_at'],
        ],
        $assignments
    );

    relationships_respond(200, [
        'ok' => true,
        'assignments' => $assignments,
        'territory_options' => relationships_cw_synced_territory_names($pdo),
    ]);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    relationships_respond(405, ['ok' => false, 'error' => 'Method not allowed.']);
}

if ($action === 'add') {
    $data = relationships_read_json_body();
    $email = strtolower(trim((string) ($data['email'] ?? '')));
    $territoryName = trim((string) ($data['territory_name'] ?? ''));

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        relationships_respond(400, ['ok' => false, 'error' => 'A valid email address is required.']);
    }
    if ($territoryName === '') {
        relationships_respond(400, ['ok' => false, 'error' => 'A territory name is required.']);
    }
    if (mb_strlen($territoryName) > 200) {
        relationships_respond(400, ['ok' => false, 'error' => 'Territory name is too long (200 characters max).']);
    }

    // INSERT OR IGNORE -- the (email, territory_name) UNIQUE constraint
    // (see db.php) means re-adding an existing pair is a harmless no-op
    // rather than an error, matching every other "assign" action in this
    // app's general tolerance for a redundant click.
    $pdo->prepare('INSERT OR IGNORE INTO crc_territory_reps (email, territory_name) VALUES (:email, :territory)')
        ->execute([':email' => $email, ':territory' => $territoryName]);

    $stmt = $pdo->prepare('SELECT id, email, territory_name, created_at FROM crc_territory_reps WHERE email = :email AND territory_name = :territory');
    $stmt->execute([':email' => $email, ':territory' => $territoryName]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    relationships_respond(200, [
        'ok' => true,
        'assignment' => [
            'id' => (int) $row['id'],
            'email' => $row['email'],
            'territory_name' => $row['territory_name'],
            'created_at' => $row['created_at'],
        ],
    ]);
}

if ($action === 'remove') {
    $data = relationships_read_json_body();
    $id = (int) ($data['id'] ?? 0);
    if ($id <= 0) {
        relationships_respond(400, ['ok' => false, 'error' => 'Missing id.']);
    }
    $pdo->prepare('DELETE FROM crc_territory_reps WHERE id = :id')->execute([':id' => $id]);
    relationships_respond(200, ['ok' => true]);
}

relationships_respond(400, ['ok' => false, 'error' => 'Unknown action.']);
