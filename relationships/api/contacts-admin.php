<?php
/**
 * relationships/api/contacts-admin.php
 *
 * REAL Account Contacts feature, built 2026-10-09 against the facts
 * confirmed by contacts-admin-probe.php + contacts-admin-write-test.php
 * (see claude/relationships-account-contacts.md for the full research
 * trail -- both of those files are now safe to delete once this is
 * confirmed working live).
 *
 * Michael's request: a contact count on each customer's dashboard that's
 * clickable, opening a list of EVERY contact (active and inactive) with
 * status, first/last name, email, phone, and ConnectWise Contact Type,
 * each editable with changes pushed live to ConnectWise -- including
 * marking a contact Inactive live in a customer meeting -- plus creating
 * new contacts to ConnectWise's standards. Scope narrowed via
 * AskUserQuestion (2026-10-09): one primary phone + one primary email per
 * contact (not ConnectWise's full communicationItems list), and one
 * Contact Type per contact (not the technically-multi-valued `types`
 * array).
 *
 * Confirmed facts this is built on:
 *   - Primary phone/email come from communicationItems, matched on
 *     `communicationType === "Phone"/"Email"` directly (not type.name
 *     substring guessing, unlike this codebase's two older extractors in
 *     connectwise-contacts-sync-core.php / contact-card.php), preferring
 *     the item with defaultFlag true.
 *   - A contact's top-level defaultPhoneNbr is NOT used -- confirmed it
 *     can silently reflect the COMPANY's shared switchboard number
 *     instead of a personal line when the contact has no Phone
 *     communicationItem of its own.
 *   - Contact PATCH (the contact's own fields AND an existing
 *     communication item's value) works cleanly on this ConnectWise
 *     instance -- all 5 write-test actions succeeded via plain PATCH, no
 *     PUT fallback ever needed. relationships_cw_patch_then_put() is
 *     still used as a safety net (mirrors Company, where PATCH is
 *     confirmed broken and PUT-with-strip-retry is required), but in
 *     practice every call here is expected to take the PATCH path.
 *   - /company/contacts/types' LIST resource names the label field
 *     "description" (confirmed via register/api/customers.php's own
 *     research trail), not "name" -- "name" is only how it appears
 *     embedded on a Contact's own `types` array. Both get normalized to
 *     {id, name} here.
 *
 * NOT yet confirmed: whether ConnectWise requires a `title` on Contact
 * create. The only two proven create-contact runs in this codebase
 * (register_cw_create_contact(), and this feature's own write-test) both
 * sent a non-empty title; omitting it entirely (done here, since title
 * isn't one of Michael's requested fields) has never been tried. If a
 * real create ever 400s citing "title", the ConnectWise error text is
 * surfaced verbatim to the UI rather than failing silently -- add a
 * default title here if that happens.
 *
 * Every write here targets a real customer's real ConnectWise contact --
 * unlike contacts-admin-write-test.php, there is no disposable-test-
 * contact safety net. Authorization is the same depth as the rest of
 * this app: a rep must already have territory access to the customer
 * (relationships_require_territory_scope) to list/update/create its
 * contacts; an update/create call carries that customer's local id
 * precisely so this check can run, same as contact-card.php.
 *
 * GET ?action=list&customer_id=N
 *   -> { ok, customer: {id, name}, contact_types: [{id, name}],
 *        contacts: [{ id, first_name, last_name, inactive, type_id,
 *                      type_name, phone, phone_comm_id, email,
 *                      email_comm_id }, ...] (sorted last name, first name) }
 *
 * POST ?action=update  body: { customer_id, contact_id,
 *   first_name?, last_name?, type_id?, inactive?, phone?, phone_comm_id?,
 *   email?, email_comm_id? }
 *   -> only the fields present in the body are changed. phone/email: if a
 *      *_comm_id is given, the existing communication item is PATCHed in
 *      place; otherwise (no comm id, non-empty value) a new one is
 *      created the same way register_cw_create_contact() does (type id 2
 *      "Direct" for phone, type id 1 "Email" for email, defaultFlag
 *      true). An empty phone/email is left untouched -- this endpoint
 *      never clears or deletes a communication item.
 *   -> { ok: true, contact: {...} }  (freshly re-fetched after the write)
 *
 * POST ?action=create  body: { customer_id, first_name, last_name,
 *   type_id?, phone?, email? }
 *   -> type_id defaults to 3 ("End User", ConnectWise's own default).
 *      Same create-then-attach-communications shape as
 *      register_cw_create_contact().
 *   -> { ok: true, contact: {...} }
 */

declare(strict_types=1);

require_once __DIR__ . '/_util.php';
require_once __DIR__ . '/territory-access.php';
require_once __DIR__ . '/connectwise.php';
require_once __DIR__ . '/db.php';

$pdo = relationships_db();
relationships_require_login($pdo);
$allowedTerritories = relationships_allowed_territories($pdo);

$action = $_GET['action'] ?? '';

function relationships_contacts_admin_resolve_customer(PDO $pdo, int $customerId): array
{
    $stmt = $pdo->prepare('SELECT id, name, connectwise_id, territory_name FROM customers WHERE id = :id');
    $stmt->execute([':id' => $customerId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        relationships_respond(404, ['ok' => false, 'error' => 'Customer not found.']);
    }
    return $row;
}

function relationships_contacts_admin_cw_id(array $customer): string
{
    $cwId = $customer['connectwise_id'] ?? null;
    if ($cwId === null || $cwId === '' || str_starts_with((string) $cwId, 'MOCK-')) {
        relationships_respond(400, ['ok' => false, 'error' => 'This customer has no real ConnectWise company to look up contacts for.']);
    }
    return (string) $cwId;
}

/**
 * Picks the primary value + its communication item id for one
 * communicationType ("Phone" or "Email") out of a Contact's
 * communicationItems -- matches on communicationType directly (confirmed
 * live 2026-10-09), preferring the item with defaultFlag true; falls
 * back to the first matching item with a non-empty value if none is
 * flagged default.
 */
function relationships_contacts_admin_primary(array $communicationItems, string $wantType): array
{
    $fallback = null;
    foreach ($communicationItems as $item) {
        if (!is_array($item)) {
            continue;
        }
        if ((string) ($item['communicationType'] ?? '') !== $wantType) {
            continue;
        }
        $value = trim((string) ($item['value'] ?? ''));
        if ($value === '') {
            continue;
        }
        $id = isset($item['id']) ? (int) $item['id'] : null;
        if (!empty($item['defaultFlag'])) {
            return ['value' => $value, 'comm_id' => $id];
        }
        if ($fallback === null) {
            $fallback = ['value' => $value, 'comm_id' => $id];
        }
    }
    return $fallback ?? ['value' => null, 'comm_id' => null];
}

/** Shapes one raw ConnectWise Contact record for the frontend. */
function relationships_contacts_admin_shape(array $c): array
{
    $items = is_array($c['communicationItems'] ?? null) ? $c['communicationItems'] : [];
    $phone = relationships_contacts_admin_primary($items, 'Phone');
    $email = relationships_contacts_admin_primary($items, 'Email');
    $types = is_array($c['types'] ?? null) ? $c['types'] : [];
    $firstType = (count($types) > 0 && is_array($types[0])) ? $types[0] : null;

    return [
        'id' => (int) ($c['id'] ?? 0),
        'first_name' => (string) ($c['firstName'] ?? ''),
        'last_name' => (string) ($c['lastName'] ?? ''),
        'inactive' => (bool) ($c['inactiveFlag'] ?? false),
        'type_id' => $firstType !== null ? (int) ($firstType['id'] ?? 0) : null,
        'type_name' => $firstType !== null ? (string) ($firstType['name'] ?? '') : null,
        'phone' => $phone['value'],
        'phone_comm_id' => $phone['comm_id'],
        'email' => $email['value'],
        'email_comm_id' => $email['comm_id'],
    ];
}

/** Fetches + shapes one contact by id, fresh from ConnectWise. */
function relationships_contacts_admin_fetch_one(int $contactId): array
{
    $raw = relationships_cw_request('/company/contacts/' . $contactId);
    return relationships_contacts_admin_shape($raw);
}

if ($action === 'list') {
    $customerId = (int) ($_GET['customer_id'] ?? 0);
    if ($customerId <= 0) {
        relationships_respond(400, ['ok' => false, 'error' => 'Missing customer_id.']);
    }
    $customer = relationships_contacts_admin_resolve_customer($pdo, $customerId);
    relationships_require_territory_scope($allowedTerritories, $customer['territory_name']);
    $cwId = relationships_contacts_admin_cw_id($customer);

    try {
        $raw = relationships_cw_request('/company/contacts', [
            'conditions' => 'company/id=' . (int) $cwId,
            'pageSize' => '200',
        ]);
        $typesRaw = relationships_cw_request('/company/contacts/types', ['pageSize' => '100']);
    } catch (RelationshipsConnectWiseError $e) {
        relationships_respond(502, ['ok' => false, 'error' => $e->getMessage()]);
    }

    $contacts = array_map('relationships_contacts_admin_shape', $raw);
    usort($contacts, static function (array $a, array $b): int {
        return strcasecmp($a['last_name'] . ' ' . $a['first_name'], $b['last_name'] . ' ' . $b['first_name']);
    });

    // /company/contacts/types' LIST resource names this field "description"
    // (not "name" -- see register/api/customers.php's header) -- normalize
    // to {id, name} either way.
    $contactTypes = array_map(static function (array $t): array {
        return ['id' => (int) ($t['id'] ?? 0), 'name' => (string) ($t['description'] ?? '')];
    }, $typesRaw);

    relationships_respond(200, [
        'ok' => true,
        'customer' => ['id' => (int) $customer['id'], 'name' => $customer['name']],
        'contact_types' => $contactTypes,
        'contacts' => $contacts,
    ]);
}

if ($action === 'update') {
    $body = relationships_read_json_body();
    $customerId = (int) ($body['customer_id'] ?? 0);
    $contactId = (int) ($body['contact_id'] ?? 0);
    if ($customerId <= 0 || $contactId <= 0) {
        relationships_respond(400, ['ok' => false, 'error' => 'Missing customer_id or contact_id.']);
    }
    $customer = relationships_contacts_admin_resolve_customer($pdo, $customerId);
    relationships_require_territory_scope($allowedTerritories, $customer['territory_name']);

    $path = '/company/contacts/' . $contactId;
    $ops = [];
    $fallback = [];

    if (array_key_exists('first_name', $body)) {
        $v = trim((string) $body['first_name']);
        $ops[] = ['op' => 'replace', 'path' => '/firstName', 'value' => $v];
        $fallback['firstName'] = $v;
    }
    if (array_key_exists('last_name', $body)) {
        $v = trim((string) $body['last_name']);
        $ops[] = ['op' => 'replace', 'path' => '/lastName', 'value' => $v];
        $fallback['lastName'] = $v;
    }
    if (array_key_exists('type_id', $body) && (int) $body['type_id'] > 0) {
        $v = [['id' => (int) $body['type_id']]];
        $ops[] = ['op' => 'replace', 'path' => '/types', 'value' => $v];
        $fallback['types'] = $v;
    }
    if (array_key_exists('inactive', $body)) {
        $v = (bool) $body['inactive'];
        $ops[] = ['op' => 'replace', 'path' => '/inactiveFlag', 'value' => $v];
        $fallback['inactiveFlag'] = $v;
    }

    try {
        if ($ops !== []) {
            relationships_cw_patch_then_put($path, $ops, $fallback);
        }

        $phone = array_key_exists('phone', $body) ? trim((string) $body['phone']) : null;
        if ($phone !== null && $phone !== '') {
            $phoneCommId = (int) ($body['phone_comm_id'] ?? 0);
            if ($phoneCommId > 0) {
                relationships_cw_patch_then_put(
                    $path . '/communications/' . $phoneCommId,
                    [['op' => 'replace', 'path' => '/value', 'value' => $phone]],
                    ['value' => $phone]
                );
            } else {
                relationships_cw_request($path . '/communications', [], 'POST', [
                    'type' => ['id' => 2], // "Direct" -- confirmed, see register_cw_create_contact()
                    'value' => $phone,
                    'communicationType' => 'Phone',
                    'defaultFlag' => true,
                ]);
            }
        }

        $email = array_key_exists('email', $body) ? trim((string) $body['email']) : null;
        if ($email !== null && $email !== '') {
            $emailCommId = (int) ($body['email_comm_id'] ?? 0);
            if ($emailCommId > 0) {
                relationships_cw_patch_then_put(
                    $path . '/communications/' . $emailCommId,
                    [['op' => 'replace', 'path' => '/value', 'value' => $email]],
                    ['value' => $email]
                );
            } else {
                relationships_cw_request($path . '/communications', [], 'POST', [
                    'type' => ['id' => 1], // "Email" -- confirmed
                    'value' => $email,
                    'communicationType' => 'Email',
                    'defaultFlag' => true,
                ]);
            }
        }
    } catch (RelationshipsConnectWiseError $e) {
        relationships_respond(502, ['ok' => false, 'error' => $e->getMessage()]);
    }

    try {
        $fresh = relationships_contacts_admin_fetch_one($contactId);
    } catch (RelationshipsConnectWiseError $e) {
        relationships_respond(502, ['ok' => false, 'error' => 'Saved, but could not re-load the contact: ' . $e->getMessage()]);
    }
    relationships_respond(200, ['ok' => true, 'contact' => $fresh]);
}

if ($action === 'create') {
    $body = relationships_read_json_body();
    $customerId = (int) ($body['customer_id'] ?? 0);
    $firstName = trim((string) ($body['first_name'] ?? ''));
    $lastName = trim((string) ($body['last_name'] ?? ''));
    if ($customerId <= 0 || $firstName === '' || $lastName === '') {
        relationships_respond(400, ['ok' => false, 'error' => 'customer_id, first_name, and last_name are all required.']);
    }
    $customer = relationships_contacts_admin_resolve_customer($pdo, $customerId);
    relationships_require_territory_scope($allowedTerritories, $customer['territory_name']);
    $cwId = relationships_contacts_admin_cw_id($customer);

    $typeId = (int) ($body['type_id'] ?? 0);
    $payload = [
        'firstName' => $firstName,
        'lastName' => $lastName,
        'company' => ['id' => (int) $cwId],
        'types' => [['id' => $typeId > 0 ? $typeId : 3]], // default "End User", confirmed
    ];

    try {
        $created = relationships_cw_request('/company/contacts', [], 'POST', $payload);
        $contactId = (int) ($created['id'] ?? 0);
        if ($contactId <= 0) {
            relationships_respond(502, ['ok' => false, 'error' => 'ConnectWise did not return a new contact id.']);
        }

        $phone = trim((string) ($body['phone'] ?? ''));
        if ($phone !== '') {
            relationships_cw_request('/company/contacts/' . $contactId . '/communications', [], 'POST', [
                'type' => ['id' => 2],
                'value' => $phone,
                'communicationType' => 'Phone',
                'defaultFlag' => true,
            ]);
        }
        $email = trim((string) ($body['email'] ?? ''));
        if ($email !== '') {
            relationships_cw_request('/company/contacts/' . $contactId . '/communications', [], 'POST', [
                'type' => ['id' => 1],
                'value' => $email,
                'communicationType' => 'Email',
                'defaultFlag' => true,
            ]);
        }

        $fresh = relationships_contacts_admin_fetch_one($contactId);
    } catch (RelationshipsConnectWiseError $e) {
        relationships_respond(502, ['ok' => false, 'error' => $e->getMessage()]);
    }

    relationships_respond(200, ['ok' => true, 'contact' => $fresh]);
}

relationships_respond(400, ['ok' => false, 'error' => 'Unknown action.']);
