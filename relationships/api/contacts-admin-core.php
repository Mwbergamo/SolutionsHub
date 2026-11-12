<?php
declare(strict_types=1);

/**
 * relationships/api/contacts-admin-core.php
 *
 * Added 2026-11-12, extracted out of contacts-admin.php so the Marketing
 * document hub (marketing/api/send.php -- "search for a company, select a
 * contact's email, attach a file, send it") can get a company's live
 * ConnectWise contact list (with email) WITHOUT duplicating the
 * extraction logic contacts-admin.php already got right (primary
 * phone/email matched on communicationType, not the unreliable top-level
 * defaultPhoneNbr -- see that file's header for the confirmed facts this
 * is built on) and without re-implementing a second territory-scope
 * check. Same pattern as gauges-core.php and documents-core.php: this
 * file defines FUNCTIONS ONLY, no top-level execution, so requiring it
 * never runs contacts-admin.php's own action dispatch.
 *
 * contacts-admin.php's own `list` action is now a thin wrapper around
 * relationships_contacts_admin_list_for_customer() below -- no behavior
 * change, same response shape, same errors.
 */

require_once __DIR__ . '/_util.php';
require_once __DIR__ . '/territory-access.php';
require_once __DIR__ . '/connectwise.php';

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

/**
 * Everything contacts-admin.php's `?action=list` endpoint does, as a
 * reusable function: resolve the customer, enforce territory scope,
 * fetch + shape every ConnectWise contact (active and inactive) with
 * primary phone/email, plus the contact-type list. Responds (and exits)
 * on any error, exactly as the inline code it replaces did -- callers
 * don't need their own error handling.
 *
 * @return array{customer: array{id:int,name:string}, contact_types: array, contacts: array}
 */
function relationships_contacts_admin_list_for_customer(PDO $pdo, ?array $allowedTerritories, int $customerId): array
{
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

    return [
        'customer' => ['id' => (int) $customer['id'], 'name' => $customer['name']],
        'contact_types' => $contactTypes,
        'contacts' => $contacts,
    ];
}
