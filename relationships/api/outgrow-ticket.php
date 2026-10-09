<?php
/**
 * relationships/api/outgrow-ticket.php
 *
 * POST /relationships/api/outgrow-ticket.php
 *   { ticket_text: "<pasted ConnectWise service ticket text>" }
 *   -> { ok: true, values: {...}, warnings: [...], formstack_url: "https://..." }
 *   or { ok: false, error: "..." }
 *
 * Added 2026-10-09 per Michael -- see outgrow-ticket-parse.php for the
 * field mapping and for why this builds a pre-filled link instead of
 * submitting the form itself (reCAPTCHA, no Formstack API access).
 * Nothing is stored; parsing is stateless.
 */

declare(strict_types=1);

require_once __DIR__ . '/_util.php';
require_once __DIR__ . '/outgrow-ticket-parse.php';

$pdo = relationships_db();
relationships_require_login($pdo);

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

relationships_respond(200, [
    'ok' => true,
    'values' => $parsed['values'],
    'warnings' => $parsed['warnings'],
    'formstack_url' => relationships_outgrow_form_url($parsed['values']),
]);
