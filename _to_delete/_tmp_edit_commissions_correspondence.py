#!/usr/bin/env python3
path = "commissions/api/sync-core.php"
with open(path, "r", encoding="utf-8") as f:
    src = f.read()
orig = src

old = """        $billable = strtolower((string) ($t['billableOption'] ?? ''));
        if ($billable === 'donotbill' || $billable === 'nocharge') {
            continue;
        }
        $hours = 0.0;"""
assert src.count(old) == 1, "old count=%d" % src.count(old)

new = """        $billable = strtolower((string) ($t['billableOption'] ?? ''));
        if ($billable === 'donotbill' || $billable === 'nocharge') {
            continue;
        }
        // Correspondence/Meeting time never counts against a rep, billable or not --
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
        }
        $hours = 0.0;"""
assert src.count(old) == 1
src = src.replace(old, new, 1)
assert src != orig
with open(path, "w", encoding="utf-8") as f:
    f.write(src)
print("sync-core.php updated: Correspondence/Meeting time excluded from commissions regardless of billableOption")
