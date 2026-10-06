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
 *       cost = quantity x the Product Catalog's cost (static, regardless of
 *       when it was sold -- also true for Agreement-class items).
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
