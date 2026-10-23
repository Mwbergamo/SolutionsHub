<?php
/**
 * call-experience-designer/api/_util.php
 *
 * Small shared helpers for every call-experience-designer/api/*.php
 * endpoint that requires a rep login: JSON responses, the shared-SSO
 * session check. Same shape as ratesheet/api/_util.php. NOT used by
 * public.php -- that endpoint is deliberately login-free (a customer
 * recording their own tracks has no SolutionsHub account) and has its
 * own token-based access check instead.
 *
 * Unlike ratesheet, there is no admin/sender roster here -- per Michael
 * (2026-10-23, asked directly): every signed-in rep sees every Call In
 * Experience request on the dashboard, and anyone signed in can send one.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

function call_experience_respond(int $httpCode, array $payload): never
{
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($httpCode);
    echo json_encode($payload);
    exit;
}

/**
 * Same safety net as ratesheet_install_error_handlers()/
 * register_install_error_handlers() -- turns an uncaught Throwable or a
 * real PHP fatal into a real JSON error response instead of a broken/
 * partial body.
 */
function call_experience_install_error_handlers(): void
{
    set_exception_handler(function (Throwable $e): void {
        if (!headers_sent()) {
            call_experience_respond(500, ['ok' => false, 'error' => 'Server error: ' . $e->getMessage()]);
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

function call_experience_read_json_body(int $maxBytes = 262144): array
{
    $raw = file_get_contents('php://input', false, null, 0, $maxBytes);
    $data = json_decode((string) $raw, true);
    return is_array($data) ? $data : [];
}

/** Delegates to the ONE shared Microsoft 365 session -- see auth/session.php. */
function call_experience_start_session(): void
{
    require_once __DIR__ . '/../../auth/session.php';
    auth_start_session();
}

/**
 * Returns the signed-in rep's row (id, name, email) or null.
 * $_SESSION['call_experience_user_id'] is set by auth/callback.php on
 * successful Microsoft sign-in (see auth/local-user.php), same pattern as
 * ratesheet_user_id/crc_user_id/register_user_id.
 */
function call_experience_current_user(PDO $pdo): ?array
{
    call_experience_start_session();
    $userId = $_SESSION['call_experience_user_id'] ?? null;
    if (!is_int($userId)) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT id, name, email FROM call_experience_users WHERE id = :id');
    $stmt->execute([':id' => $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}

/** Call at the top of any rep-facing endpoint. 401s and exits if not signed in. */
function call_experience_require_login(PDO $pdo): array
{
    $user = call_experience_current_user($pdo);
    if ($user === null) {
        call_experience_respond(401, ['ok' => false, 'error' => 'Not signed in.']);
    }
    return $user;
}
