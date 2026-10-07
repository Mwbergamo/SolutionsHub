<?php
/**
 * relationships/api/solutions.php
 *
 * Saved Solutions -- added 2026-10-07 per Michael: a solution built in the Solutions Hub
 * can be saved to a customer (with its documents and images), found again on that customer's
 * dashboard ("Solutions" card), edited in the Hub and re-saved, or deleted.
 *
 * What is stored: the Hub's working state as JSON (selections, parts/scope quantities, notes,
 * camera layout ...) so the solution can be reopened exactly as it was, plus a plain-text
 * `search_text` (name, customer, services, notes) for the central repository search, plus the
 * attached files. Files live on disk under data/solutions/<customer_id>/<solution_id>/ (data/ is
 * blocked from direct HTTP by data/.htaccess; the only way back out is the 'file' action below,
 * which applies the normal login + territory checks).
 *
 * Who can do what (every action also needs the customer to be inside the signed-in rep's territories):
 *   list / get / file    any signed-in Relationships user
 *   save (new or edit)   any signed-in Relationships user
 *   delete               the rep who saved it, or a territory admin
 *
 * GET  ?action=list&customer_id=N          one customer's solutions, newest first
 * GET  ?action=list&q=text                 repository search across every customer in scope
 * GET  ?action=get&id=N                    one solution incl. its saved state + files
 * GET  ?action=file&id=N                   streams one stored file (images inline, others as download)
 * POST ?action=save   multipart/form-data:
 *        id (0/omitted = new), customer_id, name, pillars (JSON array of names), search_text,
 *        state (JSON string), keep_file_ids (JSON array of existing file ids to keep),
 *        files[] + file_kinds[] ('attachment'|'camera_photo') + file_refs[] (camera photo id)
 * POST ?action=delete  { id }
 * POST ?action=retry_cw_file { file_id }   re-attempts the ConnectWise attachment of one marked-up camera photo
 *
 * Camera photos: the Hub saves each photo twice -- the original (kind 'camera_photo', so the layout can be re-edited) and a
 * marked-up copy with the numbered cameras drawn on it (kind 'camera_marked', ref '<photoId>:<signature>'). The marked-up
 * copy is attached to the customer's ConnectWise company the first time it is saved (and again if the photo's cameras change).
 *
 * ConnectWise link (added 2026-10-07 per Michael: "a unique link ... added to a project (pre-sales) as a commented link"):
 *   every solution has a stable link (row.link = <site>/index.html?solution=ID -- opens it in the Hub after sign-in,
 *   subject to the same territory rules).
 * GET  ?action=projects&id=N               open Pre-Sales (board 45) ConnectWise projects, the solution's customer's first
 * POST ?action=link_project { id, project_id, project_name }
 *      posts a Comment note ("Solution: <name> -- <customer>\n<link>") on that project, exactly like projects.php's
 *      notes_add (type id 2; the rep's name is prefixed because ConnectWise stamps the shared API member), and records it.
 *      Returns { ok, links, cw_warning? } -- a ConnectWise failure is recorded and reported, never lost.
 */

declare(strict_types=1);

require_once __DIR__ . '/_util.php';
require_once __DIR__ . '/territory-access.php';
require_once __DIR__ . '/connectwise.php';

$pdo = relationships_db();
$user = relationships_require_login($pdo);
$allowedTerritories = relationships_allowed_territories($pdo);
$action = $_GET['action'] ?? '';

const RELATIONSHIPS_SOLUTION_FILE_MAX_BYTES = 100 * 1024 * 1024;
const RELATIONSHIPS_SOLUTION_STATE_MAX_BYTES = 6 * 1024 * 1024;

function relationships_solution_types(): array
{
    return [
        'pdf' => 'application/pdf', 'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'xlsm' => 'application/vnd.ms-excel.sheet.macroEnabled.12', 'csv' => 'text/csv',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'txt' => 'text/plain', 'rtf' => 'application/rtf',
        'odt' => 'application/vnd.oasis.opendocument.text', 'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
        'msg' => 'application/vnd.ms-outlook', 'eml' => 'message/rfc822',
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp',
        'vsd' => 'application/vnd.visio', 'vsdx' => 'application/vnd.ms-visio.drawing', 'zip' => 'application/zip',
    ];
}

function relationships_solution_dir(int $customerId, int $solutionId): string
{
    return __DIR__ . '/../data/solutions/' . $customerId . '/' . $solutionId;
}

function relationships_solution_customer(PDO $pdo, ?array $allowedTerritories, int $customerId): array
{
    $stmt = $pdo->prepare('SELECT id, name, territory_name FROM customers WHERE id = :id');
    $stmt->execute([':id' => $customerId]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($customer === false) {
        relationships_respond(404, ['ok' => false, 'error' => 'Customer not found.']);
    }
    relationships_require_territory_scope($allowedTerritories, $customer['territory_name']);
    return $customer;
}

function relationships_solution_link(int $solutionId): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $base = preg_replace('#/relationships/api/[^/]*$#', '', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $base . '/index.html?solution=' . $solutionId;
}

function relationships_solution_cw_links(PDO $pdo, int $solutionId): array
{
    $stmt = $pdo->prepare('SELECT cw_project_id, project_name, push_status, push_error, linked_by_name, linked_at FROM solution_cw_links WHERE solution_id = :s ORDER BY id DESC');
    $stmt->execute([':s' => $solutionId]);
    return array_map(static fn (array $l): array => [
        'project_id' => (int) $l['cw_project_id'], 'project_name' => $l['project_name'], 'status' => $l['push_status'],
        'error' => $l['push_error'], 'linked_by_name' => $l['linked_by_name'], 'linked_at' => $l['linked_at'],
    ], $stmt->fetchAll(PDO::FETCH_ASSOC));
}

const RELATIONSHIPS_SOLUTION_PRESALES_BOARD = 45; // same Pre-Sales board projects.php lists

/**
 * Attaches one marked-up camera photo to the customer's ConnectWise Company as a Document (same call Customer
 * Documents use) and records the outcome on the row. Never throws -- the local save has already succeeded.
 */
function relationships_solution_file_push_to_cw(PDO $pdo, int $fileId, array $solution, string $uploaderName): void
{
    $fs = $pdo->prepare('SELECT * FROM solution_files WHERE id = :id');
    $fs->execute([':id' => $fileId]);
    $f = $fs->fetch(PDO::FETCH_ASSOC);
    if ($f === false) {
        return;
    }
    $set = static function (string $status, ?string $docId, ?string $err) use ($pdo, $fileId): void {
        $pdo->prepare('UPDATE solution_files SET cw_upload_status = :s, cw_document_id = :d, cw_upload_error = :e WHERE id = :id')
            ->execute([':s' => $status, ':d' => $docId, ':e' => $err, ':id' => $fileId]);
    };
    $cs = $pdo->prepare('SELECT connectwise_id, is_mock FROM customers WHERE id = :id');
    $cs->execute([':id' => $solution['customer_id']]);
    $c = $cs->fetch(PDO::FETCH_ASSOC);
    if ($c === false || (int) ($c['is_mock'] ?? 0) === 1 || trim((string) ($c['connectwise_id'] ?? '')) === '') {
        $set('skipped', null, 'This customer has no ConnectWise company to attach to.');
        return;
    }
    $path = relationships_solution_dir((int) $solution['customer_id'], (int) $f['solution_id']) . '/' . $f['stored_filename'];
    @set_time_limit(280);
    try {
        $cwDoc = relationships_cw_upload_document(
            'Company',
            (string) $c['connectwise_id'],
            $solution['name'] . ' - ' . $f['original_filename'],
            $path,
            (string) $f['original_filename'],
            'Camera layout photo (cameras numbered) from the Solutions Hub solution "' . $solution['name'] . '", saved by ' . $uploaderName . '. Open the solution: ' . relationships_solution_link((int) $solution['id']),
            'image/jpeg'
        );
        $set('uploaded', (string) $cwDoc['id'], null);
    } catch (Throwable $e) {
        error_log('[relationships/solutions] ConnectWise attach failed for file ' . $fileId . ': ' . $e->getMessage());
        $set('failed', null, mb_substr($e->getMessage(), 0, 500));
    }
}

function relationships_solution_files(PDO $pdo, int $solutionId): array
{
    $stmt = $pdo->prepare('SELECT id, kind, ref, original_filename, size_bytes, uploaded_at, cw_upload_status, cw_upload_error FROM solution_files WHERE solution_id = :s ORDER BY id ASC');
    $stmt->execute([':s' => $solutionId]);
    return array_map(static function (array $f): array {
        $ext = strtolower((string) pathinfo((string) $f['original_filename'], PATHINFO_EXTENSION));
        return [
            'id' => (int) $f['id'], 'kind' => $f['kind'], 'ref' => $f['ref'],
            'name' => $f['original_filename'], 'size_bytes' => (int) $f['size_bytes'], 'uploaded_at' => $f['uploaded_at'],
            'cw_upload_status' => $f['cw_upload_status'], 'cw_upload_error' => $f['cw_upload_error'],
            'is_image' => in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true),
            'url' => 'api/solutions.php?action=file&id=' . (int) $f['id'],
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));
}

function relationships_solution_row(PDO $pdo, array $r, bool $withFiles, ?array $viewer = null): array
{
    $pillars = json_decode((string) ($r['pillars'] ?? '[]'), true);
    $out = [
        'id' => (int) $r['id'],
        'customer_id' => (int) $r['customer_id'],
        'customer_name' => $r['customer_name'] ?? null,
        'customer_cw_id' => $r['customer_cw_id'] ?? null,
        'name' => $r['name'],
        'pillars' => is_array($pillars) ? array_values($pillars) : [],
        'created_at' => $r['created_at'],
        'created_by_name' => $r['created_by_name'],
        'updated_at' => $r['updated_at'],
        'updated_by_name' => $r['updated_by_name'],
        'file_count' => (int) ($r['file_count'] ?? 0),
        'can_delete' => $viewer !== null && (((int) $r['created_by_user_id'] === (int) $viewer['id']) || relationships_is_territory_admin_email($viewer['email'] ?? '')),
    ];
    $out['link'] = relationships_solution_link((int) $r['id']);
    $out['cw_links'] = relationships_solution_cw_links($pdo, (int) $r['id']);
    if ($withFiles) {
        $out['files'] = relationships_solution_files($pdo, (int) $r['id']);
    }
    return $out;
}

const RELATIONSHIPS_SOLUTION_SELECT =
    'SELECT s.*, c.name AS customer_name, c.connectwise_id AS customer_cw_id,
            (SELECT COUNT(*) FROM solution_files f WHERE f.solution_id = s.id AND f.kind = \'attachment\') AS file_count
     FROM customer_solutions s JOIN customers c ON c.id = s.customer_id';

function relationships_solution_load(PDO $pdo, ?array $allowedTerritories, int $id): array
{
    $stmt = $pdo->prepare(RELATIONSHIPS_SOLUTION_SELECT . ' WHERE s.id = :id');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        relationships_respond(404, ['ok' => false, 'error' => 'Solution not found.']);
    }
    relationships_solution_customer($pdo, $allowedTerritories, (int) $row['customer_id']);
    return $row;
}

function relationships_solution_rm_dir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $f) {
        if ($f !== '.' && $f !== '..' && is_file($dir . '/' . $f)) {
            @unlink($dir . '/' . $f);
        }
    }
    @rmdir($dir);
}

// ---------------------------------------------------------------- list
if ($action === 'list') {
    $customerId = (int) ($_GET['customer_id'] ?? 0);
    $q = trim((string) ($_GET['q'] ?? ''));
    $where = [];
    $params = [];
    if ($customerId > 0) {
        relationships_solution_customer($pdo, $allowedTerritories, $customerId);
        // also include solutions saved on another customer record with the same ConnectWise company id (duplicates)
        $where[] = '(s.customer_id = :cid OR (c.connectwise_id IS NOT NULL AND c.connectwise_id <> \'\' AND c.connectwise_id = (SELECT connectwise_id FROM customers WHERE id = :cid2)))';
        $params[':cid2'] = $customerId;
        $params[':cid'] = $customerId;
    } elseif ($allowedTerritories !== null) {
        $tf = relationships_territory_filter_sql($allowedTerritories, 'c');
        $where[] = ltrim(substr($tf['sql'], 3)); // strip the leading "AND"
        $params = array_merge($params, $tf['params']);
    }
    if ($q !== '') {
        $where[] = '(s.name LIKE :q1 OR s.search_text LIKE :q2 OR c.name LIKE :q3 OR s.created_by_name LIKE :q4 OR s.pillars LIKE :q5)';
        $like = '%' . $q . '%';
        $params += [':q1' => $like, ':q2' => $like, ':q3' => $like, ':q4' => $like, ':q5' => $like];
    }
    $sql = RELATIONSHIPS_SOLUTION_SELECT . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY s.created_at DESC, s.id DESC LIMIT 300';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = array_map(static fn (array $r): array => relationships_solution_row($pdo, $r, false, $user), $stmt->fetchAll(PDO::FETCH_ASSOC));
    relationships_respond(200, ['ok' => true, 'solutions' => $rows]);
}

// ---------------------------------------------------------------- get
if ($action === 'get') {
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) {
        relationships_respond(400, ['ok' => false, 'error' => 'Missing/invalid id.']);
    }
    $row = relationships_solution_load($pdo, $allowedTerritories, $id);
    $out = relationships_solution_row($pdo, $row, true, $user);
    $state = json_decode((string) $row['state_json'], true);
    $out['state'] = is_array($state) ? $state : new stdClass();
    relationships_respond(200, ['ok' => true, 'solution' => $out]);
}

// ---------------------------------------------------------------- file
if ($action === 'file') {
    $id = (int) ($_GET['id'] ?? 0);
    $stmt = $pdo->prepare('SELECT f.*, s.customer_id FROM solution_files f JOIN customer_solutions s ON s.id = f.solution_id WHERE f.id = :id');
    $stmt->execute([':id' => $id]);
    $f = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($f === false) {
        relationships_respond(404, ['ok' => false, 'error' => 'File not found.']);
    }
    relationships_solution_customer($pdo, $allowedTerritories, (int) $f['customer_id']);
    $path = relationships_solution_dir((int) $f['customer_id'], (int) $f['solution_id']) . '/' . $f['stored_filename'];
    if (!is_file($path)) {
        relationships_respond(404, ['ok' => false, 'error' => 'That file is no longer on the server.']);
    }
    $safe = str_replace(['"', "\r", "\n", '/', '\\'], '', (string) $f['original_filename']);
    $ext = strtolower((string) pathinfo($safe, PATHINFO_EXTENSION));
    $mime = relationships_solution_types()[$ext] ?? 'application/octet-stream';
    $inline = in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true);
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: ' . $mime);
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $safe . '"; filename*=UTF-8\'\'' . rawurlencode($safe));
    header('Content-Length: ' . (string) filesize($path));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=300');
    readfile($path);
    exit;
}

// ---------------------------------------------------------------- projects (for the link picker)
if ($action === 'projects') {
    $row = relationships_solution_load($pdo, $allowedTerritories, (int) ($_GET['id'] ?? 0));
    $cwStmt = $pdo->prepare('SELECT connectwise_id FROM customers WHERE id = :id');
    $cwStmt->execute([':id' => $row['customer_id']]);
    $custCw = (string) ($cwStmt->fetchColumn() ?: '');
    try {
        $projects = relationships_cw_request('/project/projects', [
            'conditions' => '(board/id=' . RELATIONSHIPS_SOLUTION_PRESALES_BOARD . ')',
            'pageSize' => '200',
            'orderBy' => 'estimatedStart desc',
        ]);
    } catch (RelationshipsConnectWiseError $e) {
        relationships_respond(502, ['ok' => false, 'error' => 'ConnectWise: ' . $e->getMessage()]);
    }
    $closed = [];
    try {
        foreach (relationships_cw_request('/project/statuses', ['pageSize' => '200']) as $s) {
            if (isset($s['id'])) {
                $closed[(int) $s['id']] = !empty($s['closedFlag']);
            }
        }
    } catch (RelationshipsConnectWiseError $e) {
        // fail open: fall back to the status name below
    }
    $out = [];
    foreach ($projects as $p) {
        $sid = isset($p['status']['id']) ? (int) $p['status']['id'] : null;
        $sname = (string) ($p['status']['name'] ?? '');
        $isClosed = $sid !== null && isset($closed[$sid]) ? $closed[$sid] : (stripos($sname, 'closed') !== false);
        if ($isClosed || empty($p['id'])) {
            continue;
        }
        $out[] = [
            'id' => (int) $p['id'], 'name' => (string) ($p['name'] ?? ''), 'company_name' => (string) ($p['company']['name'] ?? ''),
            'status_name' => $sname,
            'same_customer' => $custCw !== '' && isset($p['company']['id']) && (string) $p['company']['id'] === $custCw,
        ];
    }
    usort($out, static fn (array $a, array $b): int => ($b['same_customer'] <=> $a['same_customer']) ?: strcasecmp($a['company_name'] . $a['name'], $b['company_name'] . $b['name']));
    relationships_respond(200, ['ok' => true, 'projects' => $out]);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    relationships_respond(405, ['ok' => false, 'error' => 'Method not allowed.']);
}

// ---------------------------------------------------------------- save
if ($action === 'save') {
    $id = (int) ($_POST['id'] ?? 0);
    $customerId = (int) ($_POST['customer_id'] ?? 0);
    $name = trim((string) ($_POST['name'] ?? ''));
    if ($name === '') {
        relationships_respond(400, ['ok' => false, 'error' => 'Give the solution a name.']);
    }
    $name = mb_substr($name, 0, 200);
    $stateRaw = (string) ($_POST['state'] ?? '');
    if ($stateRaw === '' || strlen($stateRaw) > RELATIONSHIPS_SOLUTION_STATE_MAX_BYTES || !is_array(json_decode($stateRaw, true))) {
        relationships_respond(400, ['ok' => false, 'error' => 'The solution data was missing or too large to save.']);
    }
    $pillarsIn = json_decode((string) ($_POST['pillars'] ?? '[]'), true);
    $pillars = [];
    foreach (is_array($pillarsIn) ? $pillarsIn : [] as $p) {
        if (is_string($p) && trim($p) !== '') {
            $pillars[] = mb_substr(trim($p), 0, 80);
        }
    }
    $searchText = mb_substr((string) ($_POST['search_text'] ?? ''), 0, 20000);

    $existing = null;
    if ($id > 0) {
        $existing = relationships_solution_load($pdo, $allowedTerritories, $id);
        if ($customerId <= 0) {
            $customerId = (int) $existing['customer_id'];
        }
    }
    if ($customerId <= 0) {
        relationships_respond(400, ['ok' => false, 'error' => 'Choose the customer this solution is for.']);
    }
    relationships_solution_customer($pdo, $allowedTerritories, $customerId);
    if ($existing !== null && (int) $existing['customer_id'] !== $customerId) {
        relationships_respond(400, ['ok' => false, 'error' => 'A saved solution can\'t be moved to a different customer. Save it as a new solution instead.']);
    }

    // Validate the uploads first so a bad file doesn't leave a half-saved solution behind.
    $types = relationships_solution_types();
    $uploads = [];
    $fl = $_FILES['files'] ?? null;
    if ($fl && is_array($fl['name'])) {
        $kinds = $_POST['file_kinds'] ?? [];
        $refs = $_POST['file_refs'] ?? [];
        foreach ($fl['name'] as $i => $origRaw) {
            $err = (int) $fl['error'][$i];
            $orig = trim(basename(str_replace('\\', '/', (string) $origRaw)));
            if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
                relationships_respond(400, ['ok' => false, 'error' => '"' . $orig . '" is too large for the server to accept.']);
            }
            if ($err !== UPLOAD_ERR_OK) {
                relationships_respond(400, ['ok' => false, 'error' => 'Upload of "' . $orig . '" failed (error ' . $err . '). Please try again.']);
            }
            $size = (int) $fl['size'][$i];
            if ($size <= 0 || $size > RELATIONSHIPS_SOLUTION_FILE_MAX_BYTES) {
                relationships_respond(400, ['ok' => false, 'error' => '"' . $orig . '" is empty or larger than 100 MB.']);
            }
            $ext = strtolower((string) pathinfo($orig, PATHINFO_EXTENSION));
            if ($orig === '' || !isset($types[$ext])) {
                relationships_respond(400, ['ok' => false, 'error' => '"' . $orig . '" isn\'t an accepted file type. Allowed: ' . implode(', ', array_keys($types)) . '.']);
            }
            $head = (string) @file_get_contents($fl['tmp_name'][$i], false, null, 0, 5);
            if ($ext === 'pdf' && substr($head, 0, 4) !== '%PDF') {
                relationships_respond(400, ['ok' => false, 'error' => '"' . $orig . '" doesn\'t look like a valid PDF.']);
            }
            if (in_array($ext, ['docx', 'xlsx', 'xlsm', 'pptx', 'zip', 'odt', 'ods'], true) && substr($head, 0, 2) !== 'PK') {
                relationships_respond(400, ['ok' => false, 'error' => '"' . $orig . '" doesn\'t look like a valid .' . $ext . ' file.']);
            }
            $kindIn = (string) ($kinds[$i] ?? 'attachment');
            $kind = in_array($kindIn, ['camera_photo', 'camera_marked'], true) ? $kindIn : 'attachment';
            $uploads[] = ['tmp' => $fl['tmp_name'][$i], 'orig' => $orig, 'ext' => $ext, 'size' => $size, 'kind' => $kind, 'ref' => $kind !== 'attachment' ? mb_substr((string) ($refs[$i] ?? ''), 0, 80) : null];
        }
    }

    $keepIn = json_decode((string) ($_POST['keep_file_ids'] ?? '[]'), true);
    $keep = array_values(array_filter(array_map('intval', is_array($keepIn) ? $keepIn : []), static fn (int $x): bool => $x > 0));

    $newMarkedIds = [];
    $pdo->beginTransaction();
    try {
        if ($existing === null) {
            $pdo->prepare(
                'INSERT INTO customer_solutions (customer_id, name, pillars, search_text, state_json, created_by_user_id, created_by_name, updated_by_user_id, updated_by_name)
                 VALUES (:c, :n, :p, :s, :j, :u, :un, :u, :un)'
            )->execute([':c' => $customerId, ':n' => $name, ':p' => json_encode($pillars), ':s' => $searchText, ':j' => $stateRaw, ':u' => $user['id'], ':un' => $user['name']]);
            $id = (int) $pdo->lastInsertId();
        } else {
            $pdo->prepare(
                'UPDATE customer_solutions SET name = :n, pillars = :p, search_text = :s, state_json = :j,
                        updated_at = datetime(\'now\'), updated_by_user_id = :u, updated_by_name = :un WHERE id = :id'
            )->execute([':n' => $name, ':p' => json_encode($pillars), ':s' => $searchText, ':j' => $stateRaw, ':u' => $user['id'], ':un' => $user['name'], ':id' => $id]);
        }

        // Drop files that are no longer wanted (removed attachments, replaced / deleted camera photos).
        $dir = relationships_solution_dir($customerId, $id);
        $cur = $pdo->prepare('SELECT id, stored_filename FROM solution_files WHERE solution_id = :s');
        $cur->execute([':s' => $id]);
        $toUnlink = [];
        foreach ($cur->fetchAll(PDO::FETCH_ASSOC) as $f) {
            if (!in_array((int) $f['id'], $keep, true)) {
                $pdo->prepare('DELETE FROM solution_files WHERE id = :id')->execute([':id' => $f['id']]);
                $toUnlink[] = $dir . '/' . $f['stored_filename'];
            }
        }

        if ($uploads && !is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create the storage folder for this solution.');
        }
        $newPaths = [];
        foreach ($uploads as $u) {
            $stored = date('Ymd-His') . '_' . bin2hex(random_bytes(6)) . '.' . $u['ext'];
            if (!move_uploaded_file($u['tmp'], $dir . '/' . $stored)) {
                throw new RuntimeException('Could not save "' . $u['orig'] . '".');
            }
            $newPaths[] = $dir . '/' . $stored;
            $pdo->prepare(
                'INSERT INTO solution_files (solution_id, kind, ref, original_filename, stored_filename, size_bytes, uploaded_by_name)
                 VALUES (:s, :k, :r, :o, :st, :z, :un)'
            )->execute([':s' => $id, ':k' => $u['kind'], ':r' => $u['ref'], ':o' => $u['orig'], ':st' => $stored, ':z' => $u['size'], ':un' => $user['name']]);
            if ($u['kind'] === 'camera_marked') {
                $newMarkedIds[] = (int) $pdo->lastInsertId();
            }
        }
        $pdo->commit();
        foreach ($toUnlink as $p) {
            @unlink($p);
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        foreach ($newPaths ?? [] as $p) {
            @unlink($p);
        }
        error_log('[solutions-save] ' . $e->getMessage());
        relationships_respond(500, ['ok' => false, 'error' => 'Could not save the solution: ' . $e->getMessage()]);
    }

    $row = relationships_solution_load($pdo, $allowedTerritories, $id);
    // Attach each NEW marked-up camera photo to the customer's ConnectWise company (unchanged photos keep their earlier attachment).
    foreach ($newMarkedIds as $mid) {
        relationships_solution_file_push_to_cw($pdo, $mid, $row, (string) $user['name']);
    }
    relationships_respond(200, ['ok' => true, 'solution' => relationships_solution_row($pdo, $row, true, $user), 'created' => $existing === null]);
}

// ---------------------------------------------------------------- link_project
if ($action === 'link_project') {
    $data = relationships_read_json_body();
    $id = (int) ($data['id'] ?? 0);
    $projectId = (int) ($data['project_id'] ?? 0);
    $projectName = mb_substr(trim((string) ($data['project_name'] ?? '')), 0, 200);
    if ($id <= 0 || $projectId <= 0) {
        relationships_respond(400, ['ok' => false, 'error' => 'Choose a project to link to.']);
    }
    $row = relationships_solution_load($pdo, $allowedTerritories, $id);
    $note = 'Solution: ' . $row['name'] . ' - ' . $row['customer_name'] . "\n" . relationships_solution_link($id);
    $pdo->prepare('INSERT INTO solution_cw_links (solution_id, cw_project_id, project_name, push_status, linked_by_name) VALUES (:s, :p, :n, \'pending\', :u)')
        ->execute([':s' => $id, ':p' => $projectId, ':n' => $projectName, ':u' => $user['name']]);
    $linkId = (int) $pdo->lastInsertId();
    $warning = null;
    try {
        $created = relationships_cw_request("/project/projects/$projectId/notes", [], 'POST', [
            'text' => '[' . $user['name'] . '] ' . $note,
            'type' => ['id' => 2], // "Comment", as in projects.php notes_add
        ]);
        $pdo->prepare("UPDATE solution_cw_links SET push_status = 'pushed', cw_note_id = :n WHERE id = :id")
            ->execute([':n' => isset($created['id']) ? (string) $created['id'] : null, ':id' => $linkId]);
    } catch (RelationshipsConnectWiseError $e) {
        $pdo->prepare("UPDATE solution_cw_links SET push_status = 'failed', push_error = :e WHERE id = :id")->execute([':e' => $e->getMessage(), ':id' => $linkId]);
        $warning = 'The link was recorded here, but ConnectWise did not accept the note: ' . $e->getMessage();
    }
    $resp = ['ok' => true, 'links' => relationships_solution_cw_links($pdo, $id)];
    if ($warning !== null) {
        $resp['cw_warning'] = $warning;
    }
    relationships_respond(200, $resp);
}

// ---------------------------------------------------------------- retry_cw_file
if ($action === 'retry_cw_file') {
    $data = relationships_read_json_body();
    $fid = (int) ($data['file_id'] ?? 0);
    $q = $pdo->prepare('SELECT f.id, f.kind, f.cw_upload_status, f.solution_id FROM solution_files f WHERE f.id = :id');
    $q->execute([':id' => $fid]);
    $f = $q->fetch(PDO::FETCH_ASSOC);
    if ($f === false || $f['kind'] !== 'camera_marked') {
        relationships_respond(404, ['ok' => false, 'error' => 'File not found.']);
    }
    $row = relationships_solution_load($pdo, $allowedTerritories, (int) $f['solution_id']);
    if ($f['cw_upload_status'] !== 'uploaded') {
        relationships_solution_file_push_to_cw($pdo, $fid, $row, (string) $user['name']);
    }
    relationships_respond(200, ['ok' => true, 'files' => relationships_solution_files($pdo, (int) $row['id'])]);
}

// ---------------------------------------------------------------- delete
if ($action === 'delete') {
    $data = relationships_read_json_body();
    $id = (int) ($data['id'] ?? 0);
    if ($id <= 0) {
        relationships_respond(400, ['ok' => false, 'error' => 'Missing/invalid id.']);
    }
    $row = relationships_solution_load($pdo, $allowedTerritories, $id);
    $isOwner = (int) $row['created_by_user_id'] === (int) $user['id'];
    if (!$isOwner && !relationships_is_territory_admin_email($user['email'] ?? '')) {
        relationships_respond(403, ['ok' => false, 'error' => 'Only ' . ($row['created_by_name'] ?: 'the rep who saved this solution') . ' (or an administrator) can delete it.']);
    }
    $pdo->prepare('DELETE FROM solution_files WHERE solution_id = :id')->execute([':id' => $id]);
    $pdo->prepare('DELETE FROM solution_cw_links WHERE solution_id = :id')->execute([':id' => $id]);
    $pdo->prepare('DELETE FROM customer_solutions WHERE id = :id')->execute([':id' => $id]);
    relationships_solution_rm_dir(relationships_solution_dir((int) $row['customer_id'], $id));
    relationships_respond(200, ['ok' => true]);
}

relationships_respond(400, ['ok' => false, 'error' => 'Unknown action.']);
