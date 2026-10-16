<?php
declare(strict_types=1);

/**
 * auth/client-finance-access.php
 *
 * Who may open the Client Finance sub-app (added 2026-10-16, per Michael's
 * AskUserQuestion answer: "Same as Commissions" -- Kasie Van Fossen,
 * Michael Bergamo, Trey Hayden and Jaclyn Kelley only).
 *
 * This is deliberately just a thin wrapper around
 * COMMISSIONS_ALLOWED_EMAILS/commissions_email_allowed() rather than its
 * own separate list -- Michael's answer was "the same list", and keeping
 * it as one shared list (rather than two lists that happen to start
 * identical) means a future access change made in one place can't
 * silently drift out of sync with the other. If Client Finance's access
 * ever needs to diverge from Commissions', give it its own
 * CLIENT_FINANCE_ALLOWED_EMAILS list here instead of editing
 * commissions-access.php.
 *
 * Enforced in two places, same pattern as every other sub-app:
 *   - auth/me.php adds `can_view_client_finance` so the portal home page
 *     only renders the card for these users (cosmetic).
 *   - client-finance/api/_util.php refuses every API call from anyone
 *     else with a 403 (the real enforcement).
 */

require_once __DIR__ . '/commissions-access.php';

function client_finance_email_allowed(?string $email): bool
{
    return commissions_email_allowed($email);
}
