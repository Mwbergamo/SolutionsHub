<?php
/**
 * commissions/api/_util.php
 *
 * Shared by every commissions/api/*.php endpoint: JSON responses and --
 * the important part -- the access check. EVERY endpoint calls
 * commissions_require_access(), which responds 401 when nobody is signed in
 * and 403 for anyone who is not on the four-person allow-list in
 * auth/commissions-access.php. Hiding the home-page card is cosmetic; this
 * is what actually keeps the data private.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../../auth/session.php';
require_once __DIR__ . '/../../auth/commissions-access.php';

function commissions_respond(int $httpCode, array $payload): never
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    http_response_code($httpCode);
    echo json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

function commissions_read_json_body(int $maxBytes = 262144): array
{
    $raw = file_get_contents('php://input', false, null, 0, $maxBytes);
    $data = json_decode((string) $raw, true);
    return is_array($data) ? $data : [];
}

/**
 * Signed-in AND allow-listed, or the request ends here.
 * The PHP session is released immediately so a long sync step doesn't block
 * the user's other requests (PHP file sessions are exclusive-locked).
 *
 * @return array{name: string, email: string}
 */
function commissions_require_access(): array
{
    $user = auth_current_user();
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    if ($user === null) {
        commissions_respond(401, ['ok' => false, 'error' => 'Not signed in.']);
    }
    if (!commissions_email_allowed($user['email'] ?? '')) {
        commissions_respond(403, ['ok' => false, 'error' => 'You do not have access to Commissions.']);
    }
    return ['name' => (string) $user['name'], 'email' => (string) $user['email']];
}

/** Same-site check for state-changing requests (defense in depth, like mail/send-*.php). */
function commissions_require_post(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        commissions_respond(405, ['ok' => false, 'error' => 'Method not allowed.']);
    }
    $host = $_SERVER['HTTP_HOST'] ?? '';
    foreach (['HTTP_ORIGIN', 'HTTP_REFERER'] as $h) {
        $v = $_SERVER[$h] ?? '';
        if ($v !== '') {
            $vh = parse_url($v, PHP_URL_HOST);
            $hh = parse_url('//' . $host, PHP_URL_HOST);
            if (!is_string($vh) || !is_string($hh) || strcasecmp($vh, $hh) !== 0) {
                commissions_respond(403, ['ok' => false, 'error' => 'Cross-site request refused.']);
            }
            break;
        }
    }
}

function commissions_period_label(string $month): string
{
    $d = DateTimeImmutable::createFromFormat('!Y-m', $month);
    return $d ? $d->format('F Y') : $month;
}
