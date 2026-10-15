#!/usr/bin/env python3
import sys

def do(src, old, new, label):
    n = src.count(old)
    assert n == 1, "%s: count=%d" % (label, n)
    return src.replace(old, new, 1)

# --- db.php ---
path_db = "api/db.php"
with open(path_db, "r", encoding="utf-8") as f:
    db = f.read()
orig_db = db

db = do(db,
"""        CREATE TABLE IF NOT EXISTS invoice_lines (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            invoice_id INTEGER NOT NULL,
            kind TEXT NOT NULL,                   -- product | time | agreement | adjustment
            item TEXT,
            ticket_id INTEGER,
            ticket_summary TEXT,
            hours REAL,                           -- billable (invoiced) hours
            actual_hours REAL,                    -- hours actually worked; labor cost is based on these
            member TEXT,                          -- technician (ConnectWise member) on time lines
            qty REAL,""",
"""        CREATE TABLE IF NOT EXISTS invoice_lines (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            invoice_id INTEGER NOT NULL,
            kind TEXT NOT NULL,                   -- product | time | agreement | adjustment
            item TEXT,
            ticket_id INTEGER,
            ticket_summary TEXT,
            hours REAL,                           -- billable (invoiced) hours
            actual_hours REAL,                    -- hours actually worked; labor cost is based on these
            is_project INTEGER NOT NULL DEFAULT 0, -- time line charged to a ConnectWise ProjectTicket --
                                                    -- these bill (and cost) at RAW actual hours, never
                                                    -- rounded to the Service-ticket billing bracket
            member TEXT,                          -- technician (ConnectWise member) on time lines
            qty REAL,""",
    "db.php CREATE TABLE invoice_lines")

db = do(db,
"""    // Databases created before actual_hours/member existed.
    $cols = array_column($pdo->query('PRAGMA table_info(invoice_lines)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array('actual_hours', $cols, true)) {
        $pdo->exec('ALTER TABLE invoice_lines ADD COLUMN actual_hours REAL');
    }
    if (!in_array('member', $cols, true)) {
        $pdo->exec('ALTER TABLE invoice_lines ADD COLUMN member TEXT');
    }""",
"""    // Databases created before actual_hours/member existed.
    $cols = array_column($pdo->query('PRAGMA table_info(invoice_lines)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array('actual_hours', $cols, true)) {
        $pdo->exec('ALTER TABLE invoice_lines ADD COLUMN actual_hours REAL');
    }
    if (!in_array('member', $cols, true)) {
        $pdo->exec('ALTER TABLE invoice_lines ADD COLUMN member TEXT');
    }
    // Databases created before is_project existed (2026-10-15 Project-ticket-hours fix).
    if (!in_array('is_project', $cols, true)) {
        $pdo->exec('ALTER TABLE invoice_lines ADD COLUMN is_project INTEGER NOT NULL DEFAULT 0');
    }""",
    "db.php migration block")

assert db != orig_db
with open(path_db, "w", encoding="utf-8") as f:
    f.write(db)
print("db.php updated: invoice_lines.is_project column + migration")

# --- sync-core.php ---
path_sc = "api/sync-core.php"
with open(path_sc, "r", encoding="utf-8") as f:
    sc = f.read()
orig_sc = sc

# 1) Bump the rules version so the next sync fully rebuilds every invoice in its
#    lookback window under the corrected logic (same mechanism used for the
#    2026-10-09 NC-time and hour-rounding fixes).
sc = do(sc,
"const COMMISSIONS_RULES_VERSION = '2026-10-09-round-hours';",
"const COMMISSIONS_RULES_VERSION = '2026-10-15-project-actual-hours';",
    "bump COMMISSIONS_RULES_VERSION")

# 2) The time-entry loop in commissions_build_invoice_lines: don't bracket-round
#    Project-ticket hours.
sc = do(sc,
"""        // Same basis as the existing Service Commission report: price is the
        // BILLED hours x rate; cost is the hours actually worked x hourly cost.
        // "Actually worked" is rounded up to CodeBlue's billing bracket first
        // (see commissions_round_worked_hours) -- a short visit that would
        // only ever be billed for the minimum isn't a labor-cost loss just
        // because the raw clock time undershoots it. Confirmed by Michael
        // 2026-10-09 against the rate sheet's rounding rules.
        $actualRaw = (isset($t['actualHours']) && (float) $t['actualHours'] > 0) ? (float) $t['actualHours'] : $hours;
        $actual = commissions_round_worked_hours($actualRaw);
        $rate = (float) ($t['hourlyRate'] ?? 0);
        $chargeType = (string) ($t['chargeToType'] ?? '');
        $ticketId = (isset($t['chargeToId']) && stripos($chargeType, 'Ticket') !== false) ? (int) $t['chargeToId'] : (isset($t['ticket']['id']) ? (int) $t['ticket']['id'] : null);
        $member = (string) ($t['member']['name'] ?? '');
        $lines[] = [
            'kind' => 'time',
            'item' => 'Labor' . ($member !== '' ? ' — ' . $member : ''),
            'ticket_id' => $ticketId,
            'ticket_summary' => $ticketId !== null ? ($tickets[$ticketId] ?? null) : null,
            'hours' => $hours,
            'actual_hours' => $actual,
            'member' => $member !== '' ? $member : null,
            'qty' => null,
            'price' => round($hours * $rate, 2),
            'unit_cost' => null,
            'cost' => round($actual * $laborCost, 2),
            'cost_note' => 'Assumed labor cost $' . number_format($laborCost, 2) . '/hr x ' . $actual . ' actual hrs'
                . (abs($actual - $actualRaw) > 0.0001 ? ' (rounded up from ' . $actualRaw . ' raw hrs per billing-bracket rule)' : ''),
        ];
    }""",
"""        $chargeType = (string) ($t['chargeToType'] ?? '');
        // Project tickets bill (and cost) at RAW actual hours -- ConnectWise never applies
        // the rate sheet's 30-minute billing bracket to them. Confirmed live 2026-10-15
        // (Michael, against ticket #931457 / invoice #160752): a ProjectTicket time entry's
        // actualHours, hoursBilled and invoiceHours are always identical (e.g. 0.58/0.58/0.58
        // -- no rounding anywhere in ConnectWise). The bracket-rounding rule below is a
        // Service-ticket-only rule; applying it to a Project line inflated cost past what was
        // actually billed and manufactured a loss that never happened.
        $isProjectTicket = stripos($chargeType, 'Project') !== false;
        // Same basis as the existing Service Commission report: price is the BILLED hours x
        // rate; cost is the hours actually worked x hourly cost. On a Service ticket,
        // "actually worked" is rounded up to CodeBlue's billing bracket first (see
        // commissions_round_worked_hours) -- a short visit that would only ever be billed
        // for the minimum isn't a labor-cost loss just because the raw clock time undershoots
        // it (confirmed by Michael 2026-10-09 against the rate sheet's rounding rules). A
        // Project ticket skips this rounding entirely (see above).
        $actualRaw = (isset($t['actualHours']) && (float) $t['actualHours'] > 0) ? (float) $t['actualHours'] : $hours;
        $actual = $isProjectTicket ? $actualRaw : commissions_round_worked_hours($actualRaw);
        $rate = (float) ($t['hourlyRate'] ?? 0);
        $ticketId = (isset($t['chargeToId']) && stripos($chargeType, 'Ticket') !== false) ? (int) $t['chargeToId'] : (isset($t['ticket']['id']) ? (int) $t['ticket']['id'] : null);
        $member = (string) ($t['member']['name'] ?? '');
        $lines[] = [
            'kind' => 'time',
            'item' => 'Labor' . ($member !== '' ? ' — ' . $member : ''),
            'ticket_id' => $ticketId,
            'ticket_summary' => $ticketId !== null ? ($tickets[$ticketId] ?? null) : null,
            'hours' => $hours,
            'actual_hours' => $actual,
            'is_project' => $isProjectTicket ? 1 : 0,
            'member' => $member !== '' ? $member : null,
            'qty' => null,
            'price' => round($hours * $rate, 2),
            'unit_cost' => null,
            'cost' => round($actual * $laborCost, 2),
            'cost_note' => $isProjectTicket
                ? 'Assumed labor cost $' . number_format($laborCost, 2) . '/hr x ' . $actual . ' actual hrs (Project ticket — ConnectWise bills raw actual hours, no billing-bracket rounding)'
                : 'Assumed labor cost $' . number_format($laborCost, 2) . '/hr x ' . $actual . ' actual hrs'
                    . (abs($actual - $actualRaw) > 0.0001 ? ' (rounded up from ' . $actualRaw . ' raw hrs per billing-bracket rule)' : ''),
        ];
    }""",
    "commissions_build_invoice_lines time loop")

# 3) The INSERT statement that persists invoice_lines rows: add the is_project column.
sc = do(sc,
"""            'INSERT INTO invoice_lines (invoice_id, kind, item, ticket_id, ticket_summary, hours, actual_hours, member, qty, price, cost, unit_cost, cost_note, gp, over_year, pct, commission, is_loss)
             VALUES (:iid, :k, :item, :tid, :ts, :h, :ah, :mem, :q, :p, :c, :uc, :cn, :gp, :oy, :pct, :com, :loss)'""",
"""            'INSERT INTO invoice_lines (invoice_id, kind, item, ticket_id, ticket_summary, hours, actual_hours, is_project, member, qty, price, cost, unit_cost, cost_note, gp, over_year, pct, commission, is_loss)
             VALUES (:iid, :k, :item, :tid, :ts, :h, :ah, :ip, :mem, :q, :p, :c, :uc, :cn, :gp, :oy, :pct, :com, :loss)'""",
    "INSERT INTO invoice_lines statement")

sc = do(sc,
"""                    $insLine->execute([
                        ':iid' => $id, ':k' => $l['kind'], ':item' => $l['item'], ':tid' => $l['ticket_id'], ':ts' => $l['ticket_summary'],
                        ':h' => $l['hours'], ':ah' => $l['actual_hours'] ?? null, ':mem' => $l['member'] ?? null, ':q' => $l['qty'], ':p' => $l['price'], ':c' => $l['cost'], ':uc' => $l['unit_cost'], ':cn' => $l['cost_note'],
                        ':gp' => $m['gp'], ':oy' => $overYear ? 1 : 0, ':pct' => 0, ':com' => 0, ':loss' => $m['is_loss'],
                    ]);""",
"""                    $insLine->execute([
                        ':iid' => $id, ':k' => $l['kind'], ':item' => $l['item'], ':tid' => $l['ticket_id'], ':ts' => $l['ticket_summary'],
                        ':h' => $l['hours'], ':ah' => $l['actual_hours'] ?? null, ':ip' => $l['is_project'] ?? 0, ':mem' => $l['member'] ?? null, ':q' => $l['qty'], ':p' => $l['price'], ':c' => $l['cost'], ':uc' => $l['unit_cost'], ':cn' => $l['cost_note'],
                        ':gp' => $m['gp'], ':oy' => $overYear ? 1 : 0, ':pct' => 0, ':com' => 0, ':loss' => $m['is_loss'],
                    ]);""",
    "$insLine->execute binding")

# 4) commissions_recompute(): don't re-round a Project-ticket line's stored actual_hours.
sc = do(sc,
"""            $cost = (float) $l['cost'];
            if ($l['kind'] === 'time') {
                $worked = ($l['actual_hours'] !== null && (float) $l['actual_hours'] > 0) ? (float) $l['actual_hours'] : (float) $l['hours'];
                // Safety net: a stored actual_hours from before the 2026-10-09
                // rounding fix may still be raw; re-rounding here is a no-op
                // once it's already bracket-aligned.
                $worked = commissions_round_worked_hours($worked);
                $cost = round($worked * $laborCost, 2);
            }""",
"""            $cost = (float) $l['cost'];
            if ($l['kind'] === 'time') {
                $worked = ($l['actual_hours'] !== null && (float) $l['actual_hours'] > 0) ? (float) $l['actual_hours'] : (float) $l['hours'];
                // Safety net: a stored actual_hours from before the 2026-10-09 rounding fix
                // may still be raw; re-rounding here is a no-op once it's already
                // bracket-aligned. Project-ticket lines (2026-10-15 fix) never get the
                // bracket rounding -- ConnectWise bills them at raw actual hours, so
                // re-rounding here would reintroduce the same manufactured-loss bug.
                if ((int) ($l['is_project'] ?? 0) === 0) {
                    $worked = commissions_round_worked_hours($worked);
                }
                $cost = round($worked * $laborCost, 2);
            }""",
    "commissions_recompute time-line cost")

assert sc != orig_sc
with open(path_sc, "w", encoding="utf-8") as f:
    f.write(sc)
print("sync-core.php updated: Project-ticket time lines use raw actual hours, no billing-bracket rounding")
