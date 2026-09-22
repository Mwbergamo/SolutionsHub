<?php
/**
 * ratesheet/api/_util.php
 *
 * Small shared helpers for every ratesheet/api/*.php endpoint that
 * requires a rep login: JSON responses, the shared-SSO session check, and
 * the fixed sender/admin rosters Michael gave verbatim. Same shape as
 * register/api/_util.php and relationships/api/_util.php. NOT used by
 * public.php -- that endpoint is deliberately login-free (a customer
 * filling out their own signup has no SolutionsHub account) and has its
 * own token-based access check instead.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

function ratesheet_respond(int $httpCode, array $payload): never
{
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($httpCode);
    echo json_encode($payload);
    exit;
}

/**
 * Same safety net as register_install_error_handlers() -- turns an
 * uncaught Throwable or a real PHP fatal into a real JSON error response
 * instead of a broken/partial body. See register/api/_util.php's version
 * for the full reasoning (Bluehost execution-time-limit history).
 */
function ratesheet_install_error_handlers(): void
{
    set_exception_handler(function (Throwable $e): void {
        if (!headers_sent()) {
            ratesheet_respond(500, ['ok' => false, 'error' => 'Server error: ' . $e->getMessage()]);
        }
    });

    register_shutdown_function(function (): void {
        $error = error_get_last();
        if ($error === null || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            return;
        }
        if (headers_sent()) {
            return;
        }
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Server error: ' . $error['message']]);
    });
}

function ratesheet_read_json_body(int $maxBytes = 262144): array
{
    $raw = file_get_contents('php://input', false, null, 0, $maxBytes);
    $data = json_decode((string) $raw, true);
    return is_array($data) ? $data : [];
}

/** Delegates to the ONE shared Microsoft 365 session -- see auth/session.php. */
function ratesheet_start_session(): void
{
    require_once __DIR__ . '/../../auth/session.php';
    auth_start_session();
}

/**
 * Returns the signed-in rep's row (id, name, email) or null.
 * $_SESSION['ratesheet_user_id'] is set by auth/callback.php on
 * successful Microsoft sign-in (see auth/local-user.php), same pattern as
 * crc_user_id/register_user_id.
 */
function ratesheet_current_user(PDO $pdo): ?array
{
    ratesheet_start_session();
    $userId = $_SESSION['ratesheet_user_id'] ?? null;
    if (!is_int($userId)) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT id, name, email FROM ratesheet_users WHERE id = :id');
    $stmt->execute([':id' => $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}

/** Call at the top of any rep-facing endpoint. 401s and exits if not signed in. */
function ratesheet_require_login(PDO $pdo): array
{
    $user = ratesheet_current_user($pdo);
    if ($user === null) {
        ratesheet_respond(401, ['ok' => false, 'error' => 'Not signed in.']);
    }
    return $user;
}

/**
 * The "Sending Representative" roster, verbatim names/emails as Michael
 * gave them (chat, 2026-09-17): the first 6 names' emails come from
 * relationships/api/catalog.php's relationships_todo_roster_cw_email()
 * (same 7-person roster minus Jake Bradshaw, who isn't a sender here --
 * see ratesheet_admin_emails() below for where Jake does show up); the
 * next 3 (Courtney Cruz, Kasie Van Fossen, Trey Hayden) came with their
 * emails spelled out directly in Michael's request. Not a
 * ratesheet_users query, same reasoning as relationships_todo_roster():
 * this list is the source of truth for the dropdown regardless of who
 * has actually signed in yet.
 *
 * Daemian Caron and Kevin Headley added 2026-09-18, per Michael, with
 * their emails spelled out directly in his request the same way
 * Courtney/Kasie/Trey's were -- appended to the end rather than
 * alphabetized in, so the dropdown order for the original 9 names never
 * shifts. Keep app.js's SENDER_ROSTER array (names only) in sync with
 * this list if it's ever changed again.
 *
 * @return array<string,string> name => email
 */
function ratesheet_sender_roster(): array
{
    return [
        'Chester Sienko' => 'Csienko@codebluetechnology.com',
        'Moe Okeilli' => 'Mokeilli@codebluetechnology.com',
        'Walter Drew' => 'Wdrew@codebluetechnology.com',
        'Claire Hayden' => 'Chayden@codebluetechnology.com',
        'Casey Mayes' => 'cmayes@codebluetechnology.com',
        'Michael Bergamo' => 'Mbergamo@codebluetechnology.com',
        'Courtney Cruz' => 'Ccruz@codebluetechnology.com',
        'Kasie Van Fossen' => 'Kvanfossen@codebluetechnology.com',
        'Trey Hayden' => 'Thayden@codebluetechnology.com',
        'Daemian Caron' => 'Dcaron@codebluetechnology.com',
        'Kevin Headley' => 'Kheadley@codebluetechnology.com',
    ];
}

/**
 * Emails allowed to see EVERY rate sheet on the dashboard, per Michael:
 * "Michael, Trey, Kasie, Courtney, Claire, Jake and Casey should be able
 * to see all rate sheets." Jake Bradshaw isn't in the sender dropdown
 * above (he's not a rate-sheet sender, just a dashboard viewer) -- his
 * email comes from the same confirmed roster map in
 * relationships/api/catalog.php. Anyone signed in but NOT in this list
 * sees only rows whose rep_email matches their own signed-in email --
 * see requests.php's ?action=list.
 *
 * Charlie Trible added 2026-09-18 as an admin (full visibility), per
 * Michael -- his email spelled out directly in the request, same pattern
 * as the sender-roster additions above.
 *
 * Daemian Caron and Kevin Headley added 2026-09-22 as admins (full
 * visibility), per Michael's walk-in-rate-sheet request: they previously
 * saw only rows they personally sent (not in this list at all) --
 * promoted to match Charlie Trible's full-dashboard access.
 *
 * @return string[] lowercased emails
 */
function ratesheet_admin_emails(): array
{
    return [
        'mbergamo@codebluetechnology.com',
        'thayden@codebluetechnology.com',
        'kvanfossen@codebluetechnology.com',
        'ccruz@codebluetechnology.com',
        'chayden@codebluetechnology.com',
        'jbradshaw@codebluetechnology.com',
        'cmayes@codebluetechnology.com',
        'ctrible@codebluetechnology.com',
        'dcaron@codebluetechnology.com',
        'kheadley@codebluetechnology.com',
    ];
}

function ratesheet_is_admin(array $user): bool
{
    return in_array(strtolower((string) $user['email']), ratesheet_admin_emails(), true);
}

/** Warsaw / Richmond hourly rate, per Michael (2026-09-17): same rate for Commercial or Residential. */
function ratesheet_hourly_rate(string $location): float
{
    return $location === 'Richmond' ? 180.00 : 173.25;
}

/**
 * ConnectWise Territory, per Sending Representative -- added 2026-09-17
 * (follow-up), per Michael: "the customer should be created under their
 * direct territory (Moe = Moe Okeilli's Account, Chester = Chester
 * Sienko's Accounts, Trey = Trey Hayden's Accounts, Michael = House
 * Accounts, all relationship coordinators = House Accounts."
 *
 * Only Moe, Chester, and Trey have their own named ConnectWise Territory;
 * every other name in ratesheet_sender_roster() (Walter Drew, Claire
 * Hayden, Casey Mayes, Michael Bergamo, Courtney Cruz, Kasie Van Fossen)
 * falls under "all relationship coordinators = House Accounts" -- this
 * function returns null for those, meaning House Accounts (territory id
 * 45, same confirmed id register/api/customers.php already uses).
 *
 * Returns a SEARCH TERM, not a ConnectWise territory id -- this build
 * environment has no way to look up the real Territory ids for "Moe
 * Okeilli's Account" etc. (no live ConnectWise access from here, and the
 * only confirmed id on file is 45/House Accounts), so
 * ratesheet_cw_resolve_territory_id() in public.php resolves this to a
 * real id with a live ConnectWise name search at signup time instead of
 * a hardcoded id, falling back to House Accounts if no match is found.
 * The full name (not just last name) is used as the search term so Trey
 * Hayden's territory search can't accidentally match Claire Hayden's.
 *
 * Worth Michael double-checking the first live signup for each of these
 * three lands in the right territory -- if he already knows the real
 * ConnectWise territory ids, those can replace this live-search
 * resolution with a straight hardcoded map, same as House Accounts' 45.
 *
 * @return string|null search term, or null for House Accounts
 */
function ratesheet_rep_territory_search_term(string $repName): ?string
{
    static $map = [
        'Moe Okeilli' => 'Moe Okeilli',
        'Chester Sienko' => 'Chester Sienko',
        'Trey Hayden' => 'Trey Hayden',
    ];
    return $map[$repName] ?? null;
}

/** ConnectWise Territory id for "House Accounts" -- confirmed (register/api/customers.php). */
const RATESHEET_HOUSE_ACCOUNTS_TERRITORY_ID = 45;

/**
 * The literal ConnectWise Company Status name Michael says exists on this
 * instance for a not-yet-paying account (2026-09-18): "the Billing Status
 * of that company should be marked 'Credit Hold' until Invoicing team
 * changes it manually with a card/ach added in Alt Pay." Used both to
 * resolve the real status id at signup (ratesheet_cw_resolve_status_id_by_name()
 * in connectwise.php) and to recognize when Invoicing has since changed
 * it away from this (the dashboard's "Payment Added" green state -- see
 * requests.php).
 */
const RATESHEET_CREDIT_HOLD_STATUS_NAME = 'Credit Hold';

/**
 * The legal text shown under the signature block on the public signup
 * page, verbatim per Michael (chat, 2026-09-17). Single source of truth
 * -- signup.js keeps its own copy for the public (unauthenticated) form
 * since it can't call an authenticated endpoint, but every other use
 * (the customer's own emailed copy, and the rep-facing printable
 * "accepted terms" record) reads it from here.
 */
function ratesheet_legal_text(): string
{
    return <<<'TEXT'
I agree to pay CodeBlue Technology for services performed in the amounts specified within this rate agreement.

Taxes, shipping, handling and other fees may apply. We reserve the right to cancel orders arising from pricing or other errors.

Acceptance and Incorporation by Reference This Order together with the Master Services Agreement and Service Attachments and other terms and conditions identified on Exhibit A, all of which are incorporated herein by reference (collectively, the "Agreement") is between CodeBlue Technology (sometimes referred to as "we," "us," "our," "CBT," or "Provider"), and the customer identified on the Order (sometimes referred to as "you," "your," or "Client"). This Agreement is effective as of the date the Client accepts the Order (the "Effective Date").

By signing or accepting this Order, Client acknowledges, represents, and warrants that it has read and agrees to the terms and conditions identified on Exhibit A to this Order which are incorporated as if fully set forth herein. The parties hereby agree that electronic signatures to this Order shall be relied upon and will bind them to the obligations stated herein. Each party hereby warrants and represents that it has the express authority to execute this Agreement(s). Provider may make changes to the Agreement at any time. If there are changes, Provider will revise the date at the top of the document. Provider may or may not provide Client with additional notice regarding such changes. Client should review the terms and conditions regularly. Unless otherwise noted, the amended terms and conditions will be effective immediately, and your continued use of the Services thereafter constitutes your acceptance of the changes.

If you do not agree to the amended terms and conditions, you must stop using the Services immediately. Please note, you may incur a termination fee or other third-party fees, if applicable. You may access the current version of the terms and conditions at any time by visiting https://codebluetechnology.com/legal. The parties, acting through their authorized officers, hereby execute this Agreement.
TEXT;
}

/** The checkbox-of-understanding text, verbatim per Michael (chat, 2026-09-17). */
function ratesheet_checkbox_text(): string
{
    return 'By signing below or clicking, Client acknowledges, represents and warrants that it has read and agrees to the terms and conditions in the following documents, which are incorporated herein by reference and can be found on Exhibit A in the PDF Version of any subsequent proposal.';
}
