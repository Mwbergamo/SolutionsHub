<?php
/**
 * commissions/api/ar-core.php
 *
 * "Open invoices to collect": Closed / Closed - Emailed ConnectWise invoices
 * that still carry a balance. Refreshed at the start of every sync
 * (commissions_ar_refresh) and filled in with agreement name + ticket
 * summaries a few invoices per sync step (commissions_ar_detail_step).
 * Days outstanding are counted from the invoice date.
 */

declare(strict_types=1);

require_once __DIR__ . '/sync-core.php';
require_once __DIR__ . '/../../auth/collections-access.php';

const COMMISSIONS_AR_DETAIL_BATCH = 12;
const COMMISSIONS_AR_HOLD_DAYS = 60;
/** Invoices older than this many days are written off as bad debt and left out of collections. */
const COMMISSIONS_AR_BAD_DEBT_DAYS = 730;
const COMMISSIONS_AR_HOLD_NOTE = 'This invoice is over 60 days old. The customer will remain on service hold until the balance is current.';

/**
 * Re-read every unpaid invoice from ConnectWise; drop rows that are now paid.
 * Nothing is deleted if the ConnectWise call fails.
 * @return int number of open invoices stored
 */
function commissions_ar_refresh(PDO $pdo): int
{
    $rows = commissions_cw_list_all(
        '/finance/invoices',
        'balance>0',
        ['id', 'invoiceNumber', 'date', 'dueDate', 'total', 'balance', 'status', 'company', 'applyToType', 'applyToId', 'agreement']
    );
    $keep = [];
    foreach ($rows as $r) {
        $id = (int) ($r['id'] ?? 0);
        $statusName = is_array($r['status'] ?? null) ? (string) ($r['status']['name'] ?? '') : (string) ($r['status'] ?? '');
        $balance = (float) ($r['balance'] ?? 0);
        if ($id <= 0 || $balance < 0.005 || !commissions_status_is_closed($statusName)) {
            continue;
        }
        $keep[$id] = $r + ['_status' => $statusName];
    }

    $companyIds = [];
    foreach ($keep as $r) {
        $companyIds[] = (int) ($r['company']['id'] ?? 0);
    }
    $companies = commissions_company_lookup($pdo, $companyIds);

    $existing = [];
    foreach ($pdo->query('SELECT invoice_id, agreement_id, agreement_name, tickets, detail_at FROM ar_invoices')->fetchAll(PDO::FETCH_ASSOC) as $e) {
        $existing[(int) $e['invoice_id']] = $e;
    }

    $up = $pdo->prepare(
        'INSERT INTO ar_invoices (invoice_id, invoice_number, invoice_date, due_date, status_name, company_id, company_name, territory, agreement_id, agreement_name, total, balance, tickets, detail_at, updated_at)
         VALUES (:id, :num, :d, :due, :st, :cid, :cn, :terr, :aid, :an, :tot, :bal, :tk, :da, :u)
         ON CONFLICT(invoice_id) DO UPDATE SET invoice_number = excluded.invoice_number, invoice_date = excluded.invoice_date, due_date = excluded.due_date,
            status_name = excluded.status_name, company_id = excluded.company_id, company_name = excluded.company_name, territory = excluded.territory,
            agreement_id = excluded.agreement_id, agreement_name = excluded.agreement_name, total = excluded.total, balance = excluded.balance,
            tickets = excluded.tickets, detail_at = excluded.detail_at, updated_at = excluded.updated_at'
    );
    $del = $pdo->prepare('DELETE FROM ar_invoices WHERE invoice_id = :id');
    $pdo->beginTransaction();
    try {
        foreach ($keep as $id => $r) {
            $cid = (int) ($r['company']['id'] ?? 0);
            $co = $companies[$cid] ?? ['name' => '', 'territory' => ''];
            $applyAgreement = stripos((string) ($r['applyToType'] ?? ''), 'agreement') !== false && !empty($r['applyToId']);
            $aid = isset($r['agreement']['id']) ? (int) $r['agreement']['id'] : ($applyAgreement ? (int) $r['applyToId'] : null);
            $aname = is_array($r['agreement'] ?? null) ? trim((string) ($r['agreement']['name'] ?? '')) : '';
            $old = $existing[$id] ?? null;
            // Keep detail already gathered for an invoice we know, unless its agreement changed.
            $reuse = $old !== null && $old['detail_at'] !== null && (int) ($old['agreement_id'] ?? 0) === (int) ($aid ?? 0);
            $up->execute([
                ':id' => $id,
                ':num' => (string) ($r['invoiceNumber'] ?? $id),
                ':d' => substr((string) ($r['date'] ?? ''), 0, 10),
                ':due' => substr((string) ($r['dueDate'] ?? ''), 0, 10),
                ':st' => $r['_status'],
                ':cid' => $cid,
                ':cn' => $co['name'] !== '' ? $co['name'] : (string) ($r['company']['name'] ?? ''),
                ':terr' => $co['territory'],
                ':aid' => $aid,
                ':an' => $aname !== '' ? $aname : ($reuse ? $old['agreement_name'] : null),
                ':tot' => (float) ($r['total'] ?? 0),
                ':bal' => (float) ($r['balance'] ?? 0),
                ':tk' => $reuse ? $old['tickets'] : null,
                ':da' => $reuse ? $old['detail_at'] : null,
                ':u' => gmdate('c'),
            ]);
        }
        foreach (array_keys($existing) as $oldId) {
            if (!isset($keep[$oldId])) {
                $del->execute([':id' => $oldId]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    commissions_state_set($pdo, 'ar_refreshed_at', gmdate('c'));
    return count($keep);
}

function commissions_ar_pending_detail(PDO $pdo): int
{
    return (int) $pdo->query('SELECT COUNT(*) FROM ar_invoices WHERE detail_at IS NULL')->fetchColumn();
}

/**
 * Fill in agreement name and ticket summaries for a few open invoices.
 * Never throws: an invoice that can't be looked up is marked done and keeps
 * whatever it had (the report just omits the ticket).
 * @return int invoices still waiting
 */
function commissions_ar_detail_step(PDO $pdo, int $batch = COMMISSIONS_AR_DETAIL_BATCH): int
{
    $batch = max(1, min(50, $batch));
    $rows = $pdo->query("SELECT * FROM ar_invoices WHERE detail_at IS NULL ORDER BY invoice_id LIMIT $batch")->fetchAll(PDO::FETCH_ASSOC);
    if ($rows === []) {
        return 0;
    }
    $ids = array_map(static fn (array $r): int => (int) $r['invoice_id'], $rows);

    // Tickets already on file from the commission lines.
    $local = [];
    $q = $pdo->prepare("SELECT DISTINCT ticket_summary FROM invoice_lines WHERE invoice_id = :id AND ticket_summary IS NOT NULL AND ticket_summary != ''");
    foreach ($ids as $id) {
        $q->execute([':id' => $id]);
        $local[$id] = $q->fetchAll(PDO::FETCH_COLUMN);
    }

    // Otherwise ask ConnectWise which tickets the invoice's products / time belong to.
    $ticketIds = [];
    $need = array_values(array_filter($ids, static fn (int $id): bool => $local[$id] === []));
    if ($need !== []) {
        foreach (['/procurement/products', '/time/entries'] as $path) {
            try {
                $res = commissions_cw_rows_for_invoices($pdo, $path, $need);
            } catch (Throwable $e) {
                continue;
            }
            foreach ($res['rows'] as $invId => $list) {
                foreach ($list as $row) {
                    $t = null;
                    if (isset($row['ticket']['id'])) {
                        $t = (int) $row['ticket']['id'];
                    } elseif (isset($row['chargeToId']) && stripos((string) ($row['chargeToType'] ?? ''), 'Ticket') !== false) {
                        $t = (int) $row['chargeToId'];
                    }
                    if ($t) {
                        $ticketIds[(int) $invId][$t] = true;
                    }
                }
            }
        }
    }
    $summaries = [];
    $allT = [];
    foreach ($ticketIds as $set) {
        $allT = array_merge($allT, array_keys($set));
    }
    if ($allT !== []) {
        $summaries = commissions_ticket_summaries($allT);
    }

    $upd = $pdo->prepare('UPDATE ar_invoices SET agreement_name = :an, tickets = :tk, detail_at = :da WHERE invoice_id = :id');
    foreach ($rows as $r) {
        $id = (int) $r['invoice_id'];
        $names = $local[$id];
        foreach (array_keys($ticketIds[$id] ?? []) as $t) {
            if (!empty($summaries[$t])) {
                $names[] = (string) $summaries[$t];
            }
        }
        $names = array_values(array_unique(array_map('trim', $names)));
        $agName = (string) ($r['agreement_name'] ?? '');
        if ($agName === '' && (int) ($r['agreement_id'] ?? 0) > 0) {
            try {
                $agName = (string) commissions_agreement_lookup($pdo, (int) $r['agreement_id'])['name'];
            } catch (Throwable $e) {
                $agName = '';
            }
        }
        $upd->execute([':an' => $agName !== '' ? $agName : null, ':tk' => $names === [] ? null : implode('; ', $names), ':da' => gmdate('c'), ':id' => $id]);
    }
    return commissions_ar_pending_detail($pdo);
}

// ---------------------------------------------------------------------
// reporting
// ---------------------------------------------------------------------

function commissions_ar_today(): DateTimeImmutable
{
    return new DateTimeImmutable('today', new DateTimeZone('America/New_York'));
}

function commissions_ar_days(string $invoiceDate, DateTimeImmutable $today): int
{
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $invoiceDate, new DateTimeZone('America/New_York'));
    if ($d === false) {
        return 0;
    }
    return max(0, (int) $d->diff($today)->format('%r%a'));
}

/**
 * All open invoices with days outstanding and the payee ids for their territory.
 * @return list<array<string,mixed>>
 */
function commissions_ar_rows(PDO $pdo, bool $badDebtOnly = false, ?int $onlyRepId = null): array
{
    $today = commissions_ar_today();
    $reps = commissions_reps($pdo);
    // Collections gives Moe and Chester an explicit territory list (auth/collections-access.php) instead of the commission word match.
    $scoped = [];
    foreach ($reps as $r) {
        if (isset(COLLECTIONS_REP_TERRITORIES[$r['name']])) {
            $scoped[(int) $r['id']] = array_map('strtolower', COLLECTIONS_REP_TERRITORIES[$r['name']]);
        }
    }
    $out = [];
    foreach ($pdo->query('SELECT * FROM ar_invoices')->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $days = commissions_ar_days((string) $r['invoice_date'], $today);
        if (($days > COMMISSIONS_AR_BAD_DEBT_DAYS) !== $badDebtOnly) {
            continue;
        }
        $repIds = commissions_reps_for_territory($reps, (string) $r['territory']);
        foreach ($scoped as $sid => $list) {
            $repIds = array_values(array_diff($repIds, [$sid]));
            if (in_array(strtolower(trim((string) $r['territory'])), $list, true)) {
                $repIds[] = $sid;
            }
        }
        if ($onlyRepId !== null && !in_array($onlyRepId, $repIds, true)) {
            continue;
        }
        $out[] = [
            'invoice_id' => (int) $r['invoice_id'],
            'invoice_number' => (string) $r['invoice_number'],
            'invoice_date' => (string) $r['invoice_date'],
            'company_id' => (int) $r['company_id'],
            'customer' => (string) $r['company_name'],
            'territory' => (string) $r['territory'],
            'agreement' => (string) ($r['agreement_name'] ?? ''),
            'tickets' => (string) ($r['tickets'] ?? ''),
            'total' => round((float) $r['total'], 2),
            'balance' => round((float) $r['balance'], 2),
            'days' => $days,
            'rep_ids' => $repIds,
        ];
    }
    return $out;
}

/** @param list<array<string,mixed>> $rows */
function commissions_ar_totals(array $rows): array
{
    $t = ['count' => 0, 'balance' => 0.0, 'b0' => 0.0, 'b30' => 0.0, 'b60' => 0.0, 'oldest_days' => 0];
    foreach ($rows as $r) {
        $t['count']++;
        $t['balance'] += $r['balance'];
        $k = $r['days'] > COMMISSIONS_AR_HOLD_DAYS ? 'b60' : ($r['days'] > 30 ? 'b30' : 'b0');
        $t[$k] += $r['balance'];
        $t['oldest_days'] = max($t['oldest_days'], $r['days']);
    }
    foreach (['balance', 'b0', 'b30', 'b60'] as $k) {
        $t[$k] = round($t[$k], 2);
    }
    return $t;
}

/**
 * Grouped collections view: customers (oldest invoice first), each with its
 * invoices oldest -> newest and a subtotal.
 * @param list<array<string,mixed>> $rows already filtered
 */
function commissions_ar_group(array $rows): array
{
    $by = [];
    foreach ($rows as $r) {
        $key = $r['company_id'] > 0 ? 'c' . $r['company_id'] : 'n' . strtolower($r['customer']);
        if (!isset($by[$key])) {
            $by[$key] = ['company_id' => $r['company_id'], 'customer' => $r['customer'], 'territory' => $r['territory'], 'invoices' => [], 'subtotal' => 0.0, 'oldest_days' => 0, 'over60' => false];
        }
        $by[$key]['invoices'][] = $r;
        $by[$key]['subtotal'] += $r['balance'];
        $by[$key]['oldest_days'] = max($by[$key]['oldest_days'], $r['days']);
        if ($r['days'] > COMMISSIONS_AR_HOLD_DAYS) {
            $by[$key]['over60'] = true;
        }
    }
    foreach ($by as &$c) {
        usort($c['invoices'], static fn (array $a, array $b): int => [$b['days'], $a['invoice_number']] <=> [$a['days'], $b['invoice_number']]);
        $c['subtotal'] = round($c['subtotal'], 2);
    }
    unset($c);
    $list = array_values($by);
    usort($list, static fn (array $a, array $b): int => [$b['oldest_days'], strtolower($a['customer'])] <=> [$a['oldest_days'], strtolower($b['customer'])]);
    return $list;
}

/**
 * Rows for a scope: rep id, 'house' (no payee), or 'all'; optional territory name.
 * @return array{rows:list<array<string,mixed>>, title:string, rep:?array}
 */
function commissions_ar_scope(PDO $pdo, string $repParam, string $territory = '', ?int $onlyRepId = null): array
{
    if ($onlyRepId !== null) {
        $repParam = (string) $onlyRepId; // a restricted viewer can only ever see their own rep
    }
    $rows = commissions_ar_rows($pdo, false, $onlyRepId);
    $title = 'All reps';
    $rep = null;
    if ($repParam === 'house') {
        $rows = array_values(array_filter($rows, static fn (array $r): bool => $r['rep_ids'] === []));
        $title = 'House accounts';
    } elseif ($repParam !== 'all' && $repParam !== '') {
        $stmt = $pdo->prepare('SELECT id, name, email FROM reps WHERE id = :id');
        $stmt->execute([':id' => (int) $repParam]);
        $rep = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($rep === null) {
            throw new InvalidArgumentException('Unknown rep.');
        }
        $rid = (int) $rep['id'];
        $rows = array_values(array_filter($rows, static fn (array $r): bool => in_array($rid, $r['rep_ids'], true)));
        $title = (string) $rep['name'];
    }
    if ($territory !== '') {
        $want = $territory === '(none)' ? '' : $territory;
        $rows = array_values(array_filter($rows, static fn (array $r): bool => strcasecmp($r['territory'], $want) === 0));
        $title .= ' — ' . $territory;
    }
    return ['rows' => $rows, 'title' => $title, 'rep' => $rep];
}

/**
 * One customer (ConnectWise company id): used by the "Open Balance Report" link on a Relationships customer page.
 * @return array{rows:list<array<string,mixed>>, title:string, rep:null}
 */
function commissions_ar_scope_customer(PDO $pdo, int $cwId, string $name = ''): array
{
    $rows = array_values(array_filter(commissions_ar_rows($pdo), static fn (array $r): bool => $r['company_id'] === $cwId));
    $title = $name !== '' ? $name : ($rows !== [] ? (string) $rows[0]['customer'] : 'Customer');
    return ['rows' => $rows, 'title' => $title, 'rep' => null];
}

function commissions_ar_money(float $v): string
{
    return ($v < 0 ? '-' : '') . '$' . number_format(abs($v), 2);
}

/** HTML email body for the collections report. */
function commissions_ar_email_html(string $title, array $groups, array $totals, DateTimeImmutable $asOf, string $senderName): string
{
    $e = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $h = '<div style="font-family:Segoe UI,Arial,sans-serif;font-size:14px;color:#1f2937;max-width:900px">';
    $h .= '<h2 style="margin:0 0 4px">Collections report</h2>';
    $h .= '<div style="color:#6b7280;margin-bottom:12px">' . $e($title) . ' &middot; as of ' . $e($asOf->format('F j, Y')) . '</div>';
    $h .= '<p style="margin:0 0 12px"><strong>Total outstanding: ' . $e(commissions_ar_money($totals['balance'])) . '</strong> across ' . (int) $totals['count'] . ' invoice' . ($totals['count'] === 1 ? '' : 's')
        . ' &middot; ' . $e(commissions_ar_money($totals['b60'])) . ' is over 60 days.</p>';
    if ($groups === []) {
        return $h . '<p>No open invoices.</p></div>';
    }
    $cell = 'border:1px solid #d5dbe3;';
    foreach ($groups as $g) {
        $h .= '<table style="border-collapse:collapse;width:100%;margin:0 0 18px" cellpadding="6">';
        $h .= '<tr><td colspan="6" style="background:#eef2f7;font-weight:600;' . $cell . '">' . $e($g['customer'])
            . ' <span style="font-weight:400;color:#6b7280">&mdash; ' . $e(commissions_ar_money($g['subtotal'])) . ' outstanding</span></td></tr>';
        $h .= '<tr style="background:#f8fafc;text-align:left;font-size:12px;color:#4b5563">';
        foreach (['Invoice #', 'Invoice date', 'Agreement', 'Ticket', 'Days', 'Balance'] as $col) {
            $h .= '<th style="' . $cell . ($col === 'Balance' || $col === 'Days' ? 'text-align:right' : '') . '">' . $col . '</th>';
        }
        $h .= '</tr>';
        foreach ($g['invoices'] as $r) {
            $over = $r['days'] > COMMISSIONS_AR_HOLD_DAYS;
            $h .= '<tr' . ($over ? ' style="background:#fff5f5"' : '') . '>'
                . '<td style="' . $cell . '">' . $e($r['invoice_number']) . '</td>'
                . '<td style="' . $cell . '">' . $e($r['invoice_date']) . '</td>'
                . '<td style="' . $cell . '">' . $e($r['agreement'] !== '' ? $r['agreement'] : '—') . '</td>'
                . '<td style="' . $cell . '">' . $e($r['tickets'] !== '' ? $r['tickets'] : '—') . '</td>'
                . '<td style="' . $cell . 'text-align:right' . ($over ? ';color:#b91c1c;font-weight:600' : '') . '">' . (int) $r['days'] . '</td>'
                . '<td style="' . $cell . 'text-align:right">' . $e(commissions_ar_money($r['balance'])) . '</td></tr>';
            if ($over) {
                $h .= '<tr style="background:#fff5f5"><td colspan="6" style="' . $cell . 'color:#b91c1c;font-size:12px">Invoice ' . $e($r['invoice_number']) . ': ' . $e(COMMISSIONS_AR_HOLD_NOTE) . '</td></tr>';
            }
        }
        $h .= '<tr><td colspan="5" style="' . $cell . 'text-align:right;font-weight:600">Customer total</td><td style="' . $cell . 'text-align:right;font-weight:600">'
            . $e(commissions_ar_money($g['subtotal'])) . '</td></tr></table>';
    }
    $h .= '<p style="font-weight:600;font-size:15px">Grand total outstanding: ' . $e(commissions_ar_money($totals['balance'])) . '</p>';
    $h .= '<p style="color:#6b7280;font-size:12px">Days are counted from the invoice date. Sent by ' . $e($senderName) . ' from the CodeBlue commissions app.</p></div>';
    return $h;
}
