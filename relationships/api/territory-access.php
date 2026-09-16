<?php
/**
 * relationships/api/territory-access.php
 *
 * Rep-based territory filtering -- added 2026-09-16 per Michael:
 *   "I want to filter companies based on the logged in rep. Each rep has a
 *   set of clients they are responsible for... All unnamed users should be
 *   able to see all companies by default."
 *
 * The rule, exactly as specified:
 *   - crc_territory_reps (see db.php) is an ALLOW-LIST. A signed-in CRC
 *     whose email has zero rows there is UNRESTRICTED -- sees every
 *     customer, same as before this feature existed. That covers every
 *     rep except the ones Michael explicitly named.
 *   - A CRC whose email has one or more rows there is restricted to
 *     customers whose customers.territory_name matches one of those rows.
 *     A customer with no territory_name yet (not synced, or ConnectWise
 *     has no territory set) is invisible to a restricted rep until it is
 *     tagged -- there is no fallback "show it anyway" for unset values,
 *     since that would defeat the point of the restriction.
 *   - Matching is case-sensitive, exact-string, against the *synced*
 *     territory name from ConnectWise. Michael was warned (see the
 *     territory-admin picker in app.js) to pick names from the actual
 *     synced list rather than free-typing, since a typo here would
 *     silently show that rep zero customers with no error anywhere.
 *
 * Applied "everywhere" a customer list or a single customer's detail is
 * reachable (Michael's own words, via AskUserQuestion 2026-09-16) --
 * customers.php, dashboard.php, checklist.php, peoplefirst.php,
 * meetings.php. Sync-core files are deliberately NOT filtered by this --
 * syncing must always see and process every real customer regardless of
 * who (if anyone) is signed in when a sync happens to run.
 */

declare(strict_types=1);

require_once __DIR__ . '/_util.php';

/**
 * Hardcoded admin allow-list for the new territory-management screen.
 * Michael asked (2026-09-16) that this new admin screen be restricted to
 * just him for now, by email: mbergamo@codebluetechnology.com. Kept as a
 * simple constant rather than a DB-driven role, matching how lightly this
 * whole app treats admin/roles elsewhere -- easy to extend to a list or a
 * real roles table later if more admins are added.
 */
const RELATIONSHIPS_TERRITORY_ADMIN_EMAILS = [
    'mbergamo@codebluetechnology.com',
];

/**
 * True if the given email (case-insensitive) is allowed to manage
 * rep -> territory assignments via territory-admin.php.
 */
function relationships_is_territory_admin_email(?string $email): bool
{
    if ($email === null || $email === '') {
        return false;
    }
    $normalized = strtolower(trim($email));
    foreach (RELATIONSHIPS_TERRITORY_ADMIN_EMAILS as $adminEmail) {
        if ($normalized === strtolower($adminEmail)) {
            return true;
        }
    }
    return false;
}

/**
 * Convenience wrapper for the common case: is the CURRENTLY SIGNED IN user
 * (per the shared session) a territory admin? Returns false (never throws)
 * if nobody is signed in.
 */
function relationships_current_user_is_territory_admin(PDO $pdo): bool
{
    $user = relationships_current_user($pdo);
    if ($user === null) {
        return false;
    }
    return relationships_is_territory_admin_email((string) ($user['email'] ?? ''));
}

/**
 * The heart of this feature. Returns:
 *   - null                => unrestricted (see every customer). This is
 *     the default for nobody-signed-in AND for any signed-in rep with no
 *     crc_territory_reps rows -- "all unnamed users should be able to see
 *     all companies by default" (Michael, 2026-09-16).
 *   - string[] (non-empty) => restricted to exactly these territory_name
 *     values.
 *
 * Callers apply this as: null => no extra WHERE clause; array => an
 * `AND customers.territory_name IN (...)` (or equivalent) clause. Never
 * returns an empty array -- that would be indistinguishable from "no
 * restriction" if a caller forgot to branch on null vs [].
 */
function relationships_allowed_territories(PDO $pdo): ?array
{
    $user = relationships_current_user($pdo);
    if ($user === null) {
        return null;
    }
    return relationships_allowed_territories_for_email($pdo, (string) ($user['email'] ?? ''));
}

/**
 * Pure, session-free lookup used by relationships_allowed_territories()
 * above and by territory-admin.php (which needs to show/query a specific
 * rep's territories regardless of who's currently signed in). Kept
 * separate so it's testable without a live session and reusable outside
 * the "current user" case.
 */
function relationships_allowed_territories_for_email(PDO $pdo, string $email): ?array
{
    $email = strtolower(trim($email));
    if ($email === '') {
        return null;
    }

    $stmt = $pdo->prepare('SELECT territory_name FROM crc_territory_reps WHERE lower(email) = :email');
    $stmt->execute([':email' => $email]);
    $territories = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if ($territories === false || count($territories) === 0) {
        return null;
    }
    return array_values(array_unique(array_map('strval', $territories)));
}

/**
 * Builds a SQL fragment + bound params for restricting a `customers`-based
 * query to $territories (the array form returned by
 * relationships_allowed_territories() -- never call this with null; check
 * for null first and skip filtering entirely, since an empty IN() is
 * invalid SQL and "IN (NULL)" would silently match nothing in a way that's
 * easy to mistake for "unrestricted").
 *
 * $customersAlias lets callers that JOIN customers under an alias (or
 * reference it unqualified) get the right column reference either way.
 *
 * Returns ['sql' => 'AND customers.territory_name IN (?,?)', 'params' => [...]]
 * so callers can splice $sql into their WHERE clause and array_merge()
 * $params into their existing bound param list (positional -- see each
 * call site for whether that file uses positional or named params
 * elsewhere; named is used here to avoid clashing with existing `?`
 * placeholders in the larger queries this gets spliced into).
 */
function relationships_territory_filter_sql(array $territories, string $customersAlias = 'customers'): array
{
    $params = [];
    $placeholders = [];
    foreach (array_values($territories) as $i => $territory) {
        $key = ':territory_filter_' . $i;
        $placeholders[] = $key;
        $params[$key] = $territory;
    }
    $sql = 'AND ' . $customersAlias . '.territory_name IN (' . implode(',', $placeholders) . ')';
    return ['sql' => $sql, 'params' => $params];
}

/**
 * Single-customer gate for detail/action endpoints (customers.php's
 * `detail` action, peoplefirst.php's `log`, meetings.php's per-customer
 * actions, etc). Call after loading the customer row's territory_name.
 * Responds 403 and exits if the signed-in rep is restricted and this
 * customer's territory isn't in their allow-list (including when the
 * customer has no territory_name at all -- see file header). No-op for an
 * unrestricted rep (or nobody signed in, if the caller allows that).
 */
/**
 * Convenience lookup for endpoints that receive a bare customer_id (rather
 * than already having the customer row in hand) and need to territory-gate
 * an action on it -- checklist.php's get/set, peoplefirst.php's log,
 * meetings.php's per-customer actions. Returns null both when the customer
 * doesn't exist and when it has no territory_name -- either way,
 * relationships_require_territory_scope() treats that as "not visible" to
 * a restricted rep, which is the safe default.
 */
function relationships_customer_territory(PDO $pdo, int $customerId): ?string
{
    $stmt = $pdo->prepare('SELECT territory_name FROM customers WHERE id = :id');
    $stmt->execute([':id' => $customerId]);
    $value = $stmt->fetchColumn();
    return $value === false || $value === null ? null : (string) $value;
}

function relationships_require_territory_scope(?array $allowedTerritories, ?string $customerTerritoryName): void
{
    if ($allowedTerritories === null) {
        return; // unrestricted
    }
    if ($customerTerritoryName !== null && in_array($customerTerritoryName, $allowedTerritories, true)) {
        return;
    }
    relationships_respond(403, ['ok' => false, 'error' => 'This customer is outside your assigned territories.']);
}
