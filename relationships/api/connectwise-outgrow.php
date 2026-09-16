<?php
/**
 * relationships/api/connectwise-outgrow.php
 *
 * Reads and writes the "OutGrow Last Touch" Company custom field in
 * ConnectWise -- added 2026-09-15 per Michael, from a screenshot of the
 * Company screen showing it as a date field alongside several checkbox
 * custom fields (Premise Security Opp, Data Center Opp, etc.). Backs
 * outgrow.php's 'get' (read/backfill) and 'set' (write) actions.
 *
 * THIS IS THE SECOND WRITE this integration has ever made against
 * ConnectWise (the first was Activity creation -- see
 * connectwise-activity-create.php, whose file header is worth reading
 * before touching this one). Same caution applies, doubled: this isn't
 * even a new-record POST, it's a targeted update to one specific custom
 * field on an EXISTING Company record -- get the field reference wrong and
 * this could silently touch the wrong field, or fail outright.
 *
 * BUG FIX 2026-09-16 (Michael, live report): saving a real customer's
 * OutGrow Last Touch date saved locally but never reached ConnectWise --
 * "ConnectWise request returned HTTP 400 for
 * .../company/companies/6790 -- { "code": "InvalidObject", "message":
 * "company object is invalid", "errors": [ { "code": "OutOfRange",
 * "message": "The field yearEstablished must be between 1900 and 9999.",
 * "resource": "company", "field": "yearEstablished" }, { "code":
 * "OutOfRange", "message": "The field revenueYear must be between 1900
 * and 9999.", "resource": "company", "field": "revenueYear" } ] }". This
 * was originally written as a JSON-Patch PATCH targeting only the one
 * custom field's value (see the ORIGINAL design note this replaces,
 * preserved below) -- but that error names two fields this write never
 * touched, which means ConnectWise's Company PATCH endpoint on this
 * instance revalidates the ENTIRE stored record on every PATCH, not just
 * what's actually patched, and 400s if ANY field already has a bad legacy
 * value (company 6790's yearEstablished/revenueYear, whatever they
 * currently are, apparently aren't between 1900-9999). This is the exact
 * same quirk already found and worked around for Company writes in the
 * register app (register/api/customers.php's
 * register_cw_put_company_with_retry() -- there it's a 500 with a generic
 * DateTime error instead of a structured 400, but same root cause). Fixed
 * by switching this write to the shared
 * relationships_cw_put_company_with_retry() helper (connectwise.php) --
 * PUT the full record back with just this field changed, auto-stripping
 * whichever field ConnectWise names as invalid and retrying. See that
 * function's docblock for the one thing this fix could NOT verify from
 * this build environment (no live ConnectWise credentials here): whether
 * stripping a field from the PUT payload leaves its stored value
 * unchanged, or clears it -- Michael should check company 6790's Year
 * Established / Revenue Year fields after the next real save.
 *
 * ORIGINAL design note (2026-09-15), now superseded by the PUT-based fix
 * above but preserved for context on the two things this integration
 * could not independently confirm against real ConnectWise data
 * (unreachable from every build environment used on this project so far --
 * see connectwise-activity.php / connectwise-activity-create.php file
 * headers for the running history of that constraint):
 *
 * 1. That "OutGrow Last Touch" is really a CUSTOM field (not some built-in
 *    Company field with that caption) -- assumed from the screenshot's
 *    layout (grouped with obviously-custom checkbox fields, each with its
 *    own info icon, a classic ConnectWise custom-tab pattern). This code
 *    looks it up dynamically by caption in the Company's own `customFields`
 *    array rather than hardcoding a field id, specifically so a wrong
 *    assumption here surfaces as a clear "field not found" error instead
 *    of silently touching the wrong thing.
 * 2. The exact shape ConnectWise expects to update ONE custom field on an
 *    existing Company. The write now sends the Company's FULL customFields
 *    array back (via the PUT-with-retry helper) with just this one entry's
 *    `value` changed, keyed by that field's POSITION in the array
 *    ConnectWise returns for this record (NOT its setup-level custom field
 *    id) -- so this always does a fresh GET immediately before writing to
 *    find that position, rather than caching an index that could shift.
 *    The date format reuses the one format this integration has ACTUALLY
 *    confirmed ConnectWise v4_6_release accepts for a date/time value --
 *    UTC, second precision, "Z" suffix (e.g. "2026-09-15T00:00:00Z") --
 *    confirmed for Activity dateStart/dateEnd in
 *    connectwise-activity-create.php after two earlier guesses were
 *    rejected; not independently reconfirmed for a custom field
 *    specifically, but the most likely correct starting guess given that
 *    history. Real proof only comes from Michael saving one on the live
 *    site and checking ConnectWise's Company screen -- outgrow.php's
 *    'cw_probe' diagnostic action exists for exactly that check (see its
 *    doc comment -- remove it once confirmed, same as this integration's
 *    now-removed cw_date_probe).
 *
 * Per Michael's standing instruction for this integration ("save locally,
 * log the ConnectWise failure" -- see connectwise-activity-create.php):
 * nothing in this file is ever allowed to block or revert the local
 * outgrow_last_touch_history save. Every public function here either
 * returns a plain value/null or throws RelationshipsConnectWiseError;
 * outgrow.php is what catches that and logs it onto the history row
 * without failing the request.
 */

declare(strict_types=1);

require_once __DIR__ . '/connectwise.php';

const RELATIONSHIPS_OUTGROW_CAPTION = 'OutGrow Last Touch';

/**
 * This Company's full customFields array, straight from ConnectWise --
 * each entry roughly {id, caption, type, value, ...}. Empty array (never
 * null) if the company has none, so callers can foreach() without an
 * is_array() check.
 */
function relationships_cw_company_custom_fields(string $cwCompanyId): array
{
    $company = relationships_cw_request('/company/companies/' . rawurlencode($cwCompanyId), ['fields' => 'id,customFields']);
    $fields = $company['customFields'] ?? [];
    return is_array($fields) ? $fields : [];
}

/**
 * Finds the entry in a customFields array whose caption matches (trimmed,
 * case-insensitive -- captions are typed by a human in ConnectWise's setup
 * screens, not worth failing a whole feature over a stray space or a
 * capitalization difference). Returns the entry PLUS its array position
 * (needed for the JSON Patch path -- see file header) as `_index`, or null
 * if no entry has that caption at all.
 */
function relationships_cw_find_custom_field(array $customFields, string $caption): ?array
{
    $target = trim(mb_strtolower($caption));
    foreach (array_values($customFields) as $i => $field) {
        if (!is_array($field)) {
            continue;
        }
        $fieldCaption = trim(mb_strtolower((string) ($field['caption'] ?? '')));
        if ($fieldCaption === $target) {
            $field['_index'] = $i;
            return $field;
        }
    }
    return null;
}

/**
 * Best-effort parse of whatever ConnectWise hands back for a Date-type
 * custom field's `value` into a plain "YYYY-MM-DD" -- observed/likely
 * shapes are a full ISO datetime ("2026-09-15T00:00:00Z") or already a
 * bare date; this take the first 10 chars if they already look like
 * "YYYY-MM-DD", otherwise falls back to DateTime parsing so an unexpected
 * format still has a chance of working rather than just being dropped.
 * Returns null for an empty/unset field or anything unparseable.
 */
function relationships_cw_outgrow_parse_date(mixed $raw): ?string
{
    if ($raw === null || $raw === '') {
        return null;
    }
    $raw = (string) $raw;
    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $raw, $m)) {
        return substr($raw, 0, 10);
    }
    try {
        $d = new DateTimeImmutable($raw);
        return $d->format('Y-m-d');
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Reads this Company's current "OutGrow Last Touch" value from
 * ConnectWise, as "YYYY-MM-DD", or null if the field is unset (or this
 * Company has no such field at all -- same result either way, since
 * there's nothing to backfill in both cases). Throws
 * RelationshipsConnectWiseError only on a real transport/API failure
 * (unreachable, auth, a real HTTP error) -- an unset/missing field is a
 * normal null return, not an error.
 */
function relationships_cw_outgrow_read(string $cwCompanyId): ?string
{
    $fields = relationships_cw_company_custom_fields($cwCompanyId);
    $field = relationships_cw_find_custom_field($fields, RELATIONSHIPS_OUTGROW_CAPTION);
    if ($field === null) {
        return null;
    }
    return relationships_cw_outgrow_parse_date($field['value'] ?? null);
}

/**
 * Writes a new "OutGrow Last Touch" date to this Company's ConnectWise
 * record -- see file header for the real uncertainty here (PUT-with-retry
 * mechanics, date format). Fetches the Company's full customFields array
 * fresh (rather than trusting a value the caller might have cached) so
 * the array position used is never stale, then hands the whole array --
 * with just this one entry's value changed -- to
 * relationships_cw_put_company_with_retry() (connectwise.php), which PUTs
 * the full Company record back rather than PATCHing just this field (see
 * file header: PATCH on this ConnectWise instance revalidates the entire
 * stored record and 400s/500s on any pre-existing invalid field,
 * regardless of what's actually being changed). Throws
 * RelationshipsConnectWiseError if the field can't be found on this
 * Company at all, or if the write ultimately fails -- callers
 * (outgrow.php) catch this and log it rather than letting it block the
 * local save.
 */
function relationships_cw_outgrow_write(string $cwCompanyId, string $dateYmd): void
{
    $fields = relationships_cw_company_custom_fields($cwCompanyId);
    $field = relationships_cw_find_custom_field($fields, RELATIONSHIPS_OUTGROW_CAPTION);
    if ($field === null) {
        throw new RelationshipsConnectWiseError(
            'This Company has no "' . RELATIONSHIPS_OUTGROW_CAPTION . '" custom field in ConnectWise -- ' .
            'nothing to update. (Checked ' . count($fields) . ' custom field(s) on this Company.)'
        );
    }

    $d = DateTimeImmutable::createFromFormat('Y-m-d', $dateYmd);
    if ($d === false) {
        throw new RelationshipsConnectWiseError('Invalid date "' . $dateYmd . '" -- expected YYYY-MM-DD.');
    }
    // Midnight UTC on the chosen calendar date, in the one date/time wire
    // format this integration has actually confirmed ConnectWise
    // v4_6_release accepts (see file header) -- not re-confirmed for a
    // custom field specifically.
    $wireValue = $d->setTime(0, 0, 0)->format('Y-m-d\T00:00:00\Z');

    $index = (int) $field['_index'];
    $updatedFields = $fields;
    $updatedFields[$index]['value'] = $wireValue;

    relationships_cw_put_company_with_retry($cwCompanyId, ['customFields' => $updatedFields]);
}
