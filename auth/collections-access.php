<?php
declare(strict_types=1);

/**
 * auth/collections-access.php
 *
 * Who may open the Collections app (open ConnectWise invoices to collect),
 * added 2026-10-07 per Michael:
 *   - Courtney Cruz, Trey Hayden, Michael Bergamo and Kasie Van Fossen see
 *     ALL reps' / territories' open invoices.
 *   - Moe and Chester see ONLY their own territories (listed below, matched
 *     case-insensitively on the exact ConnectWise territory name).
 * Nobody else gets in (403 from commissions/api/ar.php; auth/me.php adds
 * `can_view_collections` so the home page only shows the card to these people).
 *
 * Matching is case-insensitive on the Microsoft 365 sign-in email.
 */

const COLLECTIONS_ALL_ACCESS_EMAILS = [
    'ccruz@codebluetechnology.com',      // Courtney Cruz
    'thayden@codebluetechnology.com',    // Trey Hayden
    'mbergamo@codebluetechnology.com',   // Michael Bergamo
    'kvanfossen@codebluetechnology.com', // Kasie Van Fossen
];

/** sign-in email => the commissions rep name (commissions `reps.name`) whose accounts that person sees */
const COLLECTIONS_REP_EMAILS = [
    'mokeilli@codebluetechnology.com' => 'Moe',
    'csienko@codebluetechnology.com'  => 'Chester',
];

/** rep name => the exact ConnectWise territories that are theirs for collections */
const COLLECTIONS_REP_TERRITORIES = [
    'Moe' => ['Moe Okeilli (new accounts)', 'Trey + Moe Okeilli', 'ITTS Trading (old accounts)'],
    'Chester' => ['Arcus + Chester Sienko', "Chester Sienko's Accounts"],
];

/**
 * @return array{scope:string, rep_name:?string}|null  scope 'all' | 'rep'; null = no access
 */
function collections_access_for(?string $email): ?array
{
    $e = strtolower(trim((string) $email));
    if ($e === '') {
        return null;
    }
    if (in_array($e, COLLECTIONS_ALL_ACCESS_EMAILS, true)) {
        return ['scope' => 'all', 'rep_name' => null];
    }
    if (isset(COLLECTIONS_REP_EMAILS[$e])) {
        return ['scope' => 'rep', 'rep_name' => COLLECTIONS_REP_EMAILS[$e]];
    }
    return null;
}
