<?php
/**
 * relationships/api/duplicates-core.php
 *
 * Duplicate-customer management (2026-10-13). ConnectWise can hold several
 * companies with the same name; the sync turns each into its own customers
 * row, which made the Ticket -> OutGrow matcher "ambiguous" almost every
 * time. This file groups likely duplicates and records Michael's choice of
 * which one is correct.
 *
 * Choosing a keeper HIDES the others -- nothing is deleted:
 *   - customers.is_duplicate_hidden = 1 and cw_bucket = 'excluded', so the
 *     row drops out of lists, search, the dashboard and the OutGrow matcher;
 *   - a customer_duplicate_overrides row (keyed by the ConnectWise company
 *     id) makes the Company Status sync keep it excluded, the billing /
 *     contacts / ticket-history / opportunity syncs skip it, and the
 *     Agreement sync book its services on the keeper;
 *   - the hidden row's synced services are moved to the keeper now.
 * Rep-entered data (notes, meetings, OutGrow touches) stays on the hidden
 * row; restore puts everything back.
 */

declare(strict_types=1);

require_once __DIR__ . '/outgrow-ticket-touch.php'; // company key helpers

/** Group key: the loose company name (case, punctuation, Inc/LLC ignored). */
function relationships_dup_group_key(string $name): string
{
    $k = relationships_outgrow_company_key_loose($name);
    return $k !== '' ? $k : relationships_outgrow_company_key($name);
}

function relationships_dup_scalar(PDO $pdo, string $sql, array $params = []): int
{
    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return (int) $st->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * @return array{groups: array<int,array<string,mixed>>, hidden: array<int,array<string,mixed>>}
 */
function relationships_dup_list(PDO $pdo): array
{
    $rows = $pdo->query(
        "SELECT id, connectwise_id, name, cw_status_name, cw_bucket, territory_name, has_recent_billing,
                ticket_count_ytd, active_contact_count, is_duplicate_hidden
         FROM customers WHERE is_mock = 0 ORDER BY name COLLATE NOCASE, id"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $dismissed = $pdo->query('SELECT group_key FROM customer_duplicate_dismissals')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $dismissed = array_flip($dismissed);

    $buckets = [];
    foreach ($rows as $r) {
        if ((int) $r['is_duplicate_hidden'] === 1 || ($r['cw_bucket'] ?? '') === 'excluded') {
            continue;
        }
        $buckets[relationships_dup_group_key((string) $r['name'])][] = $r;
    }

    $groups = [];
    foreach ($buckets as $key => $members) {
        if (count($members) < 2 || $key === '' || isset($dismissed[$key])) {
            continue;
        }
        $cands = [];
        foreach ($members as $r) {
            $id = (int) $r['id'];
            $touch = $pdo->prepare('SELECT touch_date FROM outgrow_last_touch_history WHERE customer_id = :id ORDER BY id DESC LIMIT 1');
            $touch->execute([':id' => $id]);
            $cands[] = [
                'id' => $id,
                'name' => $r['name'],
                'connectwise_id' => $r['connectwise_id'],
                'status' => $r['cw_status_name'],
                'bucket' => $r['cw_bucket'],
                'territory' => $r['territory_name'],
                'services' => relationships_dup_scalar($pdo, 'SELECT COUNT(*) FROM customer_services WHERE customer_id = :id', [':id' => $id]),
                'contacts' => (int) ($r['active_contact_count'] ?? 0),
                'recent_billing' => (int) ($r['has_recent_billing'] ?? 0),
                'tickets_ytd' => (int) ($r['ticket_count_ytd'] ?? 0),
                'meetings' => relationships_dup_scalar($pdo, 'SELECT COUNT(*) FROM customer_meetings WHERE customer_id = :id', [':id' => $id]),
                'last_outgrow_touch' => $touch->fetchColumn() ?: null,
            ];
        }
        // Suggested keeper: Active status first, then most synced data.
        $score = static fn (array $c): int =>
            ($c['bucket'] === 'active' ? 1000 : 0) + $c['services'] * 10 + $c['recent_billing'] * 5
            + min($c['contacts'], 20) + min($c['tickets_ytd'], 20);
        $best = $cands[0];
        foreach ($cands as $c) {
            if ($score($c) > $score($best)) {
                $best = $c;
            }
        }
        $groups[] = ['key' => $key, 'name' => $members[0]['name'], 'suggested_id' => $best['id'], 'customers' => $cands];
    }

    $hidden = $pdo->query(
        "SELECT o.customer_id, c.name, c.connectwise_id, o.keeper_customer_id, k.name AS keeper_name,
                k.connectwise_id AS keeper_connectwise_id, o.created_by, o.created_at
         FROM customer_duplicate_overrides o
         JOIN customers c ON c.id = o.customer_id
         LEFT JOIN customers k ON k.id = o.keeper_customer_id
         ORDER BY c.name COLLATE NOCASE"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return ['groups' => $groups, 'hidden' => $hidden];
}

/**
 * Keep $keeperId, hide each of $hideIds.
 * @param int[] $hideIds
 * @return array{hidden:int, services_moved:int}
 * @throws InvalidArgumentException on a bad selection
 */
function relationships_dup_keep(PDO $pdo, int $keeperId, array $hideIds, string $by): array
{
    $hideIds = array_values(array_unique(array_filter(array_map('intval', $hideIds), static fn (int $i): bool => $i > 0)));
    if ($keeperId <= 0 || !$hideIds) {
        throw new InvalidArgumentException('Pick one customer to keep and at least one to hide.');
    }
    if (in_array($keeperId, $hideIds, true)) {
        throw new InvalidArgumentException('The customer you keep cannot also be hidden.');
    }
    $get = $pdo->prepare('SELECT id, connectwise_id, cw_bucket, is_prospect_only, is_residential, is_duplicate_hidden, is_mock FROM customers WHERE id = :id');
    $get->execute([':id' => $keeperId]);
    $keeper = $get->fetch(PDO::FETCH_ASSOC);
    if (!$keeper || (int) $keeper['is_duplicate_hidden'] === 1) {
        throw new InvalidArgumentException('The customer to keep was not found or is already hidden.');
    }

    $pdo->beginTransaction();
    try {
        $hidden = 0;
        $moved = 0;
        foreach ($hideIds as $hid) {
            $get->execute([':id' => $hid]);
            $dup = $get->fetch(PDO::FETCH_ASSOC);
            if (!$dup || (int) $dup['is_duplicate_hidden'] === 1) {
                continue;
            }
            $pdo->prepare(
                'INSERT OR REPLACE INTO customer_duplicate_overrides
                 (customer_id, hidden_connectwise_id, keeper_customer_id, keeper_connectwise_id, prev_bucket, prev_is_prospect_only, prev_is_residential, created_by)
                 VALUES (:cid, :hcw, :kid, :kcw, :pb, :pp, :pr, :by)'
            )->execute([
                ':cid' => $hid, ':hcw' => $dup['connectwise_id'] !== '' ? $dup['connectwise_id'] : null,
                ':kid' => $keeperId, ':kcw' => $keeper['connectwise_id'],
                ':pb' => $dup['cw_bucket'], ':pp' => (int) $dup['is_prospect_only'], ':pr' => (int) $dup['is_residential'],
                ':by' => $by,
            ]);
            $pdo->prepare("UPDATE customers SET is_duplicate_hidden = 1, cw_bucket = 'excluded', is_prospect_only = 0, is_residential = 0 WHERE id = :id")
                ->execute([':id' => $hid]);
            $mv = $pdo->prepare('UPDATE customer_services SET customer_id = :k WHERE customer_id = :h');
            $mv->execute([':k' => $keeperId, ':h' => $hid]);
            $moved += $mv->rowCount();
            $hidden++;
        }
        // Anything already pointing at a row we just hid now points at the keeper.
        $pdo->prepare('UPDATE customer_duplicate_overrides SET keeper_customer_id = :k, keeper_connectwise_id = :kcw WHERE keeper_customer_id IN (' . implode(',', $hideIds) . ')')
            ->execute([':k' => $keeperId, ':kcw' => $keeper['connectwise_id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return ['hidden' => $hidden, 'services_moved' => $moved];
}

/** Undo a hide: the row returns with its previous bucket; the next sync refreshes the rest. */
function relationships_dup_restore(PDO $pdo, int $customerId): bool
{
    $st = $pdo->prepare('SELECT * FROM customer_duplicate_overrides WHERE customer_id = :id');
    $st->execute([':id' => $customerId]);
    $o = $st->fetch(PDO::FETCH_ASSOC);
    if (!$o) {
        return false;
    }
    $pdo->prepare('UPDATE customers SET is_duplicate_hidden = 0, cw_bucket = :b, is_prospect_only = :p, is_residential = :r WHERE id = :id')
        ->execute([':b' => $o['prev_bucket'], ':p' => (int) $o['prev_is_prospect_only'], ':r' => (int) $o['prev_is_residential'], ':id' => $customerId]);
    $pdo->prepare('DELETE FROM customer_duplicate_overrides WHERE customer_id = :id')->execute([':id' => $customerId]);
    return true;
}
