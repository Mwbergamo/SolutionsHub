<?php
/**
 * relationships/api/contacts-admin-probe.php
 *
 * TEMPORARY, READ-ONLY diagnostic for Michael's 2026-10-09 "Account
 * Contacts" request: a clickable contact count on each customer dashboard
 * opening a full list (ALL contacts, not just active -- status, name,
 * email, phone, ConnectWise Contact Type) with inline edit (push to
 * ConnectWise) and a "mark Inactive" action, plus creating new contacts.
 *
 * Why this probe exists before any of that gets built: this codebase
 * already has TWO guesses about Contact field shapes that have never been
 * confirmed against a real ConnectWise response --
 *   - connectwise-contacts-sync-core.php's email extractor (matches a
 *     communicationItems entry whose type name contains "email")
 *   - contact-card.php's phone extractor (tries type names containing
 *     "direct"/"mobile"/"cell"/"phone", in that order, then falls back to
 *     "not email, not fax")
 * Separately, Register's register_cw_create_contact() DID confirm a real
 * create-time shape end-to-end (communicationItems[{type:{id},value,
 * communicationType,defaultFlag}], communicationType literally "Email" or
 * "Phone", type id 1 = Email, type id 2 = Direct, types:[{id:3}] = "End
 * User") -- but that's the shape of a contact THIS codebase created. An
 * existing, years-old ConnectWise contact entered by hand may not follow
 * the same convention, and this feature needs to display/edit THOSE too.
 * This probe pulls real existing contacts (not ones this codebase made)
 * to check.
 *
 * Also unconfirmed anywhere in this project: the full real list of
 * ConnectWise Contact Types (only id 3 "End User" is known, from Register's
 * create-contact work) and whether Contact PATCH works at all on this
 * instance -- Company PATCH is confirmed BROKEN (500s with a DateTime
 * error) but Service Ticket PATCH is confirmed WORKING, so each entity
 * needs its own check; this file only probes reads, a separate write-test
 * file will check PATCH/PUT once a real test contact is chosen.
 *
 * Gated behind relationships_require_login() like every other endpoint in
 * this app. Every action in this file is READ-ONLY -- nothing here writes
 * to ConnectWise or to this app's own database. This build environment
 * has no network path to connect.codebluetechnology.com, so Michael needs
 * to open each action below (logged into the Relationships app) and paste
 * back what comes back, especially any error text.
 *
 * GET ?action=probe-contact-types
 *   -> GET /company/contacts/types, unrestricted -- the full real
 *      reference list (id + name/description) for the Contact Type
 *      dropdown, beyond the one id (3, "End User") already known.
 *
 * GET ?action=probe-contacts&customer_id=N
 *   -> every ConnectWise contact (active AND inactive -- no inactiveFlag
 *      condition) for the LOCAL customer row N (resolved to its
 *      connectwise_id), full unrestricted field set. Looking for: real
 *      communicationItems shapes on contacts this codebase did NOT
 *      create (does communicationType/type.name still hold?), what
 *      inactiveFlag looks like on an actually-inactive contact, and real
 *      `types` values in everyday use (not just "End User").
 *
 * GET ?action=probe-contact&contact_id=N
 *   -> one specific ConnectWise contact by its own id, unrestricted --
 *      for a closer look at a contact probe-contacts flagged as
 *      interesting (e.g. one with no email, or marked inactive).
 *
 * DELETE THIS FILE (and its write-test sibling once that exists) once the
 * real Contact field shapes are confirmed and the actual Account Contacts
 * feature is built against them -- same lifecycle as every other
 * temporary probe in this project (ticket_invoice_probe.php, cw-catalog-
 * probe.php, projects_probe.php, ...).
 */

declare(strict_types=1);

require_once __DIR__ . '/_util.php';
require_once __DIR__ . '/connectwise.php';
require_once __DIR__ . '/db.php';

$pdo = relationships_db();
relationships_require_login($pdo);

$action = $_GET['action'] ?? '';

/**
 * Runs one candidate GET and captures success or the exact ConnectWise
 * error, never throwing -- same shape as projects_probe.php's helper of
 * the same name (kept local to each probe file, since both are meant to
 * be deleted once their findings are folded into real code).
 */
function relationships_contacts_probe_attempt(string $label, string $path, array $query = []): array
{
    try {
        $result = relationships_cw_request($path, $query);
        return ['label' => $label, 'ok' => true, 'result' => $result];
    } catch (Throwable $e) {
        return ['label' => $label, 'ok' => false, 'error' => $e->getMessage()];
    }
}

if ($action === 'probe-contact-types') {
    $out = relationships_contacts_probe_attempt(
        'GET /company/contacts/types (unrestricted)',
        '/company/contacts/types',
        ['pageSize' => '100']
    );
    relationships_respond(200, ['ok' => true, 'probe' => $out]);
}

if ($action === 'probe-contacts') {
    $customerId = (int) ($_GET['customer_id'] ?? 0);
    if ($customerId <= 0) {
        relationships_respond(400, ['ok' => false, 'error' => 'customer_id is required, e.g. ?action=probe-contacts&customer_id=123']);
    }
    $stmt = $pdo->prepare('SELECT id, connectwise_id, name FROM customers WHERE id = :id');
    $stmt->execute([':id' => $customerId]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($customer === false) {
        relationships_respond(404, ['ok' => false, 'error' => 'No local customer with id ' . $customerId]);
    }
    if (empty($customer['connectwise_id']) || str_starts_with((string) $customer['connectwise_id'], 'MOCK-')) {
        relationships_respond(400, ['ok' => false, 'error' => 'Customer ' . $customerId . ' (' . $customer['name'] . ') has no real ConnectWise id (mock or unset).']);
    }

    $out = relationships_contacts_probe_attempt(
        'GET /company/contacts (unrestricted, no inactiveFlag filter) company/id=' . $customer['connectwise_id'],
        '/company/contacts',
        ['conditions' => 'company/id=' . (int) $customer['connectwise_id'], 'pageSize' => '50']
    );
    relationships_respond(200, [
        'ok' => true,
        'customer' => ['id' => $customer['id'], 'name' => $customer['name'], 'connectwise_id' => $customer['connectwise_id']],
        'probe' => $out,
    ]);
}

if ($action === 'probe-contact') {
    $contactId = (int) ($_GET['contact_id'] ?? 0);
    if ($contactId <= 0) {
        relationships_respond(400, ['ok' => false, 'error' => 'contact_id is required, e.g. ?action=probe-contact&contact_id=456']);
    }
    $out = relationships_contacts_probe_attempt(
        'GET /company/contacts/' . $contactId . ' (unrestricted)',
        '/company/contacts/' . $contactId
    );
    relationships_respond(200, ['ok' => true, 'probe' => $out]);
}

relationships_respond(400, [
    'ok' => false,
    'error' => 'Unknown action.',
    'available' => ['probe-contact-types', 'probe-contacts&customer_id=N', 'probe-contact&contact_id=N'],
]);
