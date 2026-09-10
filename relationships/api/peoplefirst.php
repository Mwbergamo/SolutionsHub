<?php
/**
 * relationships/api/peoplefirst.php
 *
 * Tracking for the two PeopleFirst obligations CodeBlue commits to for its
 * top-tier customers (customers.is_peoplefirst = 1 -- see
 * connectwise-sync-core.php): a monthly client checkin and a quarterly risk
 * scan. Each customer only ever has ONE "most recent" checkin and ONE
 * "most recent" scan (customers.last_client_checkin_at/last_risk_scan_at,
 * + _by) -- there's no history of past ones, only "when did we last do it".
 *
 * "Needs a checkin this month" = last_client_checkin_at is empty, or falls
 * in an earlier calendar month than today. "Needs a risk scan this
 * quarter" = last_risk_scan_at is empty, or falls in an earlier calendar
 * quarter than today. Both reset to "needed" the moment the calendar
 * flips into a new month/quarter, regardless of how recently it was
 * actually done within the prior period.
 *
 * GET  /relationships/api/peoplefirst.php?action=summary
 *   -> { ok: true, total: N, needs_checkin: N, needs_scan: N }
 *
 * GET  /relationships/api/peoplefirst.php?action=queue&type=checkin|scan
 *   -> { ok: true, type: 'checkin'|'scan',
 *        customers: [{ id, name, last_at, last_by }, ...] }
 *   (last_at/last_by are the relevant field for that type -- null if never
 *   logged. Only customers currently "needing" that type are included.)
 *
 * POST /relationships/api/peoplefirst.php?action=log
 *   { customer_id, type: 'checkin'|'scan' }
 *   -> { ok: true, customer: { id, name, is_peoplefirst,
 *                                last_client_checkin_at, last_client_checkin_by,
 *                                last_risk_scan_at, last_risk_scan_by } }
 *   Stamps "now" + the signed-in CRC's name onto the relevant field. Only
 *   valid for a customer that is actually is_peoplefirst = 1.
 */

declare(strict_types=1);

require_once __DIR__ . '/_util.php';

$pdo = relationships_db();
$user = relationships_require_login($pdo);

$action = $_GET['action'] ?? '';

/**
 * All PeopleFirst customers, with a computed need_checkin/need_scan flag
 * per the calendar-month / calendar-quarter rule above. Recomputed per
 * request -- fine at CodeBlue's customer-count scale (same reasoning as
 * checklist.php's relationships_missing_services_with_progress()).
 */
function relationships_peoplefirst_status(PDO $pdo): array
{
    $rows = $pdo->query(
        "SELECT id, name, last_client_checkin_at, last_client_checkin_by, last_risk_scan_at, last_risk_scan_by
         FROM customers WHERE is_peoplefirst = 1 ORDER BY name ASC"
    )->fetchAll(PDO::FETCH_ASSOC);

    $now = new DateTimeImmutable('now');
    $curMonthKey = $now->format('Y-m');
    $curQuarterKey = $now->format('Y') . '-Q' . (int) ceil(((int) $now->format('n')) / 3);

    $monthKey = static function (?string $raw): ?string {
        if (!$raw) return null;
        $d = date_create($raw);
        return $d ? $d->format('Y-m') : null;
    };
    $quarterKey = static function (?string $raw): ?string {
        if (!$raw) return null;
        $d = date_create($raw);
        if (!$d) return null;
        return $d->format('Y') . '-Q' . (int) ceil(((int) $d->format('n')) / 3);
    };

    foreach ($rows as &$r) {
        $r['id'] = (int) $r['id'];
        $r['need_checkin'] = $monthKey($r['last_client_checkin_at']) !== $curMonthKey;
        $r['need_scan'] = $quarterKey($r['last_risk_scan_at']) !== $curQuarterKey;
    }
    unset($r);

    return $rows;
}

if ($action === 'summary') {
    $rows = relationships_peoplefirst_status($pdo);
    $needsCheckin = count(array_filter($rows, static fn (array $r): bool => $r['need_checkin']));
    $needsScan = count(array_filter($rows, static fn (array $r): bool => $r['need_scan']));
    relationships_respond(200, [
        'ok' => true,
        'total' => count($rows),
        'needs_checkin' => $needsCheckin,
        'needs_scan' => $needsScan,
    ]);
}

if ($action === 'queue') {
    $type = (string) ($_GET['type'] ?? '');
    if ($type !== 'checkin' && $type !== 'scan') {
        relationships_respond(400, ['ok' => false, 'error' => 'type must be "checkin" or "scan".']);
    }

    $rows = relationships_peoplefirst_status($pdo);
    $needKey = $type === 'checkin' ? 'need_checkin' : 'need_scan';
    $atField = $type === 'checkin' ? 'last_client_checkin_at' : 'last_risk_scan_at';
    $byField = $type === 'checkin' ? 'last_client_checkin_by' : 'last_risk_scan_by';

    $customers = [];
    foreach ($rows as $r) {
        if (!$r[$needKey]) {
            continue;
        }
        $customers[] = ['id' => $r['id'], 'name' => $r['name'], 'last_at' => $r[$atField], 'last_by' => $r[$byField]];
    }

    relationships_respond(200, ['ok' => true, 'type' => $type, 'customers' => $customers]);
}

if ($action === 'log') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        relationships_respond(405, ['ok' => false, 'error' => 'Method not allowed.']);
    }
    $data = relationships_read_json_body();
    $customerId = (int) ($data['customer_id'] ?? 0);
    $type = (string) ($data['type'] ?? '');
    if ($customerId <= 0 || ($type !== 'checkin' && $type !== 'scan')) {
        relationships_respond(400, ['ok' => false, 'error' => 'Missing/invalid customer_id or type.']);
    }

    $custStmt = $pdo->prepare('SELECT id, is_peoplefirst FROM customers WHERE id = :id');
    $custStmt->execute([':id' => $customerId]);
    $cust = $custStmt->fetch(PDO::FETCH_ASSOC);
    if ($cust === false) {
        relationships_respond(404, ['ok' => false, 'error' => 'Customer not found.']);
    }
    if (!$cust['is_peoplefirst']) {
        relationships_respond(400, ['ok' => false, 'error' => 'This customer is not a PeopleFirst member.']);
    }

    $atCol = $type === 'checkin' ? 'last_client_checkin_at' : 'last_risk_scan_at';
    $byCol = $type === 'checkin' ? 'last_client_checkin_by' : 'last_risk_scan_by';

    $upd = $pdo->prepare("UPDATE customers SET $atCol = datetime('now'), $byCol = :by WHERE id = :id");
    $upd->execute([':by' => $user['name'], ':id' => $customerId]);

    $outStmt = $pdo->prepare(
        'SELECT id, name, is_peoplefirst, last_client_checkin_at, last_client_checkin_by, last_risk_scan_at, last_risk_scan_by
         FROM customers WHERE id = :id'
    );
    $outStmt->execute([':id' => $customerId]);
    $out = $outStmt->fetch(PDO::FETCH_ASSOC);

    relationships_respond(200, [
        'ok' => true,
        'customer' => [
            'id' => (int) $out['id'],
            'name' => $out['name'],
            'is_peoplefirst' => (bool) $out['is_peoplefirst'],
            'last_client_checkin_at' => $out['last_client_checkin_at'],
            'last_client_checkin_by' => $out['last_client_checkin_by'],
            'last_risk_scan_at' => $out['last_risk_scan_at'],
            'last_risk_scan_by' => $out['last_risk_scan_by'],
        ],
    ]);
}

relationships_respond(400, ['ok' => false, 'error' => 'Unknown action.']);
