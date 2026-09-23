<?php
/**
 * relationships/api/contact-card.php
 *
 * Live ConnectWise address + primary-contact lookup for a single
 * customer's dashboard -- added 2026-09-23, per Michael: "pull in
 * address, primary contact name, email and phone number from ConnectWise
 * on each Customer in Relationships and show that data cleanly above
 * Outgrow Last Touch." Fetched live on every dashboard open (same
 * per-customer, on-demand pattern as activity.php's Service Tickets YTD
 * -- see that file's own header on why: this is only ever needed for the
 * ONE customer currently open on someone's screen, and unlike Monthly
 * Billing there is no existing nightly sync for address/phone at all to
 * read from instead -- see db.php's customers/contacts tables, neither
 * of which has ever carried these fields). Read-only; nothing here is
 * written back to SQLite or to ConnectWise.
 *
 * "Primary contact" reuses the exact same convention
 * connectwise-activity-create.php's relationships_checklist_first_contact_id()
 * already established for picking who gets a ConnectWise Activity on
 * checklist completion (confirmed again for this feature via
 * AskUserQuestion): the alphabetically-first-by-last-name synced contact
 * for that company. There's no actual "primary contact" flag anywhere in
 * this app's ConnectWise data -- this is a stand-in, same as it was there.
 * Contact name + email come straight from the local `contacts` table
 * (already synced nightly, see connectwise-contacts-sync-core.php); only
 * the phone number is fetched live, since it's never been synced.
 *
 * IMPORTANT / NOT LIVE-VERIFIED: this build environment has no network
 * path to connect.codebluetechnology.com (same standing constraint noted
 * throughout this integration -- see connectwise.php, connectwise-activity.php).
 * Two field-shape guesses here have never been checked against a real
 * ConnectWise response:
 *   - Company address fields are assumed to be addressLine1, addressLine2,
 *     city, state, zip on GET /company/companies/{id} -- ConnectWise's
 *     documented field names, but never sampled live like every other
 *     field this codebase reads (contrast connectwise-classify.php, which
 *     WAS built from real sampled data).
 *   - The phone communicationItems type name is guessed in
 *     relationships_cw_contacts_extract_phone() below -- see that
 *     function's own comment for the exact fallback order tried.
 * Both degrade gracefully rather than erroring the whole card: a missing
 * address field just isn't shown, and a failed/empty phone lookup leaves
 * the card's phone link off while name/email (locally synced, not part of
 * this guess) still show. Michael should check a real customer's card
 * against ConnectWise after deploy -- if the address or phone comes back
 * wrong or missing, tell me what the real field/type name is and this
 * can be corrected in one place.
 *
 * GET /relationships/api/contact-card.php?action=get&customer_id=123
 *   -> { ok: true, available: false }
 *      (mock/unsynced customer -- no real ConnectWise company to look up)
 *   -> { ok: true, available: true,
 *        address: { line1, line2, city, state, zip } | null,
 *        contact: { name, email, phone } | null }
 *      (address is null when ConnectWise returned nothing usable; contact
 *      is null when this customer has no synced contacts at all yet --
 *      contact.phone can itself be null even when contact isn't, when the
 *      live phone lookup came back empty or failed)
 *   -> 502 { ok: false, error }  (the Company address fetch itself failed
 *      -- a real ConnectWise/transport problem, not just a missing field)
 */

declare(strict_types=1);

require_once __DIR__ . '/_util.php';
require_once __DIR__ . '/territory-access.php';
require_once __DIR__ . '/connectwise.php';

$pdo = relationships_db();
relationships_require_login($pdo);
$allowedTerritories = relationships_allowed_territories($pdo);

$action = $_GET['action'] ?? '';

/**
 * Same "MOCK-%" convention as activity.php's relationships_activity_cw_id()
 * / outgrow.php's relationships_outgrow_cw_id() -- duplicated rather than
 * shared, matching this codebase's existing convention of a small
 * per-file copy of this exact lookup.
 */
function relationships_contact_card_cw_id(PDO $pdo, int $customerId): ?string
{
    $stmt = $pdo->prepare('SELECT connectwise_id FROM customers WHERE id = :id');
    $stmt->execute([':id' => $customerId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $cwId = $row['connectwise_id'] ?? null;
    return ($cwId === null || $cwId === '' || str_starts_with((string) $cwId, 'MOCK-')) ? null : (string) $cwId;
}

/**
 * The "first synced contact, alphabetical by last name" convention --
 * same query connectwise-activity-create.php's
 * relationships_checklist_first_contact_id() uses, but returns the full
 * local row (name + email + the ConnectWise contact id) since the card
 * needs more than just the id.
 */
function relationships_contact_card_primary_contact(PDO $pdo, int $customerId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT connectwise_contact_id, first_name, last_name, email FROM contacts
         WHERE customer_id = :cid
         ORDER BY last_name COLLATE NOCASE ASC, first_name COLLATE NOCASE ASC LIMIT 1'
    );
    $stmt->execute([':cid' => $customerId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row !== false ? $row : null;
}

/**
 * Mirrors connectwise-contacts-sync-core.php's
 * relationships_cw_contacts_extract_email() -- same communicationItems
 * shape (each item: { type: { name }, value }), different type-name
 * match. UNVERIFIED GUESS -- see this file's header. Tries, in priority
 * order, any type name containing "direct", then "mobile"/"cell", then
 * "phone"; if none of those match, falls back to the first item that
 * isn't obviously an email or fax line, so a differently-named phone
 * type still surfaces something rather than nothing.
 */
function relationships_cw_contacts_extract_phone(array $contact): ?string
{
    $items = $contact['communicationItems'] ?? [];
    if (!is_array($items)) {
        return null;
    }

    foreach (['direct', 'mobile', 'cell', 'phone'] as $needle) {
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $typeName = is_array($item['type'] ?? null) ? (string) ($item['type']['name'] ?? '') : (string) ($item['type'] ?? '');
            if (stripos($typeName, $needle) !== false && !empty($item['value'])) {
                return (string) $item['value'];
            }
        }
    }

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $typeName = is_array($item['type'] ?? null) ? (string) ($item['type']['name'] ?? '') : (string) ($item['type'] ?? '');
        if (stripos($typeName, 'email') === false && stripos($typeName, 'fax') === false && !empty($item['value'])) {
            return (string) $item['value'];
        }
    }

    return null;
}

if ($action === 'get') {
    $customerId = (int) ($_GET['customer_id'] ?? 0);
    if ($customerId <= 0) {
        relationships_respond(400, ['ok' => false, 'error' => 'Missing customer_id.']);
    }
    relationships_require_territory_scope($allowedTerritories, relationships_customer_territory($pdo, $customerId));

    $cwId = relationships_contact_card_cw_id($pdo, $customerId);
    if ($cwId === null) {
        relationships_respond(200, ['ok' => true, 'available' => false]);
    }

    $address = null;
    try {
        $company = relationships_cw_request('/company/companies/' . rawurlencode($cwId), [
            'fields' => 'addressLine1,addressLine2,city,state,zip',
        ]);
        $line1 = trim((string) ($company['addressLine1'] ?? ''));
        $line2 = trim((string) ($company['addressLine2'] ?? ''));
        $city = trim((string) ($company['city'] ?? ''));
        $state = trim((string) ($company['state'] ?? ''));
        $zip = trim((string) ($company['zip'] ?? ''));
        if ($line1 !== '' || $city !== '') {
            $address = [
                'line1' => $line1 !== '' ? $line1 : null,
                'line2' => $line2 !== '' ? $line2 : null,
                'city' => $city !== '' ? $city : null,
                'state' => $state !== '' ? $state : null,
                'zip' => $zip !== '' ? $zip : null,
            ];
        }
    } catch (RelationshipsConnectWiseError $e) {
        relationships_respond(502, ['ok' => false, 'error' => $e->getMessage()]);
    }

    $contact = null;
    $localContact = relationships_contact_card_primary_contact($pdo, $customerId);
    if ($localContact !== null) {
        $name = trim($localContact['first_name'] . ' ' . $localContact['last_name']);
        $phone = null;
        if (!empty($localContact['connectwise_contact_id'])) {
            try {
                $cwContact = relationships_cw_request('/company/contacts/' . rawurlencode((string) $localContact['connectwise_contact_id']), [
                    'fields' => 'communicationItems',
                ]);
                $phone = relationships_cw_contacts_extract_phone($cwContact);
            } catch (RelationshipsConnectWiseError $e) {
                // Graceful degradation, per this integration's standing
                // "save locally, log the ConnectWise failure" practice
                // (here: "show what's local, log the live-fetch failure")
                // -- name/email already came from the local sync, so
                // still show those; just no tappable phone this time.
                error_log('relationships contact-card: phone fetch failed for contact ' . $localContact['connectwise_contact_id'] . ': ' . $e->getMessage());
            }
        }
        $contact = [
            'name' => $name !== '' ? $name : null,
            'email' => $localContact['email'] !== null && $localContact['email'] !== '' ? $localContact['email'] : null,
            'phone' => $phone,
        ];
    }

    relationships_respond(200, ['ok' => true, 'available' => true, 'address' => $address, 'contact' => $contact]);
}

relationships_respond(400, ['ok' => false, 'error' => 'Unknown action.']);
