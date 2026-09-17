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
 * The fixed 9-person "Sending Representative" roster, verbatim
 * names/order/emails as Michael gave them (chat, 2026-09-17): the first
 * 6 names' emails come from relationships/api/catalog.php's
 * relationships_todo_roster_cw_email() (same 7-person roster minus Jake
 * Bradshaw, who isn't a sender here -- see ratesheet_admin_emails() below
 * for where Jake does show up); the last 3 (Courtney Cruz, Kasie Van
 * Fossen, Trey Hayden) came with their emails spelled out directly in
 * Michael's request. Not a ratesheet_users query, same reasoning as
 * relationships_todo_roster(): this list is the source of truth for the
 * dropdown regardless of who has actually signed in yet.
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
    ];
}

/**
 * Emails allowed to see EVERY rate sheet on the dashboard, per Michael:
 * "Michael, Trey, Kasie, Courtney, Claire, Jake and Casey should be able
 * to see all rate sheets." Jake Bradshaw isn't in the sender dropdown
 * above (he's not a rate-sheet sender, just a dashboard viewer) -- his
 * email comes from the same confirmed roster map in
 * relationships/api/catalog.php. Anyone signed in but NOT in this list
 * (Moe, Chester, Walter, or anyone else) sees only rows whose rep_email
 * matches their own signed-in email -- see requests.php's ?action=list.
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
