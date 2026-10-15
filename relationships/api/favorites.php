<?php
/**
 * relationships/api/favorites.php
 *
 * Cross-app "Add to Favorites" -- added 2026-10-15 per Michael: a rep on any SolutionsHub app
 * (root Solutions Creator, this Relationships dashboard, Register, Rate Sheet, ...) can star the
 * page they're on, and see all their starred pages as a quick-link list in a new left-hand
 * sidebar on the root Overview screen. See db.php's favorites table for the schema/design
 * rationale -- this lives here because every app already shares one login session
 * (relationships_current_user()) and the root app already calls into relationships/api/* for
 * Saved Solutions (solution-save.js), so this reuses the established shared backend rather than
 * standing up a new one. Each favorite is just a label + a URL (that app's own deep link) --
 * no snapshot of in-progress state on that page (AskUserQuestion 2026-10-15).
 *
 * GET  /relationships/api/favorites.php?action=list
 *   -> { ok: true, favorites: [ { id, app, label, url, created_at }, ... ] (newest first) }
 *
 * POST /relationships/api/favorites.php?action=add
 *   { app, label, url }
 *   -> { ok: true, favorite: {...} }
 *   Starring the same url twice is a no-op (UNIQUE(user_id, url)) -- returns the existing row,
 *   doesn't error.
 *
 * POST /relationships/api/favorites.php?action=remove
 *   { id }  -- OR --  { url }
 *   -> { ok: true }
 */

declare(strict_types=1);

require_once __DIR__ . '/_util.php';

$pdo = relationships_db();
$user = relationships_require_login($pdo);

$action = $_GET['action'] ?? '';

function relationships_favorite_row(array $r): array
{
    return [
        'id' => (int) $r['id'],
        'app' => $r['app'],
        'label' => $r['label'],
        'url' => $r['url'],
        'created_at' => $r['created_at'],
    ];
}

if ($action === 'list') {
    $stmt = $pdo->prepare('SELECT id, app, label, url, created_at FROM favorites WHERE user_id = :uid ORDER BY created_at DESC, id DESC');
    $stmt->execute([':uid' => $user['id']]);
    $favorites = array_map('relationships_favorite_row', $stmt->fetchAll(PDO::FETCH_ASSOC));
    relationships_respond(200, ['ok' => true, 'favorites' => $favorites]);
}

if ($action === 'add') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        relationships_respond(405, ['ok' => false, 'error' => 'POST required.']);
    }
    $data = relationships_read_json_body();
    $app = trim((string) ($data['app'] ?? ''));
    $label = trim((string) ($data['label'] ?? ''));
    $url = trim((string) ($data['url'] ?? ''));
    if ($app === '' || $label === '' || $url === '') {
        relationships_respond(400, ['ok' => false, 'error' => 'Missing app, label, or url.']);
    }
    if (mb_strlen($label) > 200) {
        $label = mb_substr($label, 0, 200);
    }
    if (mb_strlen($url) > 1000) {
        relationships_respond(400, ['ok' => false, 'error' => 'That link is too long to save.']);
    }

    // INSERT ... ON CONFLICT DO NOTHING keyed on the UNIQUE(user_id, url) constraint -- starring
    // an already-favorited page is a harmless no-op, not an error the caller has to special-case.
    $insert = $pdo->prepare(
        'INSERT INTO favorites (user_id, app, label, url) VALUES (:uid, :app, :label, :url)
         ON CONFLICT(user_id, url) DO NOTHING'
    );
    $insert->execute([':uid' => $user['id'], ':app' => $app, ':label' => $label, ':url' => $url]);

    $rowStmt = $pdo->prepare('SELECT id, app, label, url, created_at FROM favorites WHERE user_id = :uid AND url = :url');
    $rowStmt->execute([':uid' => $user['id'], ':url' => $url]);
    $row = $rowStmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        relationships_respond(500, ['ok' => false, 'error' => 'Could not save that favorite.']);
    }
    relationships_respond(200, ['ok' => true, 'favorite' => relationships_favorite_row($row)]);
}

if ($action === 'remove') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        relationships_respond(405, ['ok' => false, 'error' => 'POST required.']);
    }
    $data = relationships_read_json_body();
    $id = (int) ($data['id'] ?? 0);
    $url = trim((string) ($data['url'] ?? ''));
    if ($id <= 0 && $url === '') {
        relationships_respond(400, ['ok' => false, 'error' => 'Missing id or url.']);
    }
    if ($id > 0) {
        $pdo->prepare('DELETE FROM favorites WHERE id = :id AND user_id = :uid')
            ->execute([':id' => $id, ':uid' => $user['id']]);
    } else {
        $pdo->prepare('DELETE FROM favorites WHERE url = :url AND user_id = :uid')
            ->execute([':url' => $url, ':uid' => $user['id']]);
    }
    relationships_respond(200, ['ok' => true]);
}

relationships_respond(400, ['ok' => false, 'error' => 'Unknown action.']);
