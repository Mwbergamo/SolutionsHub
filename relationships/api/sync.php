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
 *
 * The billing-* actions below (added 2026-09-10) are the same start/step
 * shape, but for the Monthly Billing panel's nightly-synced 6-month series
 * (customer_monthly_billing -- see connectwise-billing-sync-core.php) --
 * queued by customer rather than by agreement. app.js's "Run Sync Now"
 * chains this after the agreement sync above finishes, so one click does
 * the same full sync the nightly cron does; connectwise-cron.php runs both
 * in sequence too.
 *
 * GET  /relationships/api/sync.php?action=billing-status
 *   -> { ok: true, totals: { pending, done, error }, started_at }
 *
 * POST /relationships/api/sync.php?action=billing-start
 *   -> { ok: true, total }
 *
 * POST /relationships/api/sync.php?action=billing-step
 *   { batch_size?: int (default 20, max 50) }
 *   -> { ok: true, processed_this_batch, remaining, done, totals, errors: [...] }
 *
 * The prospect-* actions below (added 2026-09-10) are the same start/step
 * shape again, for ConnectWise Companies with no active agreement of any
 * tracked type but a real Active/Delinquent/Special Info status and no
 * Vendor type -- see connectwise-prospect-sync-core.php. app.js's "Run
 * Sync Now" chains this after the billing sync above finishes, so one
 * click still does the whole nightly-cron-equivalent sync (agreements,
 * then billing, then prospects); connectwise-cron.php runs all three in
 * sequence too.
 *
 * GET  /relationships/api/sync.php?action=prospect-status
 *   -> { ok: true, totals: { pending, done, error }, started_at }
 *
 * POST /relationships/api/sync.php?action=prospect-start
 *   -> { ok: true, total }
 *
 * POST /relationships/api/sync.php?action=prospect-step
 *   { batch_size?: int (default 20, max 50) }
 *   -> { ok: true, processed_this_batch, remaining, done, totals, errors: [...] }
 *
 * The ticket-history-* and contacts-* actions below (added 2026-09-10) are
 * the same start/step shape again, for the front-page Primary Relationship
 * Dashboard's per-customer Service Ticket volume trend and Active Contact
 * count/trend -- see connectwise-ticket-history-sync-core.php and
 * connectwise-contacts-sync-core.php. app.js's "Run Sync Now" chains these
 * last, after prospects, so one click still does the whole nightly-cron-
 * equivalent sync (agreements, billing, prospects, ticket history,
 * contacts, in that order); connectwise-cron.php runs all five in sequence
 * too.
 *
 * GET  /relationships/api/sync.php?action=ticket-history-status
 * POST /relationships/api/sync.php?action=ticket-history-start
 * POST /relationships/api/sync.php?action=ticket-history-step
 *   Same shapes as billing-status/-start/-step above.
 *
 * GET  /relationships/api/sync.php?action=contacts-status
 * POST /relationships/api/sync.php?action=contacts-start
 * POST /relationships/api/sync.php?action=contacts-step
 *   Same shapes again.
 */

declare(strict_types=1);

require_once __DIR__ . '/_util.php';
require_once __DIR__ . '/connectwise-sync-core.php';
require_once __DIR__ . '/connectwise-billing-sync-core.php';
require_once __DIR__ . '/connectwise-prospect-sync-core.php';
require_once __DIR__ . '/connectwise-ticket-history-sync-core.php';
require_once __DIR__ . '/connectwise-contacts-sync-core.php';

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

if ($action === 'billing-status') {
    $counts = $pdo->query('SELECT status, COUNT(*) AS n FROM cw_billing_sync_queue GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
    $meta = $pdo->query('SELECT key, value FROM cw_sync_meta')->fetchAll(PDO::FETCH_KEY_PAIR);
    relationships_respond(200, [
        'ok' => true,
        'totals' => [
            'pending' => (int) ($counts['pending'] ?? 0),
            'done' => (int) ($counts['done'] ?? 0),
            'error' => (int) ($counts['error'] ?? 0),
        ],
        'started_at' => $meta['billing_started_at'] ?? null,
    ]);
}

if ($action === 'prospect-status') {
    $counts = $pdo->query('SELECT status, COUNT(*) AS n FROM cw_prospect_sync_queue GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
    $meta = $pdo->query('SELECT key, value FROM cw_sync_meta')->fetchAll(PDO::FETCH_KEY_PAIR);
    relationships_respond(200, [
        'ok' => true,
        'totals' => [
            'pending' => (int) ($counts['pending'] ?? 0),
            'done' => (int) ($counts['done'] ?? 0),
            'error' => (int) ($counts['error'] ?? 0),
        ],
        'started_at' => $meta['prospect_started_at'] ?? null,
    ]);
}

if ($action === 'ticket-history-status') {
    $counts = $pdo->query('SELECT status, COUNT(*) AS n FROM cw_ticket_history_sync_queue GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
    $meta = $pdo->query('SELECT key, value FROM cw_sync_meta')->fetchAll(PDO::FETCH_KEY_PAIR);
    relationships_respond(200, [
        'ok' => true,
        'totals' => [
            'pending' => (int) ($counts['pending'] ?? 0),
            'done' => (int) ($counts['done'] ?? 0),
            'error' => (int) ($counts['error'] ?? 0),
        ],
        'started_at' => $meta['ticket_history_started_at'] ?? null,
    ]);
}

if ($action === 'contacts-status') {
    $counts = $pdo->query('SELECT status, COUNT(*) AS n FROM cw_contacts_sync_queue GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
    $meta = $pdo->query('SELECT key, value FROM cw_sync_meta')->fetchAll(PDO::FETCH_KEY_PAIR);
    relationships_respond(200, [
        'ok' => true,
        'totals' => [
            'pending' => (int) ($counts['pending'] ?? 0),
            'done' => (int) ($counts['done'] ?? 0),
            'error' => (int) ($counts['error'] ?? 0),
        ],
        'started_at' => $meta['contacts_started_at'] ?? null,
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

if ($action === 'billing-start') {
    try {
        $result = relationships_cw_billing_sync_start($pdo);
        relationships_respond(200, ['ok' => true, 'total' => $result['total']]);
    } catch (RelationshipsConnectWiseError $e) {
        relationships_respond(502, ['ok' => false, 'error' => $e->getMessage()]);
    }
}

if ($action === 'billing-step') {
    $data = relationships_read_json_body();
    $batchSize = (int) ($data['batch_size'] ?? 20);
    $batchSize = max(1, min(50, $batchSize));
    try {
        $result = relationships_cw_billing_sync_step($pdo, $batchSize);
        relationships_respond(200, array_merge(['ok' => true], $result));
    } catch (RelationshipsConnectWiseError $e) {
        relationships_respond(502, ['ok' => false, 'error' => $e->getMessage()]);
    }
}

if ($action === 'prospect-start') {
    try {
        $result = relationships_cw_prospect_sync_start($pdo);
        relationships_respond(200, ['ok' => true, 'total' => $result['total']]);
    } catch (RelationshipsConnectWiseError $e) {
        relationships_respond(502, ['ok' => false, 'error' => $e->getMessage()]);
    }
}

if ($action === 'prospect-step') {
    $data = relationships_read_json_body();
    $batchSize = (int) ($data['batch_size'] ?? 20);
    $batchSize = max(1, min(50, $batchSize));
    try {
        $result = relationships_cw_prospect_sync_step($pdo, $batchSize);
        relationships_respond(200, array_merge(['ok' => true], $result));
    } catch (RelationshipsConnectWiseError $e) {
        relationships_respond(502, ['ok' => false, 'error' => $e->getMessage()]);
    }
}

if ($action === 'ticket-history-start') {
    try {
        $result = relationships_cw_ticket_history_sync_start($pdo);
        relationships_respond(200, ['ok' => true, 'total' => $result['total']]);
    } catch (RelationshipsConnectWiseError $e) {
        relationships_respond(502, ['ok' => false, 'error' => $e->getMessage()]);
    }
}

if ($action === 'ticket-history-step') {
    $data = relationships_read_json_body();
    $batchSize = (int) ($data['batch_size'] ?? 20);
    $batchSize = max(1, min(50, $batchSize));
    try {
        $result = relationships_cw_ticket_history_sync_step($pdo, $batchSize);
        relationships_respond(200, array_merge(['ok' => true], $result));
    } catch (RelationshipsConnectWiseError $e) {
        relationships_respond(502, ['ok' => false, 'error' => $e->getMessage()]);
    }
}

if ($action === 'contacts-start') {
    try {
        $result = relationships_cw_contacts_sync_start($pdo);
        relationships_respond(200, ['ok' => true, 'total' => $result['total']]);
    } catch (RelationshipsConnectWiseError $e) {
        relationships_respond(502, ['ok' => false, 'error' => $e->getMessage()]);
    }
}

if ($action === 'contacts-step') {
    $data = relationships_read_json_body();
    $batchSize = (int) ($data['batch_size'] ?? 20);
    $batchSize = max(1, min(50, $batchSize));
    try {
        $result = relationships_cw_contacts_sync_step($pdo, $batchSize);
        relationships_respond(200, array_merge(['ok' => true], $result));
    } catch (RelationshipsConnectWiseError $e) {
        relationships_respond(502, ['ok' => false, 'error' => $e->getMessage()]);
    }
}

relationships_respond(400, ['ok' => false, 'error' => 'Unknown action.']);
