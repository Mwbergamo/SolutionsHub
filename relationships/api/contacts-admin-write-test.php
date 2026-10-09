<?php
/**
 * relationships/api/contacts-admin-write-test.php
 *
 * REAL-WRITE test actions for the Account Contacts feature (claude/
 * relationships-account-contacts.md), kept apart from the read-only
 * contacts-admin-probe.php since every action here writes to real
 * ConnectWise. Same split as Register's ticket_invoice_write_test.php
 * vs. ticket_invoice_probe.php.
 *
 * Why this exists: contacts-admin-probe.php (2026-10-09) confirmed real
 * Contact field shapes for READING -- full Contact Type list (8 real
 * values, id 3 "End User" default), and a real multi-item
 * communicationItems example (contact 185, Trey Hayden: Cell id4 +
 * Direct id2 default + Email id1 default, communicationType literally
 * "Phone"/"Email" -- confirms Register's create-time shape generalizes to
 * existing contacts too, and that `defaultFlag` marks the primary item
 * per type). It also found that MANY contacts have no phone
 * communicationItem at all (Oriole Landscaping's 3 contacts, email only)
 * -- the Contact's own top-level defaultPhoneNbr/defaultPhoneType can
 * reflect the COMPANY's shared switchboard number in that case, not a
 * personal line, so the real feature reads the primary phone from
 * communicationItems (communicationType="Phone", prefer defaultFlag=true)
 * same as email, not from defaultPhoneNbr.
 *
 * What's still unconfirmed and why NOTHING here is folded into the real
 * feature yet: nobody has ever PATCHed or PUT an existing Contact on this
 * ConnectWise instance. Company PATCH is confirmed BROKEN (500s with a
 * DateTime error, see connectwise.php's relationships_cw_put_company_with_
 * retry() header); Service Ticket PATCH is confirmed WORKING (Register,
 * Part 2); Contact could go either way. Likewise, editing an EXISTING
 * communicationItem (e.g. changing a stored phone/email value) has never
 * been attempted -- only CREATING one via POST .../communications is
 * proven (Register's register_cw_create_contact()).
 *
 * Every write below targets a DISPOSABLE TEST CONTACT, never a real
 * customer's contact -- action 1 creates it, and every other action takes
 * that contact's id as a parameter so nothing here can accidentally touch
 * a real person's record. Mark the test contact Inactive when done, and
 * delete it from ConnectWise's own UI afterward if you don't want it
 * lingering (no delete action here -- Contact DELETE has never been
 * confirmed to exist either, and isn't needed for this feature).
 *
 * Gated behind relationships_require_login() like every other endpoint in
 * this app. This build environment has no network path to
 * connect.codebluetechnology.com, so Michael needs to run these himself,
 * logged into the Relationships app, in the order listed, and paste back
 * each result (especially any error text).
 *
 * GET ?action=write-test-create-contact&company_id=N&confirm=yes-create-test-contact
 *   -> Creates ONE disposable test contact under company N (Register's
 *      proven shape: firstName/lastName/company/title/types:[{id:3}]),
 *      then adds a phone (type id 2 "Direct") and email (type id 1
 *      "Email") communication item, same two-step POST Register already
 *      confirmed. Returns the new contact's id and both communication
 *      item ids -- feed these into the actions below.
 *
 * GET ?action=write-test-toggle-inactive&contact_id=N
 *   -> Reads the test contact's current inactiveFlag, flips it (PATCH
 *      [{op:replace,path:/inactiveFlag,value:!current}], falling back to
 *      a full PUT if PATCH fails), and reports which method worked plus
 *      the before/after value. Safe to run twice (flips back).
 *
 * GET ?action=write-test-set-type&contact_id=N&type_id=N
 *   -> Same PATCH-then-PUT test, setting `types` to [{id:type_id}] (use
 *      an id from contacts-admin-probe.php's probe-contact-types list,
 *      e.g. 1 "Approver").
 *
 * GET ?action=write-test-rename&contact_id=N&first=...&last=...
 *   -> Same PATCH-then-PUT test (two ops in one PATCH), setting
 *      firstName/lastName.
 *
 * GET ?action=write-test-update-communication&contact_id=N&comm_id=N&value=...
 *   -> THE ONE NEVER-ATTEMPTED SHAPE: edits an EXISTING communication
 *      item's value (e.g. the phone or email created in step 1) via
 *      PATCH-then-PUT against /company/contacts/{contact_id}/
 *      communications/{comm_id}. If this fails outright, editing phone/
 *      email in the real feature will need to delete-and-recreate the
 *      item instead -- this test exists to find out before the real UI
 *      assumes either way.
 *
 * DELETE THIS FILE (and contacts-admin-probe.php) once the real Contact
 * field shapes AND write behavior are confirmed and the actual Account
 * Contacts popover is built against them.
 */

declare(strict_types=1);

require_once __DIR__ . '/_util.php';
require_once __DIR__ . '/connectwise.php';
require_once __DIR__ . '/db.php';

$pdo = relationships_db();
relationships_require_login($pdo);

$action = $_GET['action'] ?? '';

if ($action === 'write-test-create-contact') {
    $companyId = (int) ($_GET['company_id'] ?? 0);
    $confirm = (string) ($_GET['confirm'] ?? '');
    if ($companyId <= 0) {
        relationships_respond(400, ['ok' => false, 'error' => 'company_id is required, e.g. ?action=write-test-create-contact&company_id=6539&confirm=yes-create-test-contact']);
    }
    if ($confirm !== 'yes-create-test-contact') {
        relationships_respond(400, ['ok' => false, 'error' => 'This creates a real ConnectWise contact. Add &confirm=yes-create-test-contact to proceed.']);
    }

    $stamp = date('Y-m-d H:i:s');
    $contact = relationships_cw_request('/company/contacts', [], 'POST', [
        'firstName' => 'CLAUDE-WRITE-TEST',
        'lastName' => 'DeleteMe-' . date('YmdHis'),
        'company' => ['id' => $companyId],
        'title' => 'AUTOMATED WRITE TEST (' . $stamp . ') - safe to delete, see claude/relationships-account-contacts.md',
        'types' => [['id' => 3]], // "End User", confirmed
    ]);
    $contactId = $contact['id'] ?? null;
    if (!is_int($contactId)) {
        relationships_respond(500, ['ok' => false, 'error' => 'Contact create did not return an id.', 'raw' => $contact]);
    }

    $commResults = [];
    try {
        $commResults['phone'] = relationships_cw_request('/company/contacts/' . $contactId . '/communications', [], 'POST', [
            'type' => ['id' => 2], // "Direct", confirmed
            'value' => '5555550100',
            'communicationType' => 'Phone',
            'defaultFlag' => true,
        ]);
    } catch (Throwable $e) {
        $commResults['phone_error'] = $e->getMessage();
    }
    try {
        $commResults['email'] = relationships_cw_request('/company/contacts/' . $contactId . '/communications', [], 'POST', [
            'type' => ['id' => 1], // "Email", confirmed
            'value' => 'claude-write-test+' . $contactId . '@example.invalid',
            'communicationType' => 'Email',
            'defaultFlag' => true,
        ]);
    } catch (Throwable $e) {
        $commResults['email_error'] = $e->getMessage();
    }

    relationships_respond(200, [
        'ok' => true,
        'contact' => $contact,
        'contact_id' => $contactId,
        'phone_comm_id' => $commResults['phone']['id'] ?? null,
        'email_comm_id' => $commResults['email']['id'] ?? null,
        'communications' => $commResults,
        'next' => 'Use this contact_id (and the comm ids above) in the other write-test actions below.',
    ]);
}

if ($action === 'write-test-toggle-inactive') {
    $contactId = (int) ($_GET['contact_id'] ?? 0);
    if ($contactId <= 0) {
        relationships_respond(400, ['ok' => false, 'error' => 'contact_id is required.']);
    }
    $path = '/company/contacts/' . $contactId;
    $current = relationships_cw_request($path, []);
    $before = (bool) ($current['inactiveFlag'] ?? false);
    $after = !$before;

    $out = relationships_cw_patch_then_put(
        $path,
        [['op' => 'replace', 'path' => '/inactiveFlag', 'value' => $after]],
        ['inactiveFlag' => $after]
    );
    relationships_respond(200, ['ok' => true, 'before' => $before, 'after' => $after, 'write' => $out]);
}

if ($action === 'write-test-set-type') {
    $contactId = (int) ($_GET['contact_id'] ?? 0);
    $typeId = (int) ($_GET['type_id'] ?? 0);
    if ($contactId <= 0 || $typeId <= 0) {
        relationships_respond(400, ['ok' => false, 'error' => 'contact_id and type_id are both required.']);
    }
    $path = '/company/contacts/' . $contactId;
    $out = relationships_cw_patch_then_put(
        $path,
        [['op' => 'replace', 'path' => '/types', 'value' => [['id' => $typeId]]]],
        ['types' => [['id' => $typeId]]]
    );
    relationships_respond(200, ['ok' => true, 'write' => $out]);
}

if ($action === 'write-test-rename') {
    $contactId = (int) ($_GET['contact_id'] ?? 0);
    $first = (string) ($_GET['first'] ?? '');
    $last = (string) ($_GET['last'] ?? '');
    if ($contactId <= 0 || $first === '' || $last === '') {
        relationships_respond(400, ['ok' => false, 'error' => 'contact_id, first, and last are all required.']);
    }
    $path = '/company/contacts/' . $contactId;
    $out = relationships_cw_patch_then_put(
        $path,
        [
            ['op' => 'replace', 'path' => '/firstName', 'value' => $first],
            ['op' => 'replace', 'path' => '/lastName', 'value' => $last],
        ],
        ['firstName' => $first, 'lastName' => $last]
    );
    relationships_respond(200, ['ok' => true, 'write' => $out]);
}

if ($action === 'write-test-update-communication') {
    $contactId = (int) ($_GET['contact_id'] ?? 0);
    $commId = (int) ($_GET['comm_id'] ?? 0);
    $value = (string) ($_GET['value'] ?? '');
    if ($contactId <= 0 || $commId <= 0 || $value === '') {
        relationships_respond(400, ['ok' => false, 'error' => 'contact_id, comm_id, and value are all required.']);
    }
    $path = '/company/contacts/' . $contactId . '/communications/' . $commId;
    $out = relationships_cw_patch_then_put(
        $path,
        [['op' => 'replace', 'path' => '/value', 'value' => $value]],
        ['value' => $value]
    );
    relationships_respond(200, ['ok' => true, 'write' => $out]);
}

relationships_respond(400, [
    'ok' => false,
    'error' => 'Unknown action.',
    'available' => [
        'write-test-create-contact&company_id=N&confirm=yes-create-test-contact',
        'write-test-toggle-inactive&contact_id=N',
        'write-test-set-type&contact_id=N&type_id=N',
        'write-test-rename&contact_id=N&first=...&last=...',
        'write-test-update-communication&contact_id=N&comm_id=N&value=...',
    ],
]);
