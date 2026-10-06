<?php
declare(strict_types=1);

/**
 * auth/commissions-access.php
 *
 * Who may see the Commissions sub-app (added 2026-10-05, per Michael:
 * "only available to Kasie Van Fossen, Michael Bergamo, Trey Hayden and
 * Jaclyn Kelley ... It should not be visible to any other signed in user").
 *
 * ONE list, used in two places:
 *   - auth/me.php adds `can_view_commissions` so the portal home page only
 *     renders the Commissions card for these users (cosmetic).
 *   - commissions/api/_util.php refuses every API call from anyone else
 *     with a 403 (the real enforcement -- hiding the card alone is not
 *     security).
 * Matching is case-insensitive on the Microsoft 365 sign-in email.
 */

const COMMISSIONS_ALLOWED_EMAILS = [
    'kvanfossen@codebluetechnology.com', // Kasie Van Fossen
    'mbergamo@codebluetechnology.com',   // Michael Bergamo
    'thayden@codebluetechnology.com',    // Trey Hayden
    'jkelley@codebluetechnology.com',    // Jaclyn Kelley
];

function commissions_email_allowed(?string $email): bool
{
    if ($email === null || $email === '') {
        return false;
    }
    return in_array(strtolower(trim($email)), COMMISSIONS_ALLOWED_EMAILS, true);
}

/**
 * The sales manager's own commission (1.25% of gross profit) is private to
 * Michael Bergamo -- not even the other allow-listed users may see it.
 * Enforced server-side in commissions/api/manager.php.
 */
const COMMISSIONS_MANAGER_EMAIL = 'mbergamo@codebluetechnology.com';

function commissions_is_manager(?string $email): bool
{
    return $email !== null && strcasecmp(trim($email), COMMISSIONS_MANAGER_EMAIL) === 0;
}
