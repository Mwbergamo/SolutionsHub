<?php
/**
 * commissions/api/sync-core.php
 *
 * Polls ConnectWise invoices and turns each into commission lines.
 * Added 2026-10-05. Reuses the Relationships app's ConnectWise client
 * (relationships/api/connectwise.php + its git-ignored
 * connectwise-config.php) -- there is deliberately no second set of API
 * credentials.
 *
 * ===== UNVERIFIED AGAINST LIVE DATA (read before trusting numbers) =====
 * This was written without a network path to ConnectWise. Everything below
 * that is not already proven elsewhere in this codebase is a documented-API
 * assumption, and the design makes each one fail LOUDLY rather than quietly
 * produce a wrong commission:
 *   - An invoice's own record has no line items (confirmed, see
 *     relationships-connectwise-sync.md). Lines are therefore read from
 *     /procurement/products and /time/entries filtered by `invoice/id`,
 *     and, for Agreement invoices, from the agreement's additions.
 *   - Every invoice gets a `detail_state`: 'ok' only when its lines add up
 *     to the invoice's own subtotal; 'mismatch' when they don't; 'no_lines'
 *     when ConnectWise returned nothing for a non-zero invoice; 'error' on
 *     an API failure. The dashboard and reports show those flags, so a
 *     wrong field name shows up as a visible warning, not a silent $0.
 *   - `?action=probe&invoice_id=N` (sync.php) dumps what ConnectWise really
 *     returns for one invoice so any wrong assumption can be corrected.
 *
 * Sync shape: start() lists invoices (one cheap list call) and queues the
 * ones that need (re)processing; step() processes a small batch per HTTP
 * request (Bluehost's time limit -- same pattern as the Relationships
 * sync); finish() locks months older than "Last Month" so history is frozen.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/calc.php';
require_once __DIR__ . '/../../relationships/api/connectwise.php';

const COMMISSIONS_STEP_BATCH = 10;
const COMMISSIONS_PENDING_LOOKBACK_DAYS = 180;

/** All pages of a ConnectWise list; sends `fields` only when given (unrestricted otherwise). */
function commissions_cw_list_all(string $path, string $conditions, array $fields = [], int $pageSize = 200): array
{
    $all = [];
    for ($page = 1; $page <= 100; $page++) {
        $query = ['pageSize' => (string) $pageSize, 'page' => (string) $page];
        if ($conditions !== '') {
            $query['conditions'] = $conditions;
        }
        if ($fields !== []) {
            $query['fields'] = implode(',', $fields);
        }
        $rows = relationships_cw_request($path, $query);
        if ($rows === []) {
            break;
        }
        foreach ($rows as $row) {
            $all[] = $row;
        }
        if (count($rows) < $pageSize) {
            break;
        }
    }
    return $all;
}

function commissions_reps(PDO $pdo): array
{
    return $pdo->query('SELECT * FROM reps ORDER BY sort_order, id')->fetchAll(PDO::FETCH_ASSOC);
}

function commissions_month_is_locked(PDO $pdo, string $month): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM months WHERE month = :m');
    $stmt->execute([':m' => $month]);
    return $stmt->fetchColumn() !== false;
}

// ---------------------------------------------------------------------
// start: list invoices, queue the ones that need work
// ---------------------------------------------------------------------

/**
 * @return array{total_listed:int, queued:int, skipped:int, window_from:string}
 */
function commissions_sync_start(PDO $pdo, int $monthsBack = 1, bool $force = false): array
{
    $monthsBack = max(1, min(24, $monthsBack));
    $tz = new DateTimeZone('America/New_York');
    $now = new DateTimeImmutable('now', $tz);
    $windowStart = $now->modify('first day of this month')->modify("-{$monthsBack} months");
    $pendingStart = $now->modify('-' . COMMISSIONS_PENDING_LOOKBACK_DAYS . ' days');
    $listStart = $windowStart < $pendingStart ? $windowStart : $pendingStart;
    $windowMonth = $windowStart->format('Y-m');

    $pdo->exec('DELETE FROM sync_queue');

    $rows = commissions_cw_list_all(
        '/finance/invoices',
        'date>=[' . $listStart->format('Y-m-d') . 'T00:00:00Z]',
        ['id', 'invoiceNumber', 'date', 'total', 'subtotal', 'salesTax', 'status', 'company', 'type', 'applyToType', 'applyToId']
    );

    $existing = [];
    foreach ($pdo->query('SELECT id, status_name, total, detail_state FROM invoices')->fetchAll(PDO::FETCH_ASSOC) as $e) {
        $existing[(int) $e['id']] = $e;
    }

    $insert = $pdo->prepare("INSERT OR REPLACE INTO sync_queue (invoice_id, payload, status) VALUES (:id, :p, 'pending')");
    $queued = 0;
    $skipped = 0;
    $pdo->beginTransaction();
    foreach ($rows as $r) {
        $id = (int) ($r['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $statusName = is_array($r['status'] ?? null) ? (string) ($r['status']['name'] ?? '') : (string) ($r['status'] ?? '');
        $date = substr((string) ($r['date'] ?? ''), 0, 10);
        $month = substr($date, 0, 7);
        if ($month === '' || commissions_status_is_void($statusName)) {
            $skipped++;
            continue;
        }
        $closed = commissions_status_is_closed($statusName);
        $locked = commissions_month_is_locked($pdo, $month);
        $seen = $existing[$id] ?? null;
        $changed = $force
            || $seen === null
            || in_array((string) $seen['detail_state'], ['pending', 'error'], true)
            || (string) $seen['status_name'] !== $statusName
            || abs((float) $seen['total'] - (float) ($r['total'] ?? 0)) > 0.004;

        // Open invoices are always (re)looked at -- they are the "Pending"
        // bucket and must flip to Closed when ConnectWise closes them.
        // Closed invoices only inside the window, only when new/changed,
        // and never inside a locked (frozen) month unless forced.
        if (!$closed) {
            $process = $changed || $seen !== null;
        } else {
            $process = $changed && $month >= $windowMonth && ($force || !$locked);
        }
        if (!$process) {
            $skipped++;
            continue;
        }

        $insert->execute([
            ':id' => $id,
            ':p' => json_encode([
                'id' => $id,
                'invoice_number' => (string) ($r['invoiceNumber'] ?? $id),
                'date' => $date,
                'status' => $statusName,
                'company_id' => (int) ($r['company']['id'] ?? 0),
                'company_name' => (string) ($r['company']['name'] ?? ''),
                'type' => is_array($r['type'] ?? null) ? (string) ($r['type']['name'] ?? '') : (string) ($r['type'] ?? ''),
                'apply_to_type' => (string) ($r['applyToType'] ?? ''),
                'apply_to_id' => isset($r['applyToId']) ? (int) $r['applyToId'] : null,
                'subtotal' => isset($r['subtotal']) ? (float) $r['subtotal'] : null,
                'sales_tax' => isset($r['salesTax']) ? (float) $r['salesTax'] : null,
                'total' => (float) ($r['total'] ?? 0),
            ]),
        ]);
        $queued++;
    }
    $pdo->commit();

    commissions_state_set($pdo, 'sync_started_at', gmdate('c'));
    commissions_state_set($pdo, 'sync_finished_at', null);
    commissions_state_set($pdo, 'sync_total', (string) $queued);
    commissions_state_set($pdo, 'sync_force', $force ? '1' : '0');

    return [
        'total_listed' => count($rows),
        'queued' => $queued,
        'skipped' => $skipped,
        'window_from' => $windowMonth,
    ];
}

// ---------------------------------------------------------------------
// lookups (cached)
// ---------------------------------------------------------------------

/**
 * Rows for a set of invoice ids from a ConnectWise endpoint that carries an
 * `invoice` reference (/procurement/products, /time/entries).
 * Tries ONE batched `invoice/id in (...)` call; if that errors, or returns
 * nothing usable and has never been seen to work, falls back to one call per
 * invoice. Returns ['rows' => [invoiceId => [row, ...]], 'errors' => [invoiceId => message]].
 */
function commissions_cw_rows_for_invoices(PDO $pdo, string $path, array $ids): array
{
    $ids = array_values(array_unique(array_map('intval', $ids)));
    $grouped = array_fill_keys($ids, []);
    $errors = [];
    if ($ids === []) {
        return ['rows' => $grouped, 'errors' => $errors];
    }
    $stateKey = 'in_batch_ok:' . $path;
    $trusted = commissions_state_get($pdo, $stateKey) === '1';

    try {
        $rows = commissions_cw_list_all($path, 'invoice/id in (' . implode(',', $ids) . ')');
        $matched = 0;
        foreach ($rows as $row) {
            $iid = (int) ($row['invoice']['id'] ?? 0);
            if (isset($grouped[$iid])) {
                $grouped[$iid][] = $row;
                $matched++;
            }
        }
        if ($matched > 0) {
            commissions_state_set($pdo, $stateKey, '1');
            return ['rows' => $grouped, 'errors' => $errors];
        }
        if ($trusted && $rows === []) {
            return ['rows' => $grouped, 'errors' => $errors]; // genuinely none in this chunk
        }
    } catch (Throwable $e) {
        // fall through to per-invoice
    }

    $grouped = array_fill_keys($ids, []);
    foreach ($ids as $id) {
        try {
            $grouped[$id] = commissions_cw_list_all($path, 'invoice/id=' . $id);
        } catch (Throwable $e) {
            $errors[$id] = substr($e->getMessage(), 0, 300);
        }
    }
    return ['rows' => $grouped, 'errors' => $errors];
}

/** Catalog cost/price by catalog item id (cached 24h). @return array<int,array> */
function commissions_catalog_lookup(PDO $pdo, array $catalogIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $catalogIds))));
    $out = [];
    $need = [];
    $get = $pdo->prepare('SELECT * FROM catalog_cache WHERE catalog_id = :id');
    $cutoff = time() - 86400;
    foreach ($ids as $id) {
        $get->execute([':id' => $id]);
        $row = $get->fetch(PDO::FETCH_ASSOC);
        if ($row !== false) {
            $out[$id] = $row;
        }
        if ($row === false || (int) strtotime((string) $row['fetched_at']) < $cutoff) {
            $need[] = $id;
        }
    }
    $upsert = $pdo->prepare(
        'INSERT INTO catalog_cache (catalog_id, identifier, description, cost, price, product_class, fetched_at)
         VALUES (:id, :ident, :d, :c, :p, :pc, :t)
         ON CONFLICT(catalog_id) DO UPDATE SET identifier = excluded.identifier, description = excluded.description,
           cost = excluded.cost, price = excluded.price, product_class = excluded.product_class, fetched_at = excluded.fetched_at'
    );
    foreach (array_chunk($need, 40) as $chunk) {
        try {
            $rows = commissions_cw_list_all('/procurement/catalog', 'id in (' . implode(',', $chunk) . ')', ['id', 'identifier', 'description', 'cost', 'price', 'productClass']);
        } catch (Throwable $e) {
            continue; // keep whatever stale cache we have
        }
        foreach ($rows as $c) {
            $cid = (int) ($c['id'] ?? 0);
            if ($cid <= 0) {
                continue;
            }
            $upsert->execute([
                ':id' => $cid,
                ':ident' => (string) ($c['identifier'] ?? ''),
                ':d' => (string) ($c['description'] ?? ''),
                ':c' => isset($c['cost']) ? (float) $c['cost'] : null,
                ':p' => isset($c['price']) ? (float) $c['price'] : null,
                ':pc' => is_array($c['productClass'] ?? null) ? (string) ($c['productClass']['name'] ?? '') : (string) ($c['productClass'] ?? ''),
                ':t' => gmdate('c'),
            ]);
            $get->execute([':id' => $cid]);
            $out[$cid] = $get->fetch(PDO::FETCH_ASSOC);
        }
    }
    return $out;
}

/** Company id -> territory name (cached 12h). @return array<int,array{name:string,territory:string}> */
function commissions_company_lookup(PDO $pdo, array $companyIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $companyIds))));
    $out = [];
    $need = [];
    $get = $pdo->prepare('SELECT * FROM company_cache WHERE company_id = :id');
    $cutoff = time() - 43200;
    foreach ($ids as $id) {
        $get->execute([':id' => $id]);
        $row = $get->fetch(PDO::FETCH_ASSOC);
        if ($row !== false) {
            $out[$id] = ['name' => (string) $row['name'], 'territory' => (string) $row['territory']];
        }
        if ($row === false || (int) strtotime((string) $row['fetched_at']) < $cutoff) {
            $need[] = $id;
        }
    }
    $upsert = $pdo->prepare(
        'INSERT INTO company_cache (company_id, name, territory, fetched_at) VALUES (:id, :n, :t, :f)
         ON CONFLICT(company_id) DO UPDATE SET name = excluded.name, territory = excluded.territory, fetched_at = excluded.fetched_at'
    );
    foreach (array_chunk($need, 40) as $chunk) {
        try {
            $rows = commissions_cw_list_all('/company/companies', 'id in (' . implode(',', $chunk) . ')', ['id', 'name', 'territory']);
        } catch (Throwable $e) {
            continue;
        }
        foreach ($rows as $c) {
            $cid = (int) ($c['id'] ?? 0);
            if ($cid <= 0) {
                continue;
            }
            $terr = is_array($c['territory'] ?? null) ? (string) ($c['territory']['name'] ?? '') : (string) ($c['territory'] ?? '');
            $upsert->execute([':id' => $cid, ':n' => (string) ($c['name'] ?? ''), ':t' => $terr, ':f' => gmdate('c')]);
            $out[$cid] = ['name' => (string) ($c['name'] ?? ''), 'territory' => $terr];
        }
    }
    return $out;
}

/** Ticket id -> summary (not cached; small). */
function commissions_ticket_summaries(array $ticketIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ticketIds))));
    $out = [];
    foreach (array_chunk($ids, 40) as $chunk) {
        try {
            $rows = commissions_cw_list_all('/service/tickets', 'id in (' . implode(',', $chunk) . ')', ['id', 'summary']);
        } catch (Throwable $e) {
            continue;
        }
        foreach ($rows as $t) {
            $out[(int) ($t['id'] ?? 0)] = (string) ($t['summary'] ?? '');
        }
    }
    return $out;
}

/**
 * Agreement start date + additions (cached 24h).
 * @return array{name:string,start_date:?string,additions:array}
 */
function commissions_agreement_lookup(PDO $pdo, int $agreementId): array
{
    $get = $pdo->prepare('SELECT * FROM agreement_cache WHERE agreement_id = :id');
    $get->execute([':id' => $agreementId]);
    $row = $get->fetch(PDO::FETCH_ASSOC);
    if ($row !== false && (int) strtotime((string) $row['fetched_at']) > time() - 86400) {
        return ['name' => (string) $row['name'], 'start_date' => $row['start_date'], 'additions' => json_decode((string) $row['additions_json'], true) ?: []];
    }
    try {
        $ag = relationships_cw_request("/finance/agreements/$agreementId");
        $start = (string) ($ag['startDate'] ?? $ag['billStartDate'] ?? '');
        $name = (string) ($ag['name'] ?? '');
        $adds = [];
        foreach (commissions_cw_list_all("/finance/agreements/$agreementId/additions", '') as $a) {
            $prod = is_array($a['product'] ?? null) ? $a['product'] : [];
            $adds[] = [
                'product_id' => isset($prod['id']) ? (int) $prod['id'] : null,
                'identifier' => (string) ($prod['identifier'] ?? ''),
                'description' => (string) ($a['description'] ?? ''),
                'qty' => (float) ($a['quantity'] ?? 1),
                'unit_price' => (float) ($a['unitPrice'] ?? 0),
                'unit_cost' => isset($a['unitCost']) ? (float) $a['unitCost'] : null,
                'effective' => substr((string) ($a['effectiveDate'] ?? ''), 0, 10),
                'cancelled' => substr((string) ($a['cancelledDate'] ?? ''), 0, 10),
                'do_not_bill' => stripos((string) ($a['billCustomer'] ?? ''), 'DoNotBill') !== false,
            ];
        }
        $pdo->prepare(
            'INSERT INTO agreement_cache (agreement_id, name, start_date, additions_json, fetched_at) VALUES (:id, :n, :s, :a, :f)
             ON CONFLICT(agreement_id) DO UPDATE SET name = excluded.name, start_date = excluded.start_date, additions_json = excluded.additions_json, fetched_at = excluded.fetched_at'
        )->execute([':id' => $agreementId, ':n' => $name, ':s' => $start !== '' ? substr($start, 0, 10) : null, ':a' => json_encode($adds), ':f' => gmdate('c')]);
        return ['name' => $name, 'start_date' => $start !== '' ? substr($start, 0, 10) : null, 'additions' => $adds];
    } catch (Throwable $e) {
        if ($row !== false) {
            return ['name' => (string) $row['name'], 'start_date' => $row['start_date'], 'additions' => json_decode((string) $row['additions_json'], true) ?: []];
        }
        throw $e;
    }
}

/**
 * Hard-set hourly rate from an agreement's Work Roles tab (Block Time
 * Agreements carry no additions; the Work Roles "Rate" is what each hour
 * billed against the agreement is worth). Uses the row in effect on the
 * invoice date (latest effective date not after it), else the first row with
 * a rate. Returns null when there is none or ConnectWise can't be reached.
 */
function commissions_agreement_work_rate(int $agreementId, string $invoiceDate): ?float
{
    try {
        $rows = commissions_cw_list_all("/finance/agreements/$agreementId/workroles", '');
    } catch (Throwable $e) {
        return null;
    }
    $best = null;
    $bestEff = '';
    $first = null;
    foreach ($rows as $r) {
        $rate = isset($r['rate']) ? (float) $r['rate'] : 0.0;
        if ($rate <= 0) {
            continue;
        }
        if ($first === null) {
            $first = $rate;
        }
        $eff = substr((string) ($r['effectiveDate'] ?? ''), 0, 10);
        if ($eff <= substr($invoiceDate, 0, 10) && ($best === null || $eff >= $bestEff)) {
            $best = $rate;
            $bestEff = $eff;
        }
    }
    return $best ?? $first;
}

// ---------------------------------------------------------------------
// step: process a batch of queued invoices
// ---------------------------------------------------------------------

/**
 * Builds the commission lines for ONE invoice from already-fetched data.
 * Pure (no I/O) so it can be unit-tested. Returns
 * ['lines' => [...], 'detail_state' => ..., 'detail_note' => ..., 'lines_total' => float].
 *
 * $inv   queue payload; $products / $times raw ConnectWise rows;
 * $catalog catalog_id => cache row; $tickets ticket_id => summary;
 * $agreement ['name','start_date','additions'] or null.
 */
function commissions_build_invoice_lines(array $inv, array $products, array $times, array $catalog, array $tickets, ?array $agreement, float $laborCost): array
{
    $lines = [];
    $notes = [];
    $isAgreement = stripos((string) $inv['apply_to_type'], 'agreement') !== false && !empty($inv['apply_to_id']);

    // The invoice's OWN product lines (ConnectWise invoice > Products tab, i.e.
    // /procurement/products linked to the invoice) are the source of truth --
    // for agreement invoices too: they carry the quantity and price actually
    // billed, the "Level" (agreement) and the line's own unit cost.
    foreach ($products as $p) {
        $qty = (float) ($p['quantity'] ?? 1);
        $unitPrice = (float) ($p['price'] ?? 0);
        $catId = (int) ($p['catalogItem']['id'] ?? 0);
        $cat = $catId > 0 ? ($catalog[$catId] ?? null) : null;
        $unitCost = null;
        $costNote = null;
        $lineCost = isset($p['cost']) ? (float) $p['cost'] : null;
        $extCostOverride = null;
        $catalogCost = ($cat !== null && $cat['cost'] !== null) ? (float) $cat['cost'] : null;
        if ($lineCost !== null && !($lineCost == 0.0 && $catalogCost !== null && $catalogCost > 0)) {
            // The invoice's own Products-tab line is the source of truth for
            // cost: its Unit Cost and Ext Cost, for every product line
            // (agreement or not). A $0 line cost with a real catalog cost is
            // treated as "not filled in" and falls through to the catalog.
            $unitCost = $lineCost;
            if (isset($p['extCost']) && is_numeric($p['extCost'])) {
                $extCostOverride = (float) $p['extCost'];
            }
            $costNote = 'Invoice Products tab cost';
        } elseif ($catalogCost !== null) {
            $unitCost = $catalogCost;
            $costNote = 'Product Catalog cost (invoice line had no cost)';
        } else {
            $unitCost = 0.0;
            $costNote = $catId > 0
                ? 'No cost on the invoice line and catalog cost not found -- cost assumed $0'
                : 'No cost on the invoice line and no catalog item -- cost assumed $0';
        }
        $ident = (string) ($p['catalogItem']['identifier'] ?? ($cat['identifier'] ?? ''));
        $desc = (string) ($p['description'] ?? ($cat['description'] ?? ''));
        $ticketId = isset($p['ticket']['id']) ? (int) $p['ticket']['id'] : null;
        $lines[] = [
            'kind' => ($isAgreement || !empty($p['agreement']['id'])) ? 'agreement' : 'product',
            'item' => trim($ident . ($ident !== '' && $desc !== '' ? ' — ' : '') . $desc),
            'ticket_id' => $ticketId,
            'ticket_summary' => $ticketId !== null ? ($tickets[$ticketId] ?? null) : null,
            'hours' => null,
            'qty' => $qty,
            'price' => round($qty * $unitPrice, 2),
            'unit_cost' => $unitCost,
            'cost' => $extCostOverride !== null ? round($extCostOverride, 2) : round($qty * $unitCost, 2),
            'cost_note' => $costNote,
        ];
    }

    foreach ($times as $t) {
        $hours = 0.0;
        foreach (['invoiceHours', 'hoursBilled', 'actualHours'] as $f) {
            if (isset($t[$f]) && (float) $t[$f] > 0) {
                $hours = (float) $t[$f];
                break;
            }
        }
        if ($hours <= 0) {
            continue; // an empty time entry carries nothing to bill or cost
        }
        // Same basis as the existing Service Commission report: price is the
        // BILLED hours x rate; cost is the hours actually worked x hourly cost.
        $actual = (isset($t['actualHours']) && (float) $t['actualHours'] > 0) ? (float) $t['actualHours'] : $hours;
        $rate = (float) ($t['hourlyRate'] ?? 0);
        $chargeType = (string) ($t['chargeToType'] ?? '');
        $ticketId = (isset($t['chargeToId']) && stripos($chargeType, 'Ticket') !== false) ? (int) $t['chargeToId'] : (isset($t['ticket']['id']) ? (int) $t['ticket']['id'] : null);
        $member = (string) ($t['member']['name'] ?? '');
        $lines[] = [
            'kind' => 'time',
            'item' => 'Labor' . ($member !== '' ? ' — ' . $member : ''),
            'ticket_id' => $ticketId,
            'ticket_summary' => $ticketId !== null ? ($tickets[$ticketId] ?? null) : null,
            'hours' => $hours,
            'actual_hours' => $actual,
            'member' => $member !== '' ? $member : null,
            'qty' => null,
            'price' => round($hours * $rate, 2),
            'unit_cost' => null,
            'cost' => round($actual * $laborCost, 2),
            'cost_note' => 'Assumed labor cost $' . number_format($laborCost, 2) . '/hr x ' . $actual . ' actual hrs',
        ];
    }

    $subtotal = $inv['subtotal'];
    if ($subtotal === null) {
        $subtotal = (float) $inv['total'] - (float) ($inv['sales_tax'] ?? 0);
    }
    $subtotal = (float) $subtotal;

    // Fallback only: if ConnectWise returned NO product lines for an agreement
    // invoice, rebuild it from the agreement's additions (never both -- that
    // double counted).
    $usedAdditions = false;
    if ($isAgreement && $agreement !== null && $products === []) {
        $usedAdditions = true;
        $date = (string) $inv['date'];
        foreach ($agreement['additions'] as $a) {
            if (!empty($a['do_not_bill'])) {
                continue;
            }
            if ($a['effective'] !== '' && $a['effective'] > $date) {
                continue;
            }
            if ($a['cancelled'] !== '' && $a['cancelled'] < $date) {
                continue;
            }
            $catId = (int) ($a['product_id'] ?? 0);
            $cat = $catId > 0 ? ($catalog[$catId] ?? null) : null;
            if ($cat !== null && $cat['cost'] !== null) {
                $unitCost = (float) $cat['cost'];
                $costNote = 'Product Catalog cost (static)';
            } else {
                $unitCost = (float) ($a['unit_cost'] ?? 0);
                $costNote = 'Catalog cost not found -- used the cost on the agreement addition';
            }
            $lines[] = [
                'kind' => 'agreement',
                'item' => trim(($a['identifier'] ?? '') . (($a['identifier'] ?? '') !== '' && ($a['description'] ?? '') !== '' ? ' — ' : '') . ($a['description'] ?? '')),
                'ticket_id' => null,
                'ticket_summary' => null,
                'hours' => null,
                'qty' => (float) $a['qty'],
                'price' => round((float) $a['qty'] * (float) $a['unit_price'], 2),
                'unit_cost' => $unitCost,
                'cost' => round((float) $a['qty'] * $unitCost, 2),
                'cost_note' => $costNote,
            ];
        }
    }

    // Block Time Agreement: an agreement invoice with no products, no time
    // entries and no agreement additions. The agreement's Work Roles Rate is a
    // hard-set rate per hour billed, so hours = invoice subtotal / rate; the
    // cost is those hours at the assumed labor cost (Settings, default $90/hr).
    // e.g. $13,500 at $135/hr = 100 hrs; cost $9,000; GP $4,500.
    $workRate = ($isAgreement && $agreement !== null) ? (float) ($agreement['work_rate'] ?? 0) : 0.0;
    if ($lines === [] && $workRate > 0 && $subtotal > 0.004) {
        $bta = round($subtotal / $workRate, 2);
        $lines[] = [
            'kind' => 'time',
            'item' => 'Block time — ' . rtrim(rtrim(number_format($bta, 2, '.', ''), '0'), '.') . ' hrs @ $' . number_format($workRate, 2) . '/hr (Work Roles rate)',
            'ticket_id' => null,
            'ticket_summary' => null,
            'hours' => $bta,
            'actual_hours' => $bta,
            'member' => null,
            'qty' => null,
            'price' => round($subtotal, 2),
            'unit_cost' => null,
            'cost' => round($bta * $laborCost, 2),
            'cost_note' => 'Assumed labor cost $' . number_format($laborCost, 2) . '/hr x ' . $bta . ' hrs (hours = invoice subtotal / Work Roles rate $' . number_format($workRate, 2) . ')',
        ];
    }

    $linesTotal = 0.0;
    foreach ($lines as $l) {
        $linesTotal += $l['price'];
    }
    $linesTotal = round($linesTotal, 2);
    $diff = round($subtotal - $linesTotal, 2);
    $tolerance = max(1.0, abs($subtotal) * 0.01);

    $state = 'ok';
    $note = null;
    if ($lines === [] && abs($subtotal) > 0.004) {
        $state = 'no_lines';
        $note = 'ConnectWise returned no products, time or agreement additions for this invoice, so no cost could be assumed. No commission is paid until this is resolved.';
    } elseif (abs($diff) > $tolerance) {
        if ($usedAdditions) {
            // Agreement invoices rebuilt from the agreement's CURRENT
            // additions; proration/discounts/changes since then show up here
            // as one adjustment line (no cost) so the invoice still totals.
            $lines[] = [
                'kind' => 'adjustment',
                'item' => 'Adjustment to match invoice subtotal (proration, discount or change since invoicing)',
                'ticket_id' => null, 'ticket_summary' => null, 'hours' => null, 'qty' => null,
                'price' => $diff, 'unit_cost' => null, 'cost' => 0.0,
                'cost_note' => 'No cost assumed on the adjustment',
            ];
            $linesTotal = round($linesTotal + $diff, 2);
            if (abs($diff) > max(5.0, abs($subtotal) * 0.05)) {
                $state = 'mismatch';
                $note = 'Agreement additions differ from the invoice subtotal by $' . number_format(abs($diff), 2) . ' -- review.';
            }
        } else {
            $state = 'mismatch';
            $note = 'Lines found in ConnectWise total $' . number_format($linesTotal, 2) . ' but the invoice subtotal is $' . number_format($subtotal, 2) . ' -- review before paying.';
        }
    }
    if ($state === 'no_lines') {
        // Nothing to hang a cost on; keep the invoice visible as one unpriced line.
        $lines[] = [
            'kind' => 'adjustment',
            'item' => 'No line detail returned by ConnectWise',
            'ticket_id' => null, 'ticket_summary' => null, 'hours' => null, 'qty' => null,
            'price' => $subtotal, 'unit_cost' => null, 'cost' => $subtotal,
            'cost_note' => 'Cost set equal to price so no commission is paid',
        ];
        $linesTotal = $subtotal;
    }

    return ['lines' => $lines, 'detail_state' => $state, 'detail_note' => $note, 'lines_total' => $linesTotal, 'is_agreement' => $isAgreement];
}

/**
 * @return array{processed:int, remaining:int, done:bool, errors:array}
 */
function commissions_sync_step(PDO $pdo, int $batch = COMMISSIONS_STEP_BATCH): array
{
    $batch = max(1, min(25, $batch));
    $rows = $pdo->query("SELECT invoice_id, payload FROM sync_queue WHERE status = 'pending' ORDER BY invoice_id LIMIT $batch")->fetchAll(PDO::FETCH_ASSOC);
    $errors = [];

    if ($rows !== []) {
        $invs = [];
        foreach ($rows as $r) {
            $invs[(int) $r['invoice_id']] = json_decode((string) $r['payload'], true);
        }
        $ids = array_keys($invs);
        $laborCost = (float) commissions_setting($pdo, 'labor_cost_per_hour', '90');
        $reps = commissions_reps($pdo);

        $fetchErrors = [];
        $prod = ['rows' => array_fill_keys($ids, []), 'errors' => []];
        $time = ['rows' => array_fill_keys($ids, []), 'errors' => []];
        // Credit-memo style or zero invoices still get looked up; cheap.
        try {
            $prod = commissions_cw_rows_for_invoices($pdo, '/procurement/products', $ids);
            $time = commissions_cw_rows_for_invoices($pdo, '/time/entries', $ids);
        } catch (Throwable $e) {
            foreach ($ids as $id) {
                $fetchErrors[$id] = substr($e->getMessage(), 0, 300);
            }
        }
        foreach ($prod['errors'] + $time['errors'] as $id => $msg) {
            $fetchErrors[$id] = $msg;
        }

        $catIds = [];
        $ticketIds = [];
        foreach ($prod['rows'] as $list) {
            foreach ($list as $p) {
                $catIds[] = (int) ($p['catalogItem']['id'] ?? 0);
                if (isset($p['ticket']['id'])) {
                    $ticketIds[] = (int) $p['ticket']['id'];
                }
            }
        }
        foreach ($time['rows'] as $list) {
            foreach ($list as $t) {
                if (isset($t['chargeToId']) && stripos((string) ($t['chargeToType'] ?? ''), 'Ticket') !== false) {
                    $ticketIds[] = (int) $t['chargeToId'];
                } elseif (isset($t['ticket']['id'])) {
                    $ticketIds[] = (int) $t['ticket']['id'];
                }
            }
        }

        // Agreements (additions' catalog ids too)
        $agreements = [];
        foreach ($invs as $id => $inv) {
            if (stripos((string) $inv['apply_to_type'], 'agreement') !== false && !empty($inv['apply_to_id'])) {
                $aid = (int) $inv['apply_to_id'];
                if (!isset($agreements[$aid])) {
                    try {
                        $agreements[$aid] = commissions_agreement_lookup($pdo, $aid);
                        foreach ($agreements[$aid]['additions'] as $a) {
                            $catIds[] = (int) ($a['product_id'] ?? 0);
                        }
                        if ($agreements[$aid]['additions'] === []) {
                            $agreements[$aid]['work_rate'] = commissions_agreement_work_rate($aid, (string) $inv['date']);
                        }
                    } catch (Throwable $e) {
                        $agreements[$aid] = null;
                        $fetchErrors[$id] = 'Agreement lookup failed: ' . substr($e->getMessage(), 0, 250);
                    }
                }
            }
        }

        $catalog = commissions_catalog_lookup($pdo, $catIds);
        $tickets = commissions_ticket_summaries($ticketIds);
        $companies = commissions_company_lookup($pdo, array_map(static fn ($i) => (int) $i['company_id'], $invs));

        $upInv = $pdo->prepare(
            'INSERT INTO invoices (id, invoice_number, invoice_date, month, status_name, is_closed, is_void, company_id, company_name, territory, rep_id,
                                   apply_to_type, agreement_id, agreement_start, subtotal, total, lines_total, detail_state, detail_note, synced_at)
             VALUES (:id, :num, :d, :m, :st, :cl, 0, :cid, :cn, :terr, :rep, :att, :aid, :ast, :sub, :tot, :lt, :ds, :dn, :sa)
             ON CONFLICT(id) DO UPDATE SET invoice_number = excluded.invoice_number, invoice_date = excluded.invoice_date, month = excluded.month,
               status_name = excluded.status_name, is_closed = excluded.is_closed, company_id = excluded.company_id, company_name = excluded.company_name,
               territory = excluded.territory, rep_id = excluded.rep_id, apply_to_type = excluded.apply_to_type, agreement_id = excluded.agreement_id,
               agreement_start = excluded.agreement_start, subtotal = excluded.subtotal, total = excluded.total, lines_total = excluded.lines_total,
               detail_state = excluded.detail_state, detail_note = excluded.detail_note, synced_at = excluded.synced_at'
        );
        $delLines = $pdo->prepare('DELETE FROM invoice_lines WHERE invoice_id = :id');
        $insLine = $pdo->prepare(
            'INSERT INTO invoice_lines (invoice_id, kind, item, ticket_id, ticket_summary, hours, actual_hours, member, qty, price, cost, unit_cost, cost_note, gp, over_year, pct, commission, is_loss)
             VALUES (:iid, :k, :item, :tid, :ts, :h, :ah, :mem, :q, :p, :c, :uc, :cn, :gp, :oy, :pct, :com, :loss)'
        );
        $markDone = $pdo->prepare("UPDATE sync_queue SET status = :s, error_message = :e WHERE invoice_id = :id");

        foreach ($invs as $id => $inv) {
            try {
                $built = commissions_build_invoice_lines(
                    $inv,
                    $prod['rows'][$id] ?? [],
                    $time['rows'][$id] ?? [],
                    $catalog,
                    $tickets,
                    isset($inv['apply_to_id']) && isset($agreements[(int) $inv['apply_to_id']]) ? $agreements[(int) $inv['apply_to_id']] : null,
                    $laborCost
                );
                $state = $built['detail_state'];
                $note = $built['detail_note'];
                if (isset($fetchErrors[$id])) {
                    $state = 'error';
                    $note = 'ConnectWise lookup failed: ' . $fetchErrors[$id];
                }
                $company = $companies[(int) $inv['company_id']] ?? ['name' => (string) $inv['company_name'], 'territory' => ''];
                $payees = commissions_reps_for_territory($reps, $company['territory']);
                $agreementStart = $built['is_agreement'] && isset($agreements[(int) $inv['apply_to_id']]) ? ($agreements[(int) $inv['apply_to_id']]['start_date'] ?? null) : null;
                $overYear = $built['is_agreement'] && commissions_agreement_over_year($agreementStart, (string) $inv['date']);
                $closed = commissions_status_is_closed((string) $inv['status']);

                $pdo->beginTransaction();
                $upInv->execute([
                    ':id' => $id, ':num' => $inv['invoice_number'], ':d' => $inv['date'], ':m' => substr((string) $inv['date'], 0, 7),
                    ':st' => $inv['status'], ':cl' => $closed ? 1 : 0, ':cid' => $inv['company_id'],
                    ':cn' => $company['name'] !== '' ? $company['name'] : $inv['company_name'], ':terr' => $company['territory'],
                    ':rep' => null, ':att' => $inv['apply_to_type'], ':aid' => $inv['apply_to_id'], ':ast' => $agreementStart,
                    ':sub' => $inv['subtotal'] ?? ((float) $inv['total'] - (float) ($inv['sales_tax'] ?? 0)), ':tot' => $inv['total'],
                    ':lt' => $built['lines_total'], ':ds' => $state, ':dn' => $note, ':sa' => gmdate('c'),
                ]);
                $delLines->execute([':id' => $id]);
                foreach ($built['lines'] as $l) {
                    $m = commissions_line_money((float) $l['price'], (float) $l['cost'], 0.0);
                    $insLine->execute([
                        ':iid' => $id, ':k' => $l['kind'], ':item' => $l['item'], ':tid' => $l['ticket_id'], ':ts' => $l['ticket_summary'],
                        ':h' => $l['hours'], ':ah' => $l['actual_hours'] ?? null, ':mem' => $l['member'] ?? null, ':q' => $l['qty'], ':p' => $l['price'], ':c' => $l['cost'], ':uc' => $l['unit_cost'], ':cn' => $l['cost_note'],
                        ':gp' => $m['gp'], ':oy' => $overYear ? 1 : 0, ':pct' => 0, ':com' => 0, ':loss' => $m['is_loss'],
                    ]);
                    commissions_store_payouts($pdo, (int) $pdo->lastInsertId(), $id, $reps, $payees, $state, $built['is_agreement'], $overYear, (float) $l['price'], (float) $l['cost']);
                }
                $pdo->commit();
                $markDone->execute([':s' => 'done', ':e' => null, ':id' => $id]);
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $markDone->execute([':s' => 'error', ':e' => substr($e->getMessage(), 0, 400), ':id' => $id]);
                $errors[] = ['invoice_id' => $id, 'error' => $e->getMessage()];
            }
        }
    }

    $remaining = (int) $pdo->query("SELECT COUNT(*) FROM sync_queue WHERE status = 'pending'")->fetchColumn();
    $done = $remaining === 0;
    if ($done && commissions_state_get($pdo, 'sync_finished_at') === null) {
        commissions_sync_finish($pdo);
    }
    return ['processed' => count($rows), 'remaining' => $remaining, 'done' => $done, 'errors' => $errors];
}

/** Lock every month older than "Last Month" that has invoices -- freezing history. */
function commissions_sync_finish(PDO $pdo): void
{
    $lastMonth = commissions_month_key(1);
    $months = $pdo->prepare('SELECT DISTINCT month FROM invoices WHERE month < :lm AND month IS NOT NULL');
    $months->execute([':lm' => $lastMonth]);
    $lock = $pdo->prepare('INSERT OR IGNORE INTO months (month, locked_at) VALUES (:m, :t)');
    foreach ($months->fetchAll(PDO::FETCH_COLUMN) as $m) {
        $lock->execute([':m' => $m, ':t' => gmdate('c')]);
    }
    commissions_state_set($pdo, 'sync_finished_at', gmdate('c'));
}

function commissions_sync_status(PDO $pdo): array
{
    $counts = $pdo->query('SELECT status, COUNT(*) FROM sync_queue GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
    return [
        'started_at' => commissions_state_get($pdo, 'sync_started_at'),
        'finished_at' => commissions_state_get($pdo, 'sync_finished_at'),
        'total' => (int) (commissions_state_get($pdo, 'sync_total') ?? 0),
        'pending' => (int) ($counts['pending'] ?? 0),
        'done' => (int) ($counts['done'] ?? 0),
        'error' => (int) ($counts['error'] ?? 0),
    ];
}

// ---------------------------------------------------------------------
// recompute: apply changed settings (rates, labor cost, territories) to
// every UNLOCKED month without re-calling ConnectWise
// ---------------------------------------------------------------------

/**
 * Write the per-payee commission rows for one line. A payee is paid its % of
 * the line's gross profit (negative on a loss). Invoices whose detail could
 * not be trusted (no lines / lookup error) are kept visible but pay $0.
 *
 * @param int[] $payees
 */
function commissions_store_payouts(PDO $pdo, int $lineId, int $invoiceId, array $reps, array $payees, string $state, bool $isAgreement, bool $overYear, float $price, float $cost): float
{
    $pdo->prepare('DELETE FROM line_commissions WHERE line_id = :l')->execute([':l' => $lineId]);
    $byId = [];
    foreach ($reps as $r) {
        $byId[(int) $r['id']] = $r;
    }
    $ins = $pdo->prepare('INSERT INTO line_commissions (line_id, invoice_id, rep_id, pct, commission) VALUES (:l, :i, :r, :p, :c)');
    $total = 0.0;
    foreach ($payees as $rid) {
        if (!isset($byId[$rid])) {
            continue;
        }
        $pct = ($state === 'no_lines' || $state === 'error') ? 0.0 : commissions_rep_pct($byId[$rid], $isAgreement, $overYear);
        $m = commissions_line_money($price, $cost, $pct);
        $ins->execute([':l' => $lineId, ':i' => $invoiceId, ':r' => $rid, ':p' => $pct, ':c' => $m['commission']]);
        $total += $m['commission'];
    }
    $pdo->prepare('UPDATE invoice_lines SET commission = :c WHERE id = :l')->execute([':c' => round($total, 2), ':l' => $lineId]);
    return $total;
}

/**
 * Re-apply the CURRENT rates, labor cost and territory words to stored lines
 * without calling ConnectWise. Locked (frozen) months are skipped unless
 * $includeLocked (only used once, when the payout model itself changed).
 */
function commissions_recompute(PDO $pdo, bool $includeLocked = false): int
{
    $laborCost = (float) commissions_setting($pdo, 'labor_cost_per_hour', '90');
    $reps = commissions_reps($pdo);
    $invoices = $pdo->query(
        'SELECT i.* FROM invoices i LEFT JOIN months m ON m.month = i.month' . ($includeLocked ? '' : ' WHERE m.month IS NULL')
    )->fetchAll(PDO::FETCH_ASSOC);

    $getLines = $pdo->prepare('SELECT * FROM invoice_lines WHERE invoice_id = :id');
    $setLine = $pdo->prepare('UPDATE invoice_lines SET cost = :c, gp = :gp, pct = 0, is_loss = :l WHERE id = :id');
    $n = 0;
    $pdo->beginTransaction();
    foreach ($invoices as $inv) {
        $payees = commissions_reps_for_territory($reps, (string) $inv['territory']);
        $isAgreement = stripos((string) $inv['apply_to_type'], 'agreement') !== false && !empty($inv['agreement_id']);
        $getLines->execute([':id' => $inv['id']]);
        foreach ($getLines->fetchAll(PDO::FETCH_ASSOC) as $l) {
            $cost = (float) $l['cost'];
            if ($l['kind'] === 'time') {
                $worked = ($l['actual_hours'] !== null && (float) $l['actual_hours'] > 0) ? (float) $l['actual_hours'] : (float) $l['hours'];
                $cost = round($worked * $laborCost, 2);
            }
            $m = commissions_line_money((float) $l['price'], $cost, 0.0);
            $setLine->execute([':c' => $cost, ':gp' => $m['gp'], ':l' => $m['is_loss'], ':id' => $l['id']]);
            commissions_store_payouts($pdo, (int) $l['id'], (int) $inv['id'], $reps, $payees, (string) $inv['detail_state'], $isAgreement, (bool) $l['over_year'], (float) $l['price'], $cost);
            $n++;
        }
    }
    $pdo->commit();
    return $n;
}

/** One-time rebuild after the payout model changed (flag set by the db upgrade). */
function commissions_run_pending_migration(PDO $pdo): void
{
    if (commissions_setting($pdo, 'model_recompute_pending', '0') === '1') {
        commissions_recompute($pdo, true);
        commissions_set_setting($pdo, 'model_recompute_pending', '0');
    }
}
