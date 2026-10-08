<?php
/**
 * relationships/api/layout.php
 *
 * Per-rep customer-dashboard card layout -- added 2026-10-07 per Michael ("Edit View ... change your default
 * view of the cards for all customers"). One row per signed-in user; it applies to every customer they open.
 *
 * GET  ?action=get            -> { ok, layout: { left: [card ids], right: [card ids] } | null }   (null = use the default)
 * POST ?action=save { layout } -> saves it (card ids are validated against the known list)
 * POST ?action=reset          -> back to the default
 */
declare(strict_types=1);

require_once __DIR__ . '/_util.php';

$pdo = relationships_db();
$user = relationships_require_login($pdo);
$action = $_GET['action'] ?? '';

const RELATIONSHIPS_LAYOUT_CARDS = ['opportunity', 'contact', 'outgrow', 'riskscans', 'documents', 'solutions', 'computers'];

if ($action === 'get') {
    $stmt = $pdo->prepare('SELECT layout_json FROM user_dashboard_layouts WHERE user_id = :u');
    $stmt->execute([':u' => (int) $user['id']]);
    $json = $stmt->fetchColumn();
    $layout = $json ? json_decode((string) $json, true) : null;
    relationships_respond(200, ['ok' => true, 'layout' => is_array($layout) ? $layout : null]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    relationships_respond(405, ['ok' => false, 'error' => 'Method not allowed.']);
}

if ($action === 'reset') {
    $pdo->prepare('DELETE FROM user_dashboard_layouts WHERE user_id = :u')->execute([':u' => (int) $user['id']]);
    relationships_respond(200, ['ok' => true, 'layout' => null]);
}

if ($action === 'save') {
    $body = json_decode((string) file_get_contents('php://input'), true);
    $in = is_array($body) && is_array($body['layout'] ?? null) ? $body['layout'] : null;
    if ($in === null) {
        relationships_respond(400, ['ok' => false, 'error' => 'Missing layout.']);
    }
    $seen = [];
    $clean = ['left' => [], 'right' => []];
    foreach (['left', 'right'] as $col) {
        foreach (is_array($in[$col] ?? null) ? $in[$col] : [] as $id) {
            if (is_string($id) && in_array($id, RELATIONSHIPS_LAYOUT_CARDS, true) && !isset($seen[$id])) {
                $seen[$id] = true;
                $clean[$col][] = $id;
            }
        }
    }
    $pdo->prepare(
        'INSERT INTO user_dashboard_layouts (user_id, layout_json, updated_at) VALUES (:u, :j, datetime(\'now\'))
         ON CONFLICT(user_id) DO UPDATE SET layout_json = excluded.layout_json, updated_at = excluded.updated_at'
    )->execute([':u' => (int) $user['id'], ':j' => json_encode($clean)]);
    relationships_respond(200, ['ok' => true, 'layout' => $clean]);
}

relationships_respond(400, ['ok' => false, 'error' => 'Unknown action.']);
