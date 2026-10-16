<?php
/**
 * client-finance/api/_util.php
 *
 * Shared by every client-finance/api/*.php endpoint, same shape as
 * commissions/api/_util.php: JSON responses and the access check. EVERY
 * endpoint calls client_finance_require_access(), which responds 401 when
 * nobody is signed in and 403 for anyone not on the allow-list (currently
 * the same four people as Commissions -- see
 * auth/client-finance-access.php). Hiding the Finance-page card is
 * cosmetic; this is what actually keeps customer financials private.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../../auth/session.php';
require_once __DIR__ . '/../../auth/client-finance-access.php';

function client_finance_respond(int $httpCode, array $payload): never
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    http_response_code($httpCode);
    echo json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

function client_finance_read_json_body(int $maxBytes = 262144): array
{
    $raw = file_get_contents('php://input', false, null, 0, $maxBytes);
    $data = json_decode((string) $raw, true);
    return is_array($data) ? $data : [];
}

/**
 * Signed-in AND allow-listed, or the request ends here. The PHP session is
 * released immediately so a long sync step doesn't block the user's other
 * requests (PHP file sessions are exclusive-locked) -- same reasoning as
 * commissions_require_access().
 *
 * @return array{name: string, email: string}
 */
function client_finance_require_access(): array
{
    $user = auth_current_user();
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    if ($user === null) {
        client_finance_respond(401, ['ok' => false, 'error' => 'Not signed in.']);
    }
    if (!client_finance_email_allowed($user['email'] ?? '')) {
        client_finance_respond(403, ['ok' => false, 'error' => 'You do not have access to Client Finance.']);
    }
    return ['name' => (string) $user['name'], 'email' => (string) $user['email']];
}

/** Same-site check for state-changing requests (defense in depth, like commissions_require_post()). */
function client_finance_require_post(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        client_finance_respond(405, ['ok' => false, 'error' => 'Method not allowed.']);
    }
    $host = $_SERVER['HTTP_HOST'] ?? '';
    foreach (['HTTP_ORIGIN', 'HTTP_REFERER'] as $h) {
        $v = $_SERVER[$h] ?? '';
        if ($v !== '') {
            $vh = parse_url($v, PHP_URL_HOST);
            $hh = parse_url('//' . $host, PHP_URL_HOST);
            if (!is_string($vh) || !is_string($hh) || strcasecmp($vh, $hh) !== 0) {
                client_finance_respond(403, ['ok' => false, 'error' => 'Cross-site request refused.']);
            }
            break;
        }
    }
}
