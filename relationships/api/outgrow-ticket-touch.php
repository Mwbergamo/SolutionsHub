<?php
/**
 * relationships/api/outgrow-ticket-touch.php
 *
 * The "update Last OutGrow Touch" half of the Ticket -> OutGrow feature
 * (added 2026-10-09 per Michael): when a ticket is converted into an OutGrow
 * entry, find the customer by the ticket's Company name and set that
 * customer's OutGrow Last Touch to the DATE OF THE NOTE (not today's date,
 * which is what the contact-card tap flow logs).
 *
 * Writes through the same table and ConnectWise field that outgrow.php's
 * 'set' action uses (outgrow_last_touch_history, then the "OutGrow Last
 * Touch" Company custom field), with source = 'ticket'. Functions only, no
 * side effects at include time, so it can be unit-tested against an
 * in-memory SQLite (see tests/outgrow-ticket-touch-test.php).
 *
 * Rules, and why:
 *   - Company match is by name only: exact after normalizing case,
 *     punctuation and "&"/"and"; failing that, ignoring a trailing entity
 *     word (Inc, LLC, PC...). Never fuzzier than that. No match, or more
 *     than one equally-good match, means NOTHING is changed and the rep is
 *     told -- a wrong customer's date is worse than a missing one.
 *   - Last Touch only ever moves FORWARD. A ticket note older than (or
 *     equal to) the date already on file is left alone, so pasting an old
 *     ticket can't roll a customer's date back, and pasting the same
 *     ticket twice can't add a duplicate history row.
 *   - Territory scoping matches the rest of the app: a customer outside
 *     the signed-in rep's territories is not touched.
 *   - Saved locally first; the ConnectWise push is best-effort and its
 *     outcome is recorded on the history row, same as outgrow.php.
 */

declare(strict_types=1);

/** Lowercase, "&" -> "and", punctuation -> spaces, whitespace collapsed. */
function relationships_outgrow_company_key(string $name): string
{
    $s = strtolower(str_replace('&', ' and ', $name));
    $s = preg_replace('/[^a-z0-9]+/', ' ', $s) ?? $s;
    return trim($s);
}

/** Same, minus a leading "the" and any trailing entity words (inc, llc, pc ...). */
function relationships_outgrow_company_key_loose(string $name): string
{
    $s = relationships_outgrow_company_key($name);
    $s = preg_replace('/^the\s+/', '', $s) ?? $s;
    do {
        $before = $s;
        $s = preg_replace('/\s+(inc|incorporated|llc|l l c|llp|pllc|pc|p c|ltd|limited|corp|corporation|co|company)$/', '', $s) ?? $s;
    } while ($s !== $before);
    return trim($s);
}

/**
 * @return array{status:string, customer?:array<string,mixed>, candidates?:array<int,string>}
 *   status: 'matched' | 'none' | 'ambiguous'
 */
function relationships_outgrow_find_customer(PDO $pdo, string $company): array
{
    $want = relationships_outgrow_company_key($company);
    $wantLoose = relationships_outgrow_company_key_loose($company);
    if ($want === '') {
        return ['status' => 'none'];
    }

    $rows = $pdo->query('SELECT id, name, connectwise_id, territory_name FROM customers')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $isReal = static fn (array $r): bool => ($r['connectwise_id'] ?? '') !== '' && !str_starts_with((string) $r['connectwise_id'], 'MOCK-');

    foreach (['strict', 'loose'] as $pass) {
        $hits = array_values(array_filter($rows, static function (array $r) use ($pass, $want, $wantLoose): bool {
            return $pass === 'strict'
                ? relationships_outgrow_company_key((string) $r['name']) === $want
                : ($wantLoose !== '' && relationships_outgrow_company_key_loose((string) $r['name']) === $wantLoose);
        }));
        if (count($hits) > 1) {
            $real = array_values(array_filter($hits, $isReal));
            if (count($real) === 1) {
                $hits = $real; // a synced ConnectWise customer beats a mock/duplicate row
            }
        }
        if (count($hits) === 1) {
            return ['status' => 'matched', 'customer' => $hits[0]];
        }
        if (count($hits) > 1) {
            return ['status' => 'ambiguous', 'candidates' => array_map(static fn (array $r): string => (string) $r['name'], $hits)];
        }
    }
    return ['status' => 'none'];
}

function relationships_outgrow_fmt_ymd(string $ymd): string
{
    $d = DateTimeImmutable::createFromFormat('Y-m-d', $ymd);
    return $d === false ? $ymd : $d->format('n/j/Y');
}

/**
 * @param array<string,mixed> $user            crc_users row (id, name)
 * @param array<int,string>|null $allowedTerritories  null = unrestricted
 * @param callable|null $cwWrite               fn(string $cwCompanyId, string $dateYmd): void -- may throw
 * @return array{status:string, message:string, customer_id?:int, customer_name?:string, date?:string, cw_push?:array<string,mixed>}
 *   status: updated | already | newer_exists | no_company | no_date | no_match | ambiguous | out_of_scope
 */
function relationships_outgrow_apply_touch(PDO $pdo, array $user, ?array $allowedTerritories, string $company, ?string $noteDate, ?callable $cwWrite = null): array
{
    if (trim($company) === '') {
        return ['status' => 'no_company', 'message' => 'No company on the ticket, so Last OutGrow Touch was not updated.'];
    }
    if ($noteDate === null) {
        return ['status' => 'no_date', 'message' => 'Couldn’t read the note’s date, so Last OutGrow Touch was not updated.'];
    }

    $found = relationships_outgrow_find_customer($pdo, $company);
    if ($found['status'] === 'none') {
        return ['status' => 'no_match', 'message' => 'No Relationships customer named “' . $company . '”, so Last OutGrow Touch was not updated.'];
    }
    if ($found['status'] === 'ambiguous') {
        return ['status' => 'ambiguous', 'message' => 'More than one customer matches “' . $company . '” (' . implode('; ', $found['candidates']) . '), so Last OutGrow Touch was not updated.'];
    }

    $customer = $found['customer'];
    $customerId = (int) $customer['id'];
    $name = (string) $customer['name'];
    $base = ['customer_id' => $customerId, 'customer_name' => $name, 'date' => $noteDate];

    if ($allowedTerritories !== null && !in_array((string) ($customer['territory_name'] ?? ''), $allowedTerritories, true)) {
        return $base + ['status' => 'out_of_scope', 'message' => $name . ' is outside your assigned territories, so Last OutGrow Touch was not updated.'];
    }

    $stmt = $pdo->prepare('SELECT MAX(touch_date) FROM outgrow_last_touch_history WHERE customer_id = :id');
    $stmt->execute([':id' => $customerId]);
    $latest = $stmt->fetchColumn();
    if (is_string($latest) && $latest !== '' && $latest >= $noteDate) {
        $pretty = relationships_outgrow_fmt_ymd($latest);
        return $base + ($latest === $noteDate
            ? ['status' => 'already', 'message' => $name . '’s Last OutGrow Touch is already ' . $pretty . '.']
            : ['status' => 'newer_exists', 'message' => $name . '’s Last OutGrow Touch (' . $pretty . ') is newer than this note, so it was left as is.']);
    }

    // Local save first, unconditionally -- the ConnectWise push can never make it fail.
    $pdo->prepare(
        'INSERT INTO outgrow_last_touch_history (customer_id, touch_date, source, set_by_user_id, set_by_name)
         VALUES (:cid, :date, \'ticket\', :uid, :by)'
    )->execute([':cid' => $customerId, ':date' => $noteDate, ':uid' => $user['id'], ':by' => $user['name']]);
    $historyId = (int) $pdo->lastInsertId();

    $cwPush = ['attempted' => false, 'status' => 'skipped', 'error' => null];
    $cwId = $customer['connectwise_id'] ?? null;
    $cwId = ($cwId === null || $cwId === '' || str_starts_with((string) $cwId, 'MOCK-')) ? null : (string) $cwId;
    if ($cwId !== null && $cwWrite !== null) {
        $cwPush['attempted'] = true;
        try {
            $cwWrite($cwId, $noteDate);
            $cwPush['status'] = 'pushed';
        } catch (Throwable $e) {
            $cwPush['status'] = 'error';
            $cwPush['error'] = $e->getMessage();
        }
    }
    $pdo->prepare('UPDATE outgrow_last_touch_history SET cw_push_status = :s, cw_push_error = :e WHERE id = :id')
        ->execute([':s' => $cwPush['attempted'] ? $cwPush['status'] : null, ':e' => $cwPush['error'], ':id' => $historyId]);

    $message = 'Last OutGrow Touch for ' . $name . ' set to ' . relationships_outgrow_fmt_ymd($noteDate) . '.';
    if ($cwPush['status'] === 'error') {
        $message .= ' Saved here, but it didn’t reach ConnectWise: ' . $cwPush['error'];
    }
    return $base + ['status' => 'updated', 'message' => $message, 'cw_push' => $cwPush];
}
