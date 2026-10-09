<?php
/**
 * relationships/api/outgrow-ticket.php
 *
 * POST /relationships/api/outgrow-ticket.php
 *   { ticket_text: "<pasted ConnectWise service ticket text>" }
 *   -> { ok: true, values: {...}, warnings: [...], formstack_url: "https://...",
 *        touch: { status, message, customer_name?, date?, ... } }
 *   or { ok: false, error: "..." }
 *
 *   `touch` is the Last OutGrow Touch update (added 2026-10-09 per Michael):
 *   the matched customer's date is set to the NOTE's date -- see
 *   outgrow-ticket-touch.php for the matching and "only moves forward"
 *   rules. It never fails the request: whatever happens there, the form link
 *   still comes back.
 *
 * Added 2026-10-09 per Michael -- see outgrow-ticket-parse.php for the
 * field mapping and for why this builds a pre-filled link instead of
 * submitting the form itself (reCAPTCHA, no Formstack API access).
 * Parsing itself is stateless; the only thing written is the Last OutGrow
 * Touch update described above.
 */

declare(strict_types=1);

require_once __DIR__ . '/_util.php';
require_once __DIR__ . '/territory-access.php';
require_once __DIR__ . '/connectwise-outgrow.php';
require_once __DIR__ . '/outgrow-ticket-parse.php';
require_once __DIR__ . '/outgrow-ticket-touch.php';

$pdo = relationships_db();
$user = relationships_require_login($pdo);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    relationships_respond(405, ['ok' => false, 'error' => 'Method not allowed.']);
}

$data = relationships_read_json_body();
$text = trim((string) ($data['ticket_text'] ?? ''));
if ($text === '') {
    relationships_respond(400, ['ok' => false, 'error' => 'Paste the ticket text first.']);
}

$parsed = relationships_outgrow_parse_ticket($text);
if (!$parsed['ok']) {
    relationships_respond(422, ['ok' => false, 'error' => $parsed['error']]);
}

try {
    $touch = relationships_outgrow_apply_touch(
        $pdo,
        $user,
        relationships_allowed_territories($pdo),
        (string) $parsed['values']['company'],
        $parsed['values']['note_date'],
        static function (string $cwCompanyId, string $dateYmd): void {
            relationships_cw_outgrow_write($cwCompanyId, $dateYmd);
        }
    );
} catch (Throwable $e) {
    error_log('[relationships/outgrow-ticket] touch update failed: ' . $e->getMessage());
    $touch = ['status' => 'error', 'message' => 'Couldn’t update Last OutGrow Touch (see server log). The OutGrow form link below still works.'];
}

relationships_respond(200, [
    'ok' => true,
    'values' => $parsed['values'],
    'warnings' => $parsed['warnings'],
    'formstack_url' => relationships_outgrow_form_url($parsed['values']),
    'touch' => $touch,
]);
