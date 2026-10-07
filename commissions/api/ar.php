<?php
/**
 * commissions/api/ar.php  -- open invoices to collect (used by the Collections app)
 *
 * Access: auth/collections-access.php. Courtney / Trey / Michael / Kasie see every rep and
 * territory; Moe and Chester are limited to their own territories (enforced HERE, not just in the UI).
 *
 * GET  ?action=summary                          per rep + per territory: count, balance, aging
 * GET  ?action=detail&rep_id=<id|house|all>[&territory=NAME]
 *                                               customers (oldest first) -> invoices (oldest first)
 * POST ?action=email   body { rep_id, territory?, to, subject? }
 *                                               mails the same grouped report (Reply-To = signed-in user)
 * POST ?action=refresh                          re-reads unpaid invoices from ConnectWise (skipped if done < 5 min ago)
 * POST ?action=detail_step                      fills in agreement/ticket detail for a few invoices; returns { pending }
 */

declare(strict_types=1);

require_once __DIR__ . '/_util.php';
require_once __DIR__ . '/calc.php';
require_once __DIR__ . '/ar-core.php';

$user = auth_current_user();
$crcUserId = $_SESSION['crc_user_id'] ?? null; // set when the person has signed in to Relationships (every client relationship coordinator)
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
if ($user === null) {
    commissions_respond(401, ['ok' => false, 'error' => 'Not signed in.']);
}
$access = collections_access_for($user['email'] ?? '');
$bodyPeek = [];
$cwId = 0;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $bodyPeek = commissions_read_json_body();
    $cwId = (int) ($bodyPeek['cw_id'] ?? 0);
} else {
    $cwId = (int) ($_GET['cw_id'] ?? 0);
}
// Client-level (single customer) reports are open to every client relationship coordinator as well as Collections users.
$customerMode = $cwId > 0 && ($action0 = $_GET['action'] ?? '') !== '' && in_array($action0, ['customer', 'email', 'refresh', 'detail_step'], true);
if ($access === null && !$customerMode && !in_array($_GET['action'] ?? '', ['refresh', 'detail_step'], true)) {
    commissions_respond(403, ['ok' => false, 'error' => 'You do not have access to Collections.']);
}
if ($access === null && !is_int($crcUserId)) {
    commissions_respond(403, ['ok' => false, 'error' => 'You do not have access to Collections.']);
}
$user = ['name' => (string) ($user['name'] ?? ''), 'email' => (string) ($user['email'] ?? '')];

$pdo = commissions_db();
$action = $_GET['action'] ?? 'summary';
$today = commissions_ar_today();

/**
 * May this person see ONE customer's collections information?
 *  - Collections admins: yes. Moe / Chester: only customers in their own territories.
 *  - Any client relationship coordinator who can see that customer in Relationships (its territory rules).
 */
function ar_customer_allowed(?array $access, $crcUserId, string $email, int $cwId, ?string &$name): bool
{
    require_once __DIR__ . '/../../relationships/api/territory-access.php';
    $name = null;
    $territory = null;
    $rpdo = relationships_db();
    $q = $rpdo->prepare('SELECT name, territory_name FROM customers WHERE connectwise_id = :cw LIMIT 1');
    $q->execute([':cw' => (string) $cwId]);
    $c = $q->fetch(PDO::FETCH_ASSOC);
    if ($c !== false) {
        $name = (string) $c['name'];
        $territory = $c['territory_name'] !== null ? (string) $c['territory_name'] : null;
    }
    if ($access !== null) {
        if ($access['scope'] === 'all') {
            return true;
        }
        $list = array_map('strtolower', COLLECTIONS_REP_TERRITORIES[$access['rep_name']] ?? []);
        if ($territory !== null && in_array(strtolower(trim($territory)), $list, true)) {
            return true;
        }
    }
    if (!is_int($crcUserId)) {
        return false;
    }
    $u = $rpdo->prepare('SELECT 1 FROM crc_users WHERE id = :id');
    $u->execute([':id' => $crcUserId]);
    if ($u->fetchColumn() === false) {
        return false;
    }
    $allowed = relationships_allowed_territories_for_email($rpdo, $email);
    return $allowed === null || ($territory !== null && in_array($territory, $allowed, true));
}

// Restricted viewers (Moe, Chester): resolve their rep id once; everything below is filtered to it.
$onlyRepId = null;
if ($access !== null && $access['scope'] === 'rep') {
    $q = $pdo->prepare('SELECT id FROM reps WHERE name = :n');
    $q->execute([':n' => $access['rep_name']]);
    $found = $q->fetchColumn();
    if ($found === false) {
        commissions_respond(403, ['ok' => false, 'error' => 'Your rep record was not found. Ask Michael.']);
    }
    $onlyRepId = (int) $found;
}
$viewer = ['name' => $user['name'], 'scope' => $access['scope'] ?? 'customer', 'rep_name' => $access['rep_name'] ?? null];

$customerName = null;
if ($customerMode && !ar_customer_allowed($access, $crcUserId, $user['email'], $cwId, $customerName)) {
    commissions_respond(403, ['ok' => false, 'error' => 'You do not have rights to this customer’s collections information.']);
}

try {
    if ($action === 'summary') {
        $rows = commissions_ar_rows($pdo, false, $onlyRepId);
        $reps = array_values(array_filter(commissions_reps($pdo), static fn (array $r): bool => !empty($r['active']) && ($onlyRepId === null || (int) $r['id'] === $onlyRepId)));
        $byRep = [];
        foreach ($reps as $r) {
            $mine = array_values(array_filter($rows, static fn (array $x): bool => in_array((int) $r['id'], $x['rep_ids'], true)));
            $byRep[] = ['id' => (int) $r['id'], 'name' => $r['name'], 'email' => (string) ($r['email'] ?? '')] + commissions_ar_totals($mine);
        }
        $names = [];
        foreach (commissions_reps($pdo) as $r) {
            $names[(int) $r['id']] = $r['name'];
        }
        $terr = [];
        foreach ($rows as $x) {
            $k = $x['territory'] !== '' ? $x['territory'] : '(no territory)';
            $terr[$k]['rows'][] = $x;
            $terr[$k]['rep_ids'] = $x['rep_ids'];
        }
        $byTerritory = [];
        foreach ($terr as $name => $t) {
            $byTerritory[] = [
                'territory' => $name === '(no territory)' ? '' : $name,
                'label' => $name,
                'payees' => array_values(array_map(static fn (int $id): string => $names[$id] ?? '', $t['rep_ids'])),
                'house' => $t['rep_ids'] === [],
            ] + commissions_ar_totals($t['rows']);
        }
        usort($byTerritory, static fn (array $a, array $b): int => $b['balance'] <=> $a['balance']);
        $house = $onlyRepId === null ? array_values(array_filter($rows, static fn (array $x): bool => $x['rep_ids'] === [])) : [];
        commissions_respond(200, [
            'ok' => true,
            'viewer' => $viewer,
            'as_of' => $today->format('Y-m-d'),
            'as_of_label' => $today->format('F j, Y'),
            'refreshed_at' => commissions_state_get($pdo, 'ar_refreshed_at'),
            'reps' => $byRep,
            'territories' => $byTerritory,
            'house' => commissions_ar_totals($house),
            'total' => commissions_ar_totals($rows),
            'bad_debt' => commissions_ar_totals(commissions_ar_rows($pdo, true, $onlyRepId)),
            'bad_debt_days' => COMMISSIONS_AR_BAD_DEBT_DAYS,
            'detail_pending' => commissions_ar_pending_detail($pdo),
        ]);
    }

    if ($action === 'detail') {
        $scope = commissions_ar_scope($pdo, (string) ($_GET['rep_id'] ?? 'all'), trim((string) ($_GET['territory'] ?? '')), $onlyRepId);
        commissions_respond(200, [
            'ok' => true,
            'title' => $scope['title'],
            'rep' => $scope['rep'],
            'as_of_label' => $today->format('F j, Y'),
            'hold_days' => COMMISSIONS_AR_HOLD_DAYS,
            'hold_note' => COMMISSIONS_AR_HOLD_NOTE,
            'totals' => commissions_ar_totals($scope['rows']),
            'customers' => commissions_ar_group($scope['rows']),
            'detail_pending' => commissions_ar_pending_detail($pdo),
        ]);
    }

    if ($action === 'customer') {
        $scope = commissions_ar_scope_customer($pdo, $cwId, (string) $customerName);
        commissions_respond(200, [
            'ok' => true,
            'title' => $scope['title'],
            'rep' => null,
            'as_of_label' => $today->format('F j, Y'),
            'hold_days' => COMMISSIONS_AR_HOLD_DAYS,
            'hold_note' => COMMISSIONS_AR_HOLD_NOTE,
            'totals' => commissions_ar_totals($scope['rows']),
            'customers' => commissions_ar_group($scope['rows']),
            'refreshed_at' => commissions_state_get($pdo, 'ar_refreshed_at'),
            'detail_pending' => commissions_ar_pending_detail($pdo),
        ]);
    }

    if ($action === 'refresh') {
        commissions_require_post();
        $last = commissions_state_get($pdo, 'ar_refreshed_at');
        if ($last !== null && strtotime($last) > time() - 300) {
            commissions_respond(200, ['ok' => true, 'skipped' => true, 'pending' => commissions_ar_pending_detail($pdo)]);
        }
        $n = commissions_ar_refresh($pdo);
        commissions_respond(200, ['ok' => true, 'skipped' => false, 'open_invoices' => $n, 'pending' => commissions_ar_pending_detail($pdo)]);
    }

    if ($action === 'detail_step') {
        commissions_require_post();
        commissions_respond(200, ['ok' => true, 'pending' => commissions_ar_detail_step($pdo)]);
    }

    if ($action === 'email') {
        commissions_require_post();
        $body = commissions_read_json_body();
        $to = trim((string) ($body['to'] ?? ''));
        if ($to === '' || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            commissions_respond(400, ['ok' => false, 'error' => 'Enter a valid email address to send the report to.']);
        }
        if (($onlyRepId !== null || $access === null) && !preg_match('/@codebluetechnology\.com$/i', $to)) {
            commissions_respond(403, ['ok' => false, 'error' => 'You can only send collections reports to a CodeBlue address.']);
        }
        $scope = $customerMode
            ? commissions_ar_scope_customer($pdo, $cwId, (string) $customerName)
            : commissions_ar_scope($pdo, (string) ($body['rep_id'] ?? 'all'), trim((string) ($body['territory'] ?? '')), $onlyRepId);
        if ($scope['rows'] === []) {
            commissions_respond(400, ['ok' => false, 'error' => 'There are no open invoices in this report.']);
        }
        $configPath = __DIR__ . '/../../mail/mail-config.php';
        if (!is_file($configPath)) {
            commissions_respond(500, ['ok' => false, 'error' => 'Mail is not configured on this server yet (mail/mail-config.php is missing).']);
        }
        $config = require $configPath;
        $totals = commissions_ar_totals($scope['rows']);
        $html = commissions_ar_email_html($scope['title'], commissions_ar_group($scope['rows']), $totals, $today, $user['name']);
        $subject = trim((string) ($body['subject'] ?? ''));
        if ($subject === '') {
            $subject = 'Collections report — ' . $scope['title'] . ' — ' . $today->format('M j, Y');
        }
        $subject = str_replace(["\r", "\n"], ' ', $subject);

        require_once __DIR__ . '/../../mail/graph-mailer.php';
        try {
            $mailer = new GraphMailer(
                tenantId: (string) $config['tenant_id'],
                clientId: (string) $config['client_id'],
                clientSecret: (string) $config['client_secret'],
                senderUserId: (string) $config['sender'],
            );
            $mailer->send($to, $subject, $html, null, (string) ($config['from_name'] ?? 'CodeBlue Technology'), true, [], $user['email']);
        } catch (Throwable $e) {
            error_log('[collections-email] ' . $e->getMessage());
            commissions_respond(502, ['ok' => false, 'error' => 'Could not send the email right now. Please try again shortly.']);
        }
        commissions_respond(200, ['ok' => true, 'sent_to' => $to, 'invoices' => $totals['count'], 'balance' => $totals['balance']]);
    }
} catch (InvalidArgumentException $e) {
    commissions_respond(404, ['ok' => false, 'error' => $e->getMessage()]);
} catch (RelationshipsConnectWiseError $e) {
    commissions_respond(502, ['ok' => false, 'error' => 'ConnectWise: ' . $e->getMessage()]);
} catch (Throwable $e) {
    commissions_respond(500, ['ok' => false, 'error' => $e->getMessage()]);
}

commissions_respond(400, ['ok' => false, 'error' => 'Unknown action.']);
