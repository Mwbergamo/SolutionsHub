#!/usr/bin/env python3
path = "commissions/api/sync-core.php"
with open(path, "r", encoding="utf-8") as f:
    src = f.read()
orig = src

# 1) Bump the rules version (generalizing the Correspondence/Meeting-only fix below).
old_version = "const COMMISSIONS_RULES_VERSION = '2026-10-15-correspondence-meeting-excluded';"
assert src.count(old_version) == 1, "version count=%d" % src.count(old_version)
new_version = "const COMMISSIONS_RULES_VERSION = '2026-10-15-nc-nb-worktypes-excluded';"
src = src.replace(old_version, new_version, 1)

# 2) Add the static NC/NB work-type list as a constant, right after COMMISSIONS_PAYOUT_RULES_VERSION.
old_const_anchor = """/** Bump when how payees share a line changes; the next API call re-applies it to unlocked months. */
const COMMISSIONS_PAYOUT_RULES_VERSION = '2026-10-06-chester-arcus';"""
assert src.count(old_const_anchor) == 1, "const anchor count=%d" % src.count(old_const_anchor)
new_const_anchor = old_const_anchor + """

/**
 * Work types ConnectWise's own Work Types admin screen (System > Setup Tables >
 * Work Type) has statically set to Bill = NC (No Charge) or NB (Non-Billable,
 * ConnectWise's DoNotBill) -- confirmed against Michael's full-table screenshot,
 * 2026-10-15. These never actually invoice the customer (some, like
 * Correspondence/Meeting, get "billed" against the customer's agreement just to
 * clear the time out of ConnectWise, which can leave a per-entry billableOption
 * of Billable even though $0 was ever charged -- see the 2026-10-15 fix below).
 * Michael's rule: none of these should ever cost a rep; a rep is only paid on
 * whatever percentage the customer actually pays. Matched on the time entry's
 * own workType.name (see commissions_work_type_is_excluded()), independent of
 * whatever billableOption that specific entry happens to carry. Keep this list
 * in sync with ConnectWise's Work Types table if CodeBlue adds/changes one.
 */
const COMMISSIONS_NC_NB_WORK_TYPES = [
    'Correspondence / Meeting',
    'Int. Assist',
    'Personal',
    'Project Management',
    'Sick',
    'Pre-Sales',
    'System Prep',
    'Training',
    'Travel',
    'Travel To CBT',
    'Vacation',
    'Warranty - Labor',
    'Warranty - Parts',
];

/** Case/whitespace-insensitive match against COMMISSIONS_NC_NB_WORK_TYPES. */
function commissions_work_type_is_excluded(string $workTypeName): bool
{
    $norm = static fn (string $s): string => strtolower(preg_replace('/\\s+/', '', $s) ?? $s);
    $needle = $norm($workTypeName);
    if ($needle === '') {
        return false;
    }
    foreach (COMMISSIONS_NC_NB_WORK_TYPES as $wt) {
        if ($norm($wt) === $needle) {
            return true;
        }
    }
    return false;
}"""
assert src.count(old_const_anchor) == 1
src = src.replace(old_const_anchor, new_const_anchor, 1)

# 3) Replace the narrow Correspondence/Meeting-only check with the general list-based check.
old_block = """        // Correspondence/Meeting time never counts against a rep, billable or not --
        // Michael, 2026-10-15 (invoice #161029, Dale Kain / ticket #952760): this work
        // type gets "billed" against the customer's IT Services Agreement just to clear
        // it out of ConnectWise -- Billable shows 'B' and Status shows 'Billed', but the
        // invoice amount is always $0 because the agreement absorbs it; the customer is
        // never actually charged. Unlike the Do Not Bill / No Charge check above (which
        // looks at billableOption), this one has to check the work type itself, because
        // ConnectWise marks these entries Billable even though nothing is ever invoiced.
        $workTypeName = strtolower((string) ($t['workType']['name'] ?? ''));
        if (str_contains($workTypeName, 'correspondence') && str_contains($workTypeName, 'meeting')) {
            continue;
        }"""
assert src.count(old_block) == 1, "old_block count=%d" % src.count(old_block)
new_block = """        // Statically NC/NB work types never count against a rep, billable or not --
        // Michael, 2026-10-15 (first flagged on invoice #161029, Dale Kain / ticket
        // #952760: Correspondence/Meeting shown Billable='B'/Status='Billed' on
        // ConnectWise's own Time Detail List, yet Invoice Amount was always $0.00).
        // Michael then confirmed the FULL list via ConnectWise's Work Types admin
        // screen: every work type whose Bill column reads NC or NB (see
        // COMMISSIONS_NC_NB_WORK_TYPES) -- "These work types should not count
        // against reps. They only get paid on the percentage that is paid by the
        // customer." Unlike the Do Not Bill / No Charge check above (which looks at
        // the per-entry billableOption), this checks the entry's work type itself,
        // because ConnectWise can still show an individual entry as Billable even
        // when its work type is never actually invoiced.
        if (commissions_work_type_is_excluded((string) ($t['workType']['name'] ?? ''))) {
            continue;
        }"""
assert src.count(old_block) == 1
src = src.replace(old_block, new_block, 1)

assert src != orig
with open(path, "w", encoding="utf-8") as f:
    f.write(src)
print("sync-core.php updated: full NC/NB work-type list now excluded from commissions")
