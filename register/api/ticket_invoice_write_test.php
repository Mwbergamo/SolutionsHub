<?php
/**
 * register/api/ticket_invoice_write_test.php
 *
 * TEMPORARY, LOGIN-GATED diagnostic for Task 2 of Michael's 2026-09-30
 * Register request -- unlike ticket_invoice_probe.php (its read-only
 * sibling in this same folder), every action in THIS file makes a REAL
 * WRITE against production ConnectWise: changing a real ticket's status,
 * creating a real invoice, and changing that real invoice's status.
 * These are the three writes register-app.md's "Part 2" section flags as
 * still untested -- per Michael's own "probe first, then build" rule,
 * each one gets its own careful live test here, against a dedicated test
 * ticket Michael created for exactly this purpose (Service Ticket
 * #953916, "Test Service Ticket - Register App"), BEFORE any of this
 * logic is folded into the real feature.
 *
 * This build environment has no network path to
 * connect.codebluetechnology.com, so Claude cannot run this itself --
 * Michael needs to open each action below (logged into the register app)
 * IN THIS ORDER and report back the full JSON each time, especially any
 * error text, before the next one is run:
 *
 *   1. GET ?action=write-test-close-ticket&ticket_id=953916
 *      -> fetches ticket 953916, confirms it's on one of the two
 *         confirmed Professional Services boards, and -- unless it's
 *         already Closed -- tries PATCH first (a single JSON-Patch
 *         replace op on /status), falling back to the proven
 *         PUT-fetch-modify-send-back-with-auto-strip approach (same
 *         mechanism register_cw_put_company_with_retry() uses for
 *         Company, generalized here) if PATCH fails. Re-reads the ticket
 *         afterward to confirm the status actually changed. Safe to
 *         re-run -- a second run just reports "already_closed".
 *   2. GET ?action=write-test-create-invoice&ticket_id=953916
 *      -> refuses unless step 1 already succeeded (ticket must already
 *         be Closed) and the ticket has at least one Billable time entry
 *         not already tied to an invoice. Sends the minimal payload
 *         {type:"Standard", applyToType:"Ticket", applyToId:953916,
 *         company:{id:<the ticket's company>}} to POST /finance/invoices
 *         -- nothing more, to see exactly what ConnectWise needs/defaults
 *         on its own. THIS IS THE ONE REAL, NOT-CLEANLY-REVERSIBLE WRITE
 *         IN THIS FILE -- if it fails with a specific "X is required"
 *         error, STOP and report the full response back rather than
 *         guessing another field; if it succeeds, note the real invoice
 *         id/number it returns for step 3, and the confirmed "New"
 *         status id register-app.md has been waiting on.
 *   3. GET ?action=write-test-close-invoice&invoice_id=<id from step 2>
 *      -> same PATCH-then-PUT-fallback approach as step 1, writing
 *         status id 3 ("Closed", confirmed real id, Michael's own
 *         answer 2026-10-09) on the invoice just created. Safe to
 *         re-run -- a second run just reports "already_closed".
 *
 * Delete this file (and its read-only sibling) once Part 2 ships, same
 * standing discipline as every other probe file in this project.
 */

declare(strict_types=1);

require_once __DIR__ . '/_util.php';
require_once __DIR__ . '/connectwise.php';

register_install_error_handlers();

$pdo = register_db();
register_require_login($pdo);

$action = $_GET['action'] ?? '';

/**
 * Same wrapper as ticket_invoice_probe.php's register_probe_try() --
 * reused here so a failed write still comes back as clean, readable
 * JSON (ok:false + the real ConnectWise error text) instead of a raw
 * PHP fatal, even though what runs inside is a real write, not a read.
 */
function register_write_test_try(string $label, callable $fn): array
{
    try {
        return ['label' => $label, 'ok' => true, 'result' => $fn()];
    } catch (Throwable $e) {
        return ['label' => $label, 'ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Generalized version of customers.php's register_cw_put_company_with_retry()
 * -- fetch the full record, overlay the fields to change, PUT the whole
 * thing back, and if ConnectWise names a specific "<field> can only be
 * used when creating" offender, strip that exact field (resolving
 * ConnectWise's internal name, e.g. "typeIds", back to the real JSON key,
 * e.g. "types", when they differ) and retry. Never tested against
 * Service Ticket or Invoice before this file -- only proven against
 * Company so far (see register-app.md's Customer/Company/Contact
 * Feature section) -- used here only as the fallback if a plain PATCH
 * fails first.
 */
function register_cw_put_entity_with_retry(string $path, array $fieldsToSet, int $maxAttempts = 5): array
{
    $full = register_cw_request($path, [], 'GET', null, 20, 6);
    $modified = $fieldsToSet + $full;

    for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
        try {
            return register_cw_request($path, [], 'PUT', $modified, 20, 6);
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            $jsonStart = strpos($msg, '{');
            $decoded = $jsonStart !== false ? json_decode(substr($msg, $jsonStart), true) : null;
            $offendingField = null;
            if (is_array($decoded) && isset($decoded['errors']) && is_array($decoded['errors'])) {
                foreach ($decoded['errors'] as $err) {
                    $field = $err['field'] ?? null;
                    $errMsg = $err['message'] ?? '';
                    if (is_string($field) && $field !== '' && stripos($errMsg, 'can only be used when creating') !== false) {
                        $offendingField = $field;
                        break;
                    }
                }
            }
            $realKey = null;
            if ($offendingField !== null) {
                $candidates = [$offendingField];
                if (substr($offendingField, -3) === 'Ids') {
                    $base = substr($offendingField, 0, -3);
                    $candidates[] = $base . 's';
                    $candidates[] = $base;
                }
                foreach ($candidates as $candidate) {
                    if (array_key_exists($candidate, $modified)) {
                        $realKey = $candidate;
                        break;
                    }
                }
            }
            if ($realKey === null) {
                throw new RegisterConnectWiseError('Could not update ' . $path . ': ' . $msg);
            }
            unset($modified[$realKey]);
        }
    }

    throw new RegisterConnectWiseError('Could not update ' . $path . ' after ' . $maxAttempts . ' attempts.');
}

// Real "Closed" status id per board, confirmed live 2026-10-08 (see
// register-app.md's Part 2 section) -- a board has no "board" relation
// to itself, so this is only ever used against a ticket's embedded
// board.name, never as a /service/boards condition.
function register_closed_status_id_for_board(string $boardName): ?int
{
    return match ($boardName) {
        'Professional Services - RIC' => 17,
        'Professional Services - WAR' => 504,
        default => null,
    };
}

if ($action === 'write-test-close-ticket') {
    $ticketId = (int) ($_GET['ticket_id'] ?? 0);
    if ($ticketId <= 0) {
        register_respond(400, ['ok' => false, 'error' => 'ticket_id is required.']);
    }
    $out = register_write_test_try("REAL WRITE: close ticket $ticketId", function () use ($ticketId) {
        $before = register_cw_request("/service/tickets/$ticketId", [], 'GET', null, 20, 6);
        $boardName = $before['board']['name'] ?? '';
        $closedStatusId = register_closed_status_id_for_board($boardName);
        if ($closedStatusId === null) {
            throw new RuntimeException("Ticket $ticketId is on board \"$boardName\", not one of the two confirmed Professional Services boards -- refusing to guess a Closed status id.");
        }
        if (($before['status']['id'] ?? null) === $closedStatusId) {
            return [
                'already_closed' => true,
                'board_name' => $boardName,
                'status' => $before['status'] ?? null,
            ];
        }

        // Try PATCH first (a single JSON-Patch replace op) -- cheaper,
        // and unlike Company (confirmed broken on this instance, see
        // register-app.md's Customer/Company/Contact Feature section),
        // Service Ticket PATCH has never been tested anywhere in this
        // codebase before this call.
        $patchError = null;
        $patchSucceeded = false;
        try {
            register_cw_request("/service/tickets/$ticketId", [], 'PATCH', [
                ['op' => 'replace', 'path' => '/status', 'value' => ['id' => $closedStatusId]],
            ], 20, 6);
            $patchSucceeded = true;
        } catch (Throwable $e) {
            $patchError = $e->getMessage();
        }

        $putError = null;
        $putAttempted = false;
        if (!$patchSucceeded) {
            $putAttempted = true;
            try {
                register_cw_put_entity_with_retry("/service/tickets/$ticketId", ['status' => ['id' => $closedStatusId]]);
            } catch (Throwable $e) {
                $putError = $e->getMessage();
            }
        }

        $after = register_cw_request("/service/tickets/$ticketId", [], 'GET', null, 20, 6);

        return [
            'board_name' => $boardName,
            'target_status_id' => $closedStatusId,
            'before_status' => $before['status'] ?? null,
            'patch_succeeded' => $patchSucceeded,
            'patch_error' => $patchError,
            'put_attempted' => $putAttempted,
            'put_error' => $putError,
            'after_status' => $after['status'] ?? null,
            'write_succeeded' => ($after['status']['id'] ?? null) === $closedStatusId,
        ];
    });
    register_respond(200, ['ok' => true, 'probe' => $out]);
}

if ($action === 'write-test-create-invoice') {
    $ticketId = (int) ($_GET['ticket_id'] ?? 0);
    if ($ticketId <= 0) {
        register_respond(400, ['ok' => false, 'error' => 'ticket_id is required.']);
    }
    $out = register_write_test_try("REAL WRITE: create invoice for ticket $ticketId", function () use ($ticketId) {
        $ticket = register_cw_request("/service/tickets/$ticketId", [], 'GET', null, 20, 6);
        $boardName = $ticket['board']['name'] ?? '';
        $closedStatusId = register_closed_status_id_for_board($boardName);
        if ($closedStatusId === null) {
            throw new RuntimeException("Ticket $ticketId is on board \"$boardName\", not one of the two confirmed Professional Services boards -- refusing.");
        }
        if (($ticket['status']['id'] ?? null) !== $closedStatusId) {
            $currentStatusName = $ticket['status']['name'] ?? '?';
            throw new RuntimeException("Ticket $ticketId is not yet Closed (current status: \"$currentStatusName\"). Run write-test-close-ticket first.");
        }

        $companyId = $ticket['company']['id'] ?? null;
        if ($companyId === null) {
            throw new RuntimeException("Ticket $ticketId has no company reference -- cannot create an invoice without one.");
        }

        $entries = register_cw_request('/time/entries', [
            'conditions' => "chargeToId=$ticketId",
            'pageSize' => 50,
        ], 'GET', null, 20, 6);

        $billable = array_values(array_filter($entries, fn ($e) => ($e['billableOption'] ?? '') === 'Billable'));
        $alreadyInvoiced = array_values(array_filter($billable, fn ($e) => !empty($e['invoice'])));
        $readyToInvoice = array_values(array_filter($billable, fn ($e) => empty($e['invoice'])));

        if (count($billable) === 0) {
            throw new RuntimeException("Ticket $ticketId has no Billable time entries at all (" . count($entries) . " total time entries found) -- nothing to invoice. Log a real billable time entry on this ticket in ConnectWise first, then re-run.");
        }
        if (count($readyToInvoice) === 0) {
            return [
                'already_fully_invoiced' => true,
                'billable_count' => count($billable),
                'already_invoiced_count' => count($alreadyInvoiced),
                'note' => 'Every billable entry on this ticket already references an invoice -- refusing to create a duplicate.',
                'entries' => $billable,
            ];
        }

        // Deliberately the bare minimum implied by the confirmed
        // applyToType/applyToId structural finding (register-app.md's
        // Part 2 section) -- the whole point of this test is to see how
        // much ConnectWise fills in on its own for a Standard/ticket
        // invoice before assuming anything else is required.
        $payload = [
            'type' => 'Standard',
            'applyToType' => 'Ticket',
            'applyToId' => $ticketId,
            'company' => ['id' => $companyId],
        ];

        $createError = null;
        $created = null;
        try {
            $created = register_cw_request('/finance/invoices', [], 'POST', $payload, 25, 8);
        } catch (Throwable $e) {
            $createError = [
                'message' => $e->getMessage(),
                'response_body' => $e instanceof RegisterConnectWiseError ? $e->responseBody : null,
            ];
        }

        return [
            'ticket_company_id' => $companyId,
            'billable_count' => count($billable),
            'ready_to_invoice_count' => count($readyToInvoice),
            'payload_sent' => $payload,
            'create_error' => $createError,
            'created_invoice' => $created,
        ];
    });
    register_respond(200, ['ok' => true, 'probe' => $out]);
}

if ($action === 'write-test-close-invoice') {
    $invoiceId = (int) ($_GET['invoice_id'] ?? 0);
    if ($invoiceId <= 0) {
        register_respond(400, ['ok' => false, 'error' => 'invoice_id is required.']);
    }
    $out = register_write_test_try("REAL WRITE: close invoice $invoiceId (status id 3)", function () use ($invoiceId) {
        $before = register_cw_request("/finance/invoices/$invoiceId", [], 'GET', null, 20, 6);
        if (($before['status']['id'] ?? null) === 3) {
            return ['already_closed' => true, 'status' => $before['status'] ?? null];
        }

        $patchError = null;
        $patchSucceeded = false;
        try {
            register_cw_request("/finance/invoices/$invoiceId", [], 'PATCH', [
                ['op' => 'replace', 'path' => '/status', 'value' => ['id' => 3]],
            ], 20, 6);
            $patchSucceeded = true;
        } catch (Throwable $e) {
            $patchError = $e->getMessage();
        }

        $putError = null;
        $putAttempted = false;
        if (!$patchSucceeded) {
            $putAttempted = true;
            try {
                register_cw_put_entity_with_retry("/finance/invoices/$invoiceId", ['status' => ['id' => 3]]);
            } catch (Throwable $e) {
                $putError = $e->getMessage();
            }
        }

        $after = register_cw_request("/finance/invoices/$invoiceId", [], 'GET', null, 20, 6);

        return [
            'before_status' => $before['status'] ?? null,
            'patch_succeeded' => $patchSucceeded,
            'patch_error' => $patchError,
            'put_attempted' => $putAttempted,
            'put_error' => $putError,
            'after_status' => $after['status'] ?? null,
            'write_succeeded' => ($after['status']['id'] ?? null) === 3,
        ];
    });
    register_respond(200, ['ok' => true, 'probe' => $out]);
}

register_respond(400, ['ok' => false, 'error' => 'Unknown action.']);
