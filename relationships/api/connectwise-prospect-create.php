<?php
/**
 * relationships/api/connectwise-prospect-create.php
 *
 * ConnectWise WRITES for Prospecting's "Claim as Prospect" action (added
 * 2026-09-23, per Michael). Creates the Company (Status = "Prospect"), its
 * primary Contact (with email + phone), and assigns the claiming rep as
 * Sales Rep + Account Manager on the Company's Team.
 *
 * Modeled on the create functions already proven against this same
 * ConnectWise instance in the rate sheet app (ratesheet/api/public.php:
 * ratesheet_cw_create_company()/ratesheet_cw_create_contact() and its
 * "resolve a reference id by live name search" pattern for Territory and
 * Company Status) -- kept as this app's own copy rather than a cross-app
 * include, per this codebase's self-contained-sub-app convention.
 *
 * WHAT IS CONFIRMED vs GUESSED (nothing here has been exercised from this
 * build environment -- no ConnectWise credentials -- so Michael should
 * inspect the first real claim in ConnectWise):
 *   confirmed elsewhere in this codebase: Company country id 1, site name
 *   "Main", Territory House Accounts id 45, Contact type "End User" id 3,
 *   Email communication type id 1, team roles Sales Rep id 3 / Account
 *   Manager id 1, and the shared PUT-with-retry helper for Company writes.
 *   NOT confirmed: the Company Status named "Prospect" (resolved live by
 *   name; the claim refuses to run if it doesn't exist), the rep's
 *   ConnectWise Member (matched by email, then name, from /system/members),
 *   the phone communication type (resolved live), and the Company
 *   phoneNumber/website fields (set with the auto-stripping PUT so a
 *   wrong/unsupported field can't fail the claim).
 */

declare(strict_types=1);

require_once __DIR__ . '/connectwise.php';

/** ConnectWise Territory id for "House Accounts" -- confirmed (register/api/customers.php). */
const RELATIONSHIPS_CW_HOUSE_ACCOUNTS_TERRITORY_ID = 45;

function relationships_cw_condition_escape_value(string $value): string
{
    return str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
}

/**
 * Company identifier: letters/digits/spaces only, collapsed; when longer
 * than $maxLength the spaces are dropped and it's truncated (same rule the
 * rate sheet uses -- proven against this instance).
 */
function relationships_cw_sanitize_identifier(string $name, int $maxLength = 41): string
{
    $clean = preg_replace('/[^A-Za-z0-9 ]+/', '', $name) ?? '';
    $clean = trim(preg_replace('/\s+/', ' ', $clean) ?? '');
    if ($clean === '') {
        $clean = 'Prospect';
    }
    if (mb_strlen($clean) <= $maxLength) {
        return $clean;
    }
    return mb_substr(str_replace(' ', '', $clean), 0, $maxLength);
}

/** First row's id from a name-conditioned list request, or null. */
function relationships_cw_find_id_by_name(string $path, string $name, bool $like = false): ?int
{
    $escaped = relationships_cw_condition_escape_value($name);
    $condition = $like ? 'name like "%' . $escaped . '%"' : 'name = "' . $escaped . '"';
    $rows = relationships_cw_request($path, ['conditions' => $condition, 'fields' => 'id,name', 'pageSize' => '5']);
    foreach ($rows as $row) {
        if (is_array($row) && isset($row['id']) && is_int($row['id'])) {
            return (int) $row['id'];
        }
    }
    return null;
}

/** ConnectWise Company Status id for a status literally named $name (e.g. "Prospect"), or null. */
function relationships_cw_resolve_company_status(string $name): ?int
{
    return relationships_cw_find_id_by_name('/company/statuses', $name);
}

/**
 * The signed-in rep's ConnectWise Member id. Matches any e-mail-looking
 * field on the member against the rep's login email (case-insensitive),
 * then falls back to an exact first+last name match. Fetches the member
 * list unfiltered (it's small) and matches in PHP rather than trusting an
 * unverified server-side condition on an email field -- the same lesson
 * this integration learned the hard way with related-field conditions.
 */
function relationships_cw_resolve_member_id(string $email, string $fullName): ?int
{
    $members = relationships_cw_request('/system/members', ['pageSize' => '1000']);
    $wantEmail = strtolower(trim($email));
    $wantName = strtolower(trim(preg_replace('/\s+/', ' ', $fullName) ?? $fullName));
    $byName = null;
    foreach ($members as $m) {
        if (!is_array($m) || !isset($m['id']) || !is_int($m['id'])) {
            continue;
        }
        if (!empty($m['inactiveFlag'])) {
            continue;
        }
        foreach ($m as $key => $value) {
            if (is_string($value) && stripos((string) $key, 'mail') !== false && $wantEmail !== '' && strtolower(trim($value)) === $wantEmail) {
                return (int) $m['id'];
            }
        }
        $memberName = strtolower(trim(((string) ($m['firstName'] ?? '')) . ' ' . ((string) ($m['lastName'] ?? ''))));
        if ($byName === null && $wantName !== '' && $memberName === $wantName) {
            $byName = (int) $m['id'];
        }
    }
    return $byName;
}

/**
 * [territory id, territory name] for a claiming rep: the first territory
 * they're assigned in crc_territory_reps (see territory-access.php) if any,
 * else a live search for a territory named after them (how the rate sheet
 * finds "Moe Okeilli's Account" etc.), else House Accounts.
 */
function relationships_cw_resolve_rep_territory(PDO $pdo, string $email, string $fullName): array
{
    require_once __DIR__ . '/territory-access.php';

    $candidates = [];
    $allowed = relationships_allowed_territories_for_email($pdo, $email);
    if (is_array($allowed)) {
        foreach ($allowed as $t) {
            $candidates[] = ['name' => (string) $t, 'like' => false];
        }
    }
    if (trim($fullName) !== '') {
        $candidates[] = ['name' => trim($fullName), 'like' => true];
    }
    foreach ($candidates as $cand) {
        try {
            $escaped = relationships_cw_condition_escape_value($cand['name']);
            $condition = $cand['like'] ? 'name like "%' . $escaped . '%"' : 'name = "' . $escaped . '"';
            $rows = relationships_cw_request('/company/territories', ['conditions' => $condition, 'fields' => 'id,name', 'pageSize' => '3']);
            if (isset($rows[0]['id']) && is_int($rows[0]['id'])) {
                return [(int) $rows[0]['id'], (string) ($rows[0]['name'] ?? $cand['name'])];
            }
        } catch (Throwable $e) {
            error_log('[relationships/prospecting] territory lookup failed for "' . $cand['name'] . '": ' . $e->getMessage());
        }
    }

    $name = 'House Accounts';
    try {
        $row = relationships_cw_request('/company/territories/' . RELATIONSHIPS_CW_HOUSE_ACCOUNTS_TERRITORY_ID, ['fields' => 'id,name']);
        if (isset($row['name']) && is_string($row['name']) && $row['name'] !== '') {
            $name = $row['name'];
        }
    } catch (Throwable $e) {
        // keep the literal fallback name
    }
    return [RELATIONSHIPS_CW_HOUSE_ACCOUNTS_TERRITORY_ID, $name];
}

/**
 * Creates the Company. $c keys: name, address_line1, city, state, zip.
 * A duplicate identifier (ConnectWise requires them unique) is retried with
 * a numeric suffix. Returns the created Company array (with 'id').
 */
function relationships_cw_create_prospect_company(array $c, int $statusId, int $territoryId): array
{
    $today = gmdate('Y-m-d\T00:00:00\Z');
    $baseId = relationships_cw_sanitize_identifier((string) $c['name']);

    $lastError = null;
    for ($attempt = 0; $attempt < 4; $attempt++) {
        $identifier = $attempt === 0 ? $baseId : mb_substr($baseId, 0, 38) . ' ' . ($attempt + 1);
        $body = [
            'identifier' => $identifier,
            'name' => (string) $c['name'],
            'country' => ['id' => 1], // United States, confirmed
            'status' => ['id' => $statusId], // "Prospect", resolved by name
            'site' => ['name' => 'Main'], // confirmed required
            'territory' => ['id' => $territoryId],
            'dateAcquired' => $today,
        ];
        if (($c['address_line1'] ?? '') !== '') $body['addressLine1'] = (string) $c['address_line1'];
        if (($c['city'] ?? '') !== '') $body['city'] = (string) $c['city'];
        if (($c['state'] ?? '') !== '') $body['state'] = (string) $c['state'];
        if (($c['zip'] ?? '') !== '') $body['zip'] = (string) $c['zip'];

        try {
            $company = relationships_cw_request('/company/companies', [], 'POST', $body);
        } catch (RelationshipsConnectWiseError $e) {
            $lastError = $e;
            // Only an identifier collision is worth another attempt.
            if (stripos($e->getMessage(), 'identifier') !== false) {
                continue;
            }
            throw $e;
        }
        if (!isset($company['id']) || !is_int($company['id'])) {
            throw new RelationshipsConnectWiseError('ConnectWise did not return a new company id.');
        }
        return $company;
    }
    throw $lastError ?? new RelationshipsConnectWiseError('Could not create the company.');
}

/** Sets phone/website on the new Company via the auto-stripping PUT (best-effort; caller logs failures). */
function relationships_cw_set_prospect_company_extras(int $companyId, ?string $phone, ?string $website): void
{
    $fields = [];
    if ($phone !== null && $phone !== '') $fields['phoneNumber'] = $phone;
    if ($website !== null && $website !== '') $fields['website'] = $website;
    if ($fields === []) {
        return;
    }
    relationships_cw_put_company_with_retry((string) $companyId, $fields);
}

/** ConnectWise communication type id for a phone number (Direct preferred), or null. */
function relationships_cw_resolve_phone_comm_type(): ?int
{
    $rows = relationships_cw_request('/company/communicationTypes', ['pageSize' => '100']);
    $fallback = null;
    foreach ($rows as $r) {
        if (!is_array($r) || !isset($r['id']) || !is_int($r['id']) || empty($r['phoneFlag'])) {
            continue;
        }
        $desc = strtolower((string) ($r['description'] ?? $r['name'] ?? ''));
        if (str_contains($desc, 'direct')) {
            return (int) $r['id'];
        }
        $fallback ??= (int) $r['id'];
    }
    return $fallback;
}

/**
 * Creates the primary Contact on the Company and adds its email (+ phone)
 * communication items. Returns [contact array, warnings[]] -- email/phone
 * item failures are warnings, not errors, since the Contact itself exists.
 */
function relationships_cw_create_prospect_contact(int $companyId, string $first, string $last, ?string $title, string $email, ?string $phone): array
{
    $contact = relationships_cw_request('/company/contacts', [], 'POST', [
        'firstName' => $first,
        'lastName' => $last,
        'company' => ['id' => $companyId],
        'title' => ($title !== null && $title !== '') ? $title : 'Prospect Contact',
        'types' => [['id' => 3]], // "End User", confirmed
    ]);
    $contactId = $contact['id'] ?? null;
    if (!is_int($contactId)) {
        throw new RelationshipsConnectWiseError('ConnectWise did not return a new contact id.');
    }

    $warnings = [];
    try {
        relationships_cw_request('/company/contacts/' . $contactId . '/communications', [], 'POST', [
            'type' => ['id' => 1], // "Email", confirmed
            'value' => $email,
            'communicationType' => 'Email',
            'defaultFlag' => true,
        ]);
    } catch (Throwable $e) {
        error_log('[relationships/prospecting] contact email failed for contact ' . $contactId . ': ' . $e->getMessage());
        $warnings[] = 'The contact’s email could not be added in ConnectWise — please add it there.';
    }
    if ($phone !== null && $phone !== '') {
        try {
            $typeId = relationships_cw_resolve_phone_comm_type();
            if ($typeId === null) {
                throw new RelationshipsConnectWiseError('No phone communication type found.');
            }
            relationships_cw_request('/company/contacts/' . $contactId . '/communications', [], 'POST', [
                'type' => ['id' => $typeId],
                'value' => $phone,
                'communicationType' => 'Phone',
                'defaultFlag' => true,
            ]);
        } catch (Throwable $e) {
            error_log('[relationships/prospecting] contact phone failed for contact ' . $contactId . ': ' . $e->getMessage());
            $warnings[] = 'The contact’s phone number could not be added in ConnectWise — please add it there.';
        }
    }
    return [$contact, $warnings];
}

/** Assigns $memberId as Sales Rep + Account Manager on the Company's Team. Returns warnings[]. */
function relationships_cw_assign_prospect_team(int $companyId, int $memberId): array
{
    $warnings = [];
    foreach ([
        ['teamRole' => ['id' => 3], 'salesFlag' => true],          // Sales Rep, confirmed
        ['teamRole' => ['id' => 1], 'accountManagerFlag' => true], // Account Manager, confirmed
    ] as $row) {
        try {
            relationships_cw_request('/company/companies/' . $companyId . '/teams', [], 'POST', $row + ['member' => ['id' => $memberId]]);
        } catch (Throwable $e) {
            error_log('[relationships/prospecting] team row failed for company ' . $companyId . ': ' . $e->getMessage());
            $warnings[] = 'Team assignment could not be fully added automatically — please check the company’s Team in ConnectWise.';
            break;
        }
    }
    return $warnings;
}
