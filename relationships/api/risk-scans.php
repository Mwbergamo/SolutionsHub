<?php
/**
 * relationships/api/risk-scans.php
 *
 * Risk-scan file uploads -- added 2026-09-23 per Michael: a service team
 * member uploads a customer's zip of risk-scan output here, from that
 * customer's dashboard; any rep can then download it to review. This is
 * deliberately open to any signed-in Relationships user (AskUserQuestion,
 * 2026-09-23 -- everyone here already shares one company Microsoft 365
 * login, so a separate "service team" permission list wasn't worth
 * building), and open for ANY customer, not only PeopleFirst members --
 * see the 'upload' action below for how it interacts with the existing
 * PeopleFirst quarterly risk-scan date tracker (peoplefirst.php) when the
 * customer IS a PeopleFirst member.
 *
 * "Alert appears (unassigned) in the master list" (Michael's words) is
 * NOT a meeting_tasks row -- that table requires a real meeting_id and
 * one of the 7 fixed roster names, so a genuine "nobody's claimed this
 * yet" state doesn't exist there (AskUserQuestion, 2026-09-23, confirmed).
 * Instead every upload is simply a risk_scans row with reviewed_at = NULL
 * until a rep marks it reviewed; the Global To-Do Checklist
 * (meetings.php's 'global' action, relationships_global_risk_scan_alerts()
 * below) reads the still-open ones straight from this table and renders
 * them alongside real to-dos. "Reviewed" is CodeBlue's rep completing the
 * scan evaluation, not merely downloading the file -- download and
 * mark_reviewed are separate actions on purpose.
 *
 * The uploaded files themselves live on disk under
 * data/risk-scans/<customer_id>/ -- data/.htaccess already blocks all
 * direct HTTP access to everything under data/ (same protection the
 * SQLite database relies on), and that block applies to this new
 * subfolder too since it's just a plain Apache Deny-from-all with no
 * competing rule underneath. The ONLY way to read a file back out is the
 * 'download' action below, which streams it through PHP after the normal
 * login + territory checks. See risk-scan-purge.php for the 1-year
 * retention cron that deletes both the row and the file.
 *
 * GET  /relationships/api/risk-scans.php?action=list&customer_id=1
 *   -> { ok: true, scans: [ { id, original_filename, size_bytes,
 *        uploaded_by_name, uploaded_at, reviewed_at, reviewed_by_name },
 *        ... ] (newest upload first) }
 *
 * POST /relationships/api/risk-scans.php?action=upload
 *   multipart/form-data: customer_id, file (must end in .zip)
 *   -> { ok: true, scan: {...}, customer: { last_risk_scan_at,
 *        last_risk_scan_by } | null }
 *   customer is set (and the PeopleFirst tracker stamped to "now") only
 *   when this customer is actually is_peoplefirst = 1 -- a scan uploaded
 *   for a non-PeopleFirst customer still saves and still alerts, it just
 *   has no quarterly-tracker field to update.
 *
 * GET  /relationships/api/risk-scans.php?action=download&id=5
 *   Streams the zip (Content-Disposition: attachment; original filename).
 *   Does NOT mark the scan reviewed -- see mark_reviewed below.
 *
 * POST /relationships/api/risk-scans.php?action=mark_reviewed
 *   { id } -> { ok: true, scan: {...} }
 *   Closes the alert -- stamps reviewed_at/reviewed_by_name to the
 *   signed-in user. Whoever gets to it first closes it for everyone,
 *   same "first click wins" behavior as a meeting to-do checkbox.
 *
 * POST /relationships/api/risk-scans.php?action=unmark_reviewed
 *   { id } -> { ok: true, scan: {...} }
 *   Reopens it (clears reviewed_at/reviewed_by_name) -- for an accidental
 *   click, same reversibility as the cross-sell Kill Opportunity toggle.
 */

declare(strict_types=1);

require_once __DIR__ . '/_util.php';
require_once __DIR__ . '/territory-access.php';

$pdo = relationships_db();
$user = relationships_require_login($pdo);
$allowedTerritories = relationships_allowed_territories($pdo);

$action = $_GET['action'] ?? '';

// Max accepted upload size, enforced here IN ADDITION to whatever
// upload_max_filesize/post_max_size the server's PHP is configured with
// (see the accompanying .user.ini, which raises those to comfortably
// clear this) -- a clear application-level error beats a bare, confusing
// PHP-level rejection.
const RELATIONSHIPS_RISK_SCAN_MAX_BYTES = 300 * 1024 * 1024; // 300 MB

function relationships_risk_scan_row(array $r): array
{
    return [
        'id' => (int) $r['id'],
        'customer_id' => (int) $r['customer_id'],
        'original_filename' => $r['original_filename'],
        'size_bytes' => (int) $r['size_bytes'],
        'uploaded_by_name' => $r['uploaded_by_name'],
        'uploaded_at' => $r['uploaded_at'],
        'reviewed_at' => $r['reviewed_at'],
        'reviewed_by_name' => $r['reviewed_by_name'],
    ];
}

function relationships_risk_scan_dir(int $customerId): string
{
    return __DIR__ . '/../data/risk-scans/' . $customerId;
}

function relationships_require_customer_in_scope(PDO $pdo, ?array $allowedTerritories, int $customerId): array
{
    $stmt = $pdo->prepare('SELECT id, name, is_peoplefirst, territory_name FROM customers WHERE id = :id');
    $stmt->execute([':id' => $customerId]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($customer === false) {
        relationships_respond(404, ['ok' => false, 'error' => 'Customer not found.']);
    }
    relationships_require_territory_scope($allowedTerritories, $customer['territory_name']);
    return $customer;
}

if ($action === 'list') {
    $customerId = (int) ($_GET['customer_id'] ?? 0);
    if ($customerId <= 0) {
        relationships_respond(400, ['ok' => false, 'error' => 'Missing/invalid customer_id.']);
    }
    relationships_require_customer_in_scope($pdo, $allowedTerritories, $customerId);

    $stmt = $pdo->prepare(
        'SELECT id, customer_id, original_filename, size_bytes, uploaded_by_name, uploaded_at, reviewed_at, reviewed_by_name
         FROM risk_scans WHERE customer_id = :cid ORDER BY uploaded_at DESC'
    );
    $stmt->execute([':cid' => $customerId]);
    $scans = array_map('relationships_risk_scan_row', $stmt->fetchAll(PDO::FETCH_ASSOC));

    relationships_respond(200, ['ok' => true, 'scans' => $scans]);
}

if ($action === 'download') {
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) {
        relationships_respond(400, ['ok' => false, 'error' => 'Missing/invalid id.']);
    }
    $stmt = $pdo->prepare('SELECT * FROM risk_scans WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $scan = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($scan === false) {
        relationships_respond(404, ['ok' => false, 'error' => 'Scan not found.']);
    }
    relationships_require_customer_in_scope($pdo, $allowedTerritories, (int) $scan['customer_id']);

    $path = relationships_risk_scan_dir((int) $scan['customer_id']) . '/' . $scan['stored_filename'];
    if (!is_file($path)) {
        relationships_respond(404, ['ok' => false, 'error' => 'That file is no longer on the server (it may have been purged after a year).']);
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $scan['original_filename']) . '"');
    header('Content-Length: ' . (string) filesize($path));
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    relationships_respond(405, ['ok' => false, 'error' => 'Method not allowed.']);
}

if ($action === 'upload') {
    $customerId = (int) ($_POST['customer_id'] ?? 0);
    if ($customerId <= 0) {
        relationships_respond(400, ['ok' => false, 'error' => 'Missing/invalid customer_id.']);
    }
    $customer = relationships_require_customer_in_scope($pdo, $allowedTerritories, $customerId);

    if (!isset($_FILES['file'])) {
        relationships_respond(400, ['ok' => false, 'error' => 'No file received.']);
    }
    $file = $_FILES['file'];
    if ((int) $file['error'] === UPLOAD_ERR_INI_SIZE || (int) $file['error'] === UPLOAD_ERR_FORM_SIZE) {
        relationships_respond(400, ['ok' => false, 'error' => 'That file is too large for the server to accept.']);
    }
    if ((int) $file['error'] !== UPLOAD_ERR_OK) {
        relationships_respond(400, ['ok' => false, 'error' => 'Upload failed (error code ' . (int) $file['error'] . '). Please try again.']);
    }
    if ((int) $file['size'] <= 0) {
        relationships_respond(400, ['ok' => false, 'error' => 'That file is empty.']);
    }
    if ((int) $file['size'] > RELATIONSHIPS_RISK_SCAN_MAX_BYTES) {
        relationships_respond(400, ['ok' => false, 'error' => 'That file is larger than the 300 MB limit.']);
    }
    $originalName = trim((string) $file['name']);
    if ($originalName === '' || strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION)) !== 'zip') {
        relationships_respond(400, ['ok' => false, 'error' => 'Only .zip files are accepted.']);
    }
    // Cheap sanity check that this is actually a zip (or at least starts
    // like one -- "PK\x03\x04" for a normal archive, "PK\x05\x06" for an
    // empty one) rather than trusting the client-supplied extension alone.
    $magic = @file_get_contents($file['tmp_name'], false, null, 0, 4);
    if ($magic === false || substr($magic, 0, 2) !== 'PK') {
        relationships_respond(400, ['ok' => false, 'error' => 'That file doesn’t look like a valid zip archive.']);
    }

    $dir = relationships_risk_scan_dir($customerId);
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        relationships_respond(500, ['ok' => false, 'error' => 'Could not create storage folder for this customer.']);
    }
    $storedName = date('Ymd-His') . '_' . bin2hex(random_bytes(6)) . '.zip';
    $dest = $dir . '/' . $storedName;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        relationships_respond(500, ['ok' => false, 'error' => 'Could not save the uploaded file.']);
    }

    $insert = $pdo->prepare(
        'INSERT INTO risk_scans (customer_id, original_filename, stored_filename, size_bytes, uploaded_by_user_id, uploaded_by_name)
         VALUES (:cid, :orig, :stored, :size, :uid, :uname)'
    );
    $insert->execute([
        ':cid' => $customerId, ':orig' => $originalName, ':stored' => $storedName,
        ':size' => (int) $file['size'], ':uid' => $user['id'], ':uname' => $user['name'],
    ]);
    $scanId = (int) $pdo->lastInsertId();

    // Also stamp the existing PeopleFirst quarterly tracker, same as
    // peoplefirst.php's 'log' action, but ONLY for an actual PeopleFirst
    // customer -- AskUserQuestion, 2026-09-23, confirmed uploading should
    // "also update[] the existing tracker"; that field is meaningless for
    // a non-PeopleFirst customer, so it's left alone there.
    $customerOut = null;
    if ((int) $customer['is_peoplefirst'] === 1) {
        $pdo->prepare("UPDATE customers SET last_risk_scan_at = datetime('now'), last_risk_scan_by = :by WHERE id = :id")
            ->execute([':by' => $user['name'], ':id' => $customerId]);
        $custStmt = $pdo->prepare('SELECT last_risk_scan_at, last_risk_scan_by FROM customers WHERE id = :id');
        $custStmt->execute([':id' => $customerId]);
        $customerOut = $custStmt->fetch(PDO::FETCH_ASSOC);
    }

    $scanStmt = $pdo->prepare('SELECT * FROM risk_scans WHERE id = :id');
    $scanStmt->execute([':id' => $scanId]);
    $scan = relationships_risk_scan_row($scanStmt->fetch(PDO::FETCH_ASSOC));

    relationships_respond(200, ['ok' => true, 'scan' => $scan, 'customer' => $customerOut]);
}

if ($action === 'mark_reviewed' || $action === 'unmark_reviewed') {
    $data = relationships_read_json_body();
    $id = (int) ($data['id'] ?? 0);
    if ($id <= 0) {
        relationships_respond(400, ['ok' => false, 'error' => 'Missing/invalid id.']);
    }
    $stmt = $pdo->prepare('SELECT * FROM risk_scans WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $scan = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($scan === false) {
        relationships_respond(404, ['ok' => false, 'error' => 'Scan not found.']);
    }
    relationships_require_customer_in_scope($pdo, $allowedTerritories, (int) $scan['customer_id']);

    if ($action === 'mark_reviewed') {
        $pdo->prepare('UPDATE risk_scans SET reviewed_at = datetime(\'now\'), reviewed_by_user_id = :uid, reviewed_by_name = :uname WHERE id = :id')
            ->execute([':uid' => $user['id'], ':uname' => $user['name'], ':id' => $id]);
    } else {
        $pdo->prepare('UPDATE risk_scans SET reviewed_at = NULL, reviewed_by_user_id = NULL, reviewed_by_name = NULL WHERE id = :id')
            ->execute([':id' => $id]);
    }

    $outStmt = $pdo->prepare('SELECT * FROM risk_scans WHERE id = :id');
    $outStmt->execute([':id' => $id]);
    relationships_respond(200, ['ok' => true, 'scan' => relationships_risk_scan_row($outStmt->fetch(PDO::FETCH_ASSOC))]);
}

relationships_respond(400, ['ok' => false, 'error' => 'Unknown action.']);
