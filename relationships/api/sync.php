<?php
/**
 * relationships/api/sync.php
 *
 * "Sync Now" endpoint driving the ConnectWise import from the dashboard UI
 * (app.js's Sync view) -- any logged-in CRC can trigger it, same as every
 * other endpoint in this app. See connectwise-sync-core.php for why this
 * is split into start/step calls rather than one request: Bluehost can cut
 * off a long-running request, and a full sync (~440 agreements) can take
 * several minutes, so the frontend calls action=start once and then
 * action=step repeatedly until it reports done: true.
 *
 * GET  /relationships/api/sync.php?action=status
 *   -> { ok: true, totals: { pending, done, error }, started_at }
 *
 * POST /relationships/api/sync.php?action=start
 *   -> { ok: true, total }
 *
 * POST /relationships/api/sync.php?action=step
 *   { batch_size?: int (default 20, max 50) }
 *   -> { ok: true, processed_this_batch, remaining, done, totals, errors: [...] }
 */

declare(strict_types=1);

require_once __DIR__ . '/_util.php';
require_once __DIR__ . '/connectwise-sync-core.php';

$pdo = relationships_db();
relationships_require_login($pdo);

$action = $_GET['action'] ?? '';

if ($action === 'status') {
    $counts = $pdo->query('SELECT status, COUNT(*) AS n FROM cw_sync_queue GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
    $meta = $pdo->query('SELECT key, value FROM cw_sync_meta')->fetchAll(PDO::FETCH_KEY_PAIR);
    relationships_respond(200, [
        'ok' => true,
        'totals' => [
            'pending' => (int) ($counts['pending'] ?? 0),
            'done' => (int) ($counts['done'] ?? 0),
            'error' => (int) ($counts['error'] ?? 0),
        ],
        'started_at' => $meta['started_at'] ?? null,
    ]);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    relationships_respond(405, ['ok' => false, 'error' => 'Method not allowed.']);
}

if ($action === 'start') {
    try {
        $result = relationships_cw_sync_start($pdo);
        relationships_respond(200, ['ok' => true, 'total' => $result['total']]);
    } catch (RelationshipsConnectWiseError $e) {
        relationships_respond(502, ['ok' => false, 'error' => $e->getMessage()]);
    }
}

if ($action === 'step') {
    $data = relationships_read_json_body();
    $batchSize = (int) ($data['batch_size'] ?? 20);
    $batchSize = max(1, min(50, $batchSize));
    try {
        $result = relationships_cw_sync_step($pdo, $batchSize);
        relationships_respond(200, array_merge(['ok' => true], $result));
    } catch (RelationshipsConnectWiseError $e) {
        relationships_respond(502, ['ok' => false, 'error' => $e->getMessage()]);
    }
}

relationships_respond(400, ['ok' => false, 'error' => 'Unknown action.']);
