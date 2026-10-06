<?php
/**
 * commissions/api/calc.php
 *
 * The commission rules, as pure functions (no database, no ConnectWise) so
 * they can be tested on their own. Rules, per Michael 2026-10-05 (revised same day):
 *
 *   commission = rep % x gross profit
 *   gross profit (per line) = price billed - assumed cost
 *     - Product line: price = quantity x unit price on the invoice;
 *       cost = the invoice Products tab line's own Unit Cost / Ext Cost
 *       (2026-10-06); the Product Catalog's cost is only the backup when the
 *       invoice line carries no cost.
 *       A Do Not Bill line (product or time) is ignored completely. A billable
 *       line with no price takes the invoice line's own cost, never the catalog's.
 *     - Block Time Agreement invoice (no products/time/additions): hours =
 *       invoice subtotal / the agreement's Work Roles Rate (a hard-set rate);
 *       price = the subtotal, cost = those hours x labor cost per hour.
 *     - Time line: price = hours x the hourly rate on the invoice;
 *       cost = hours x labor cost per hour (default $90, editable in
 *       Settings). The cost is ALWAYS the labor setting, never the rate.
 *   rep %: the rep's base %. Chester's drops from 30% to 15% on Agreement
 *   invoices once the agreement is more than one year old at the invoice
 *   date (agreement_after_year_pct on the rep; empty = no drop).
 *   a line that lost money (cost > price) is flagged is_loss and its negative
 *   commission is subtracted from the payee's total.
 *   Territories pay the payees whose words they contain (see
 *   commissions_reps_for_territory); no match = house account, no commission.
 *   Shared territory "Arcus + Chester Sienko": Chester is paid his % of GP
 *   first (30%, or 15% on agreements 365+ days old); Arcus is then paid his shared
 *   % (Settings, default 30) of the REMAINDER after Chester's commission (see commissions_line_payouts).
 */

declare(strict_types=1);

function commissions_money(float $v): float
{
    return round($v, 2);
}

/**
 * True if an invoice dated $invoiceDate (YYYY-MM-DD) falls 365 or more days
 * after the agreement start date $agreementStart (YYYY-MM-DD).
 */
function commissions_agreement_over_year(?string $agreementStart, ?string $invoiceDate): bool
{
    if ($agreementStart === null || $agreementStart === '' || $invoiceDate === null || $invoiceDate === '') {
        return false;
    }
    try {
        $start = new DateTimeImmutable(substr($agreementStart, 0, 10));
        $inv = new DateTimeImmutable(substr($invoiceDate, 0, 10));
    } catch (Throwable $e) {
        return false;
    }
    // "365 days old or more" (Michael, 2026-10-05).
    return $start->diff($inv)->days >= 365 && $inv >= $start;
}

/**
 * The % to pay on a line. $rep = ['base_pct' => float, 'agreement_after_year_pct' => ?float].
 */
function commissions_rep_pct(array $rep, bool $isAgreementInvoice, bool $overYear): float
{
    $base = (float) ($rep['base_pct'] ?? 0);
    if ($isAgreementInvoice && $overYear && isset($rep['agreement_after_year_pct']) && $rep['agreement_after_year_pct'] !== null && $rep['agreement_after_year_pct'] !== '') {
        return (float) $rep['agreement_after_year_pct'];
    }
    return $base;
}

/**
 * Money columns for one line. A losing line (cost > price) has a negative
 * gross profit and therefore a NEGATIVE commission: it is subtracted from the
 * payee's total (Michael, 2026-10-05).
 *
 * @return array{gp: float, commission: float, is_loss: int}
 */
function commissions_line_money(float $price, float $cost, float $pct): array
{
    $gp = commissions_money($price - $cost);
    return [
        'gp' => $gp,
        'commission' => commissions_money($gp * $pct / 100.0),
        'is_loss' => $gp < 0 ? 1 : 0,
    ];
}

/** Default for Arcus's rate on the remainder when it shares a territory with Chester (2026-10-06); editable in Settings (setting `shared_arcus_pct`). */
const COMMISSIONS_SHARED_ARCUS_PCT = 30.0;

/**
 * What each payee of ONE line earns. Normally every payee is paid its own %
 * of the line's gross profit. The one exception is the shared
 * "Arcus + Chester Sienko" territory (a territory that pays BOTH Chester and
 * Arcus): Chester is paid first -- his base %, or his after-a-year % when the
 * agreement is 365+ days old -- and Arcus is then paid 30% of the REMAINDER,
 * i.e. of the gross profit left after Chester's commission (examples at the default 30%):
 *     GP $1,000, Chester 30%  -> Chester $300, Arcus 30% x $700 = $210
 *     GP $1,000, Chester 15%  -> Chester $150, Arcus 30% x $850 = $255
 * A losing line nets the same way (the remainder is negative too).
 *
 * `pct` is each payee's effective % of the line's gross profit (so Arcus shows
 * 21%, not 30%, in the first example); `commission` is the dollars.
 * $trusted = false (no lines / lookup error) pays everyone $0.
 *
 * @param array<int,array> $repsById rep rows keyed by id
 * @param int[]            $payees   rep ids the territory pays
 * @return array<int,array{pct:float,commission:float}> keyed by rep id
 */
function commissions_line_payouts(array $repsById, array $payees, bool $isAgreementInvoice, bool $overYear, float $price, float $cost, bool $trusted = true, ?float $sharedArcusPct = null): array
{
    $arcusPct = $sharedArcusPct ?? COMMISSIONS_SHARED_ARCUS_PCT;
    $gp = commissions_money($price - $cost);
    $chesterId = null;
    $arcusId = null;
    foreach ($payees as $rid) {
        if (!isset($repsById[$rid])) {
            continue;
        }
        $name = strtolower(trim((string) ($repsById[$rid]['name'] ?? '')));
        if ($name === 'chester') {
            $chesterId = $rid;
        } elseif ($name === 'arcus') {
            $arcusId = $rid;
        }
    }
    $shared = $chesterId !== null && $arcusId !== null;

    $chesterPct = 0.0;
    $chesterComm = 0.0;
    if ($shared) {
        $chesterPct = $trusted ? commissions_rep_pct($repsById[$chesterId], $isAgreementInvoice, $overYear) : 0.0;
        $chesterComm = commissions_money($gp * $chesterPct / 100.0);
    }

    $out = [];
    foreach ($payees as $rid) {
        if (!isset($repsById[$rid])) {
            continue;
        }
        if ($shared && $rid === $chesterId) {
            $out[$rid] = ['pct' => $chesterPct, 'commission' => $chesterComm];
        } elseif ($shared && $rid === $arcusId) {
            $pct = $trusted ? $arcusPct * (1.0 - $chesterPct / 100.0) : 0.0;
            $comm = $trusted ? commissions_money(($gp - $chesterComm) * $arcusPct / 100.0) : 0.0;
            $out[$rid] = ['pct' => round($pct, 4), 'commission' => $comm];
        } else {
            $pct = $trusted ? commissions_rep_pct($repsById[$rid], $isAgreementInvoice, $overYear) : 0.0;
            $out[$rid] = ['pct' => $pct, 'commission' => commissions_line_money($price, $cost, $pct)['commission']];
        }
    }
    return $out;
}

/**
 * Which payees a ConnectWise territory pays. $reps rows carry
 * `territory_match`: a comma-separated list of words/phrases; the payee is
 * paid when the territory contains one of them as a whole word, ignoring case
 * ("Moe" matches "Moe Okeilli (new accounts)" and "Trey + Moe Okeilli", not
 * "Smoe"). EVERY matching active payee is returned, which is how a split works
 * ("Arcus + Chester Sienko" pays both). Empty result = house account: no commission.
 *
 * @return int[]
 */
function commissions_reps_for_territory(array $reps, ?string $territory): array
{
    $territory = trim((string) $territory);
    $out = [];
    if ($territory === '') {
        return $out;
    }
    foreach ($reps as $rep) {
        if (empty($rep['active'])) {
            continue;
        }
        foreach (explode(',', (string) $rep['territory_match']) as $token) {
            $token = trim($token);
            if ($token === '') {
                continue;
            }
            if (preg_match('/(?<![A-Za-z0-9])' . preg_quote($token, '/') . '(?![A-Za-z0-9])/i', $territory) === 1) {
                $out[] = (int) $rep['id'];
                break;
            }
        }
    }
    return $out;
}

/** 'YYYY-MM' for "now" in the business timezone, shifted by $monthsBack. */
function commissions_month_key(int $monthsBack = 0, ?DateTimeImmutable $now = null): string
{
    $now = $now ?? new DateTimeImmutable('now', new DateTimeZone('America/New_York'));
    $first = $now->modify('first day of this month');
    if ($monthsBack !== 0) {
        $first = $first->modify(($monthsBack > 0 ? '-' : '+') . abs($monthsBack) . ' months');
    }
    return $first->format('Y-m');
}

function commissions_status_is_closed(string $statusName): bool
{
    $s = strtolower(trim($statusName));
    return $s === 'closed' || $s === 'closed - emailed' || $s === 'closed-emailed';
}

function commissions_status_is_void(string $statusName): bool
{
    $s = strtolower($statusName);
    return str_contains($s, 'void') || str_contains($s, 'cancel');
}
