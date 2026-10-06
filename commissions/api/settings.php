<?php
/**
 * commissions/api/settings.php
 *
 * GET  -> { settings: { labor_cost_per_hour }, reps: [...], territories: [...] }
 *         `territories` = every distinct ConnectWise territory seen on synced
 *         companies (with its invoice count and who it pays; nobody = house
 *         account) so the territory -> payee mapping can be checked at a glance.
 * POST -> saves labor cost and each payee's % / territory words,
 *         records an audit row for every change, and re-applies the new
 *         numbers to every UNLOCKED month (locked months are frozen).
 */

declare(strict_types=1);

require_once __DIR__ . '/_util.php';
require_once __DIR__ . '/calc.php';
require_once __DIR__ . '/sync-core.php';

$user = commissions_require_access();
$pdo = commissions_db();
commissions_run_pending_migration($pdo);

function commissions_settings_payload(PDO $pdo): array
{
    $reps = array_values(array_filter(commissions_reps($pdo), static fn (array $r): bool => !empty($r['active'])));
    $terr = $pdo->query(
        "SELECT territory, COUNT(*) AS invoices FROM invoices WHERE territory IS NOT NULL GROUP BY territory ORDER BY invoices DESC"
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($terr as &$t) {
        $ids = commissions_reps_for_territory($reps, (string) $t['territory']);
        $names = [];
        foreach ($reps as $r) {
            if (in_array((int) $r['id'], $ids, true)) {
                $names[] = $r['name'];
            }
        }
        $t['payees'] = $names; // empty = house account
    }
    unset($t);
    $lockedMonths = $pdo->query('SELECT month FROM months ORDER BY month')->fetchAll(PDO::FETCH_COLUMN);
    return [
        'ok' => true,
        'settings' => [
            'labor_cost_per_hour' => (float) commissions_setting($pdo, 'labor_cost_per_hour', '90'),
        ],
        'reps' => array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'name' => $r['name'],
            'territory_match' => $r['territory_match'],
            'base_pct' => (float) $r['base_pct'],
            'agreement_after_year_pct' => $r['agreement_after_year_pct'] !== null ? (float) $r['agreement_after_year_pct'] : null,
        ], $reps),
        'territories' => $terr,
        'locked_months' => $lockedMonths,
    ];
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    commissions_respond(200, commissions_settings_payload($pdo));
}

commissions_require_post();
$body = commissions_read_json_body();
$audit = $pdo->prepare('INSERT INTO settings_audit (at, by_email, what, old_value, new_value) VALUES (:at, :by, :w, :o, :n)');
$log = static function (string $what, string $old, string $new) use ($audit, $user): void {
    if ($old !== $new) {
        $audit->execute([':at' => gmdate('c'), ':by' => $user['email'], ':w' => $what, ':o' => $old, ':n' => $new]);
    }
};
$num = static function ($v, float $min, float $max): ?float {
    if ($v === null || $v === '' || !is_numeric($v)) {
        return null;
    }
    $f = (float) $v;
    return ($f < $min || $f > $max) ? null : $f;
};

if (array_key_exists('labor_cost_per_hour', $body)) {
    $labor = $num($body['labor_cost_per_hour'], 0, 1000);
    if ($labor === null) {
        commissions_respond(400, ['ok' => false, 'error' => 'Labor cost per hour must be a number from 0 to 1000.']);
    }
    $log('labor_cost_per_hour', commissions_setting($pdo, 'labor_cost_per_hour', '90'), (string) $labor);
    commissions_set_setting($pdo, 'labor_cost_per_hour', (string) $labor);
}
if (isset($body['reps']) && is_array($body['reps'])) {
    $get = $pdo->prepare('SELECT * FROM reps WHERE id = :id');
    $upd = $pdo->prepare('UPDATE reps SET territory_match = :t, base_pct = :b, agreement_after_year_pct = :a WHERE id = :id');
    foreach ($body['reps'] as $r) {
        $id = (int) ($r['id'] ?? 0);
        $get->execute([':id' => $id]);
        $old = $get->fetch(PDO::FETCH_ASSOC);
        if ($old === false) {
            continue;
        }
        $base = $num($r['base_pct'] ?? null, 0, 100);
        if ($base === null) {
            commissions_respond(400, ['ok' => false, 'error' => 'Commission % for ' . $old['name'] . ' must be a number from 0 to 100.']);
        }
        $afterRaw = $r['agreement_after_year_pct'] ?? null;
        $after = ($afterRaw === null || $afterRaw === '') ? null : $num($afterRaw, 0, 100);
        if (($afterRaw !== null && $afterRaw !== '') && $after === null) {
            commissions_respond(400, ['ok' => false, 'error' => 'The after-one-year % for ' . $old['name'] . ' must be a number from 0 to 100 (or empty).']);
        }
        $terr = substr(trim((string) ($r['territory_match'] ?? '')), 0, 300);
        $log($old['name'] . ' base %', (string) $old['base_pct'], (string) $base);
        $log($old['name'] . ' agreement after 1 year %', (string) ($old['agreement_after_year_pct'] ?? ''), (string) ($after ?? ''));
        $log($old['name'] . ' territories', (string) $old['territory_match'], $terr);
        $upd->execute([':t' => $terr, ':b' => $base, ':a' => $after, ':id' => $id]);
    }
}

$n = commissions_recompute($pdo);
commissions_respond(200, commissions_settings_payload($pdo) + ['lines_recomputed' => $n]);
