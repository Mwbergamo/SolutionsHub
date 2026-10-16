#!/usr/bin/env python3
path = "relationships/api/meetings.php"
with open(path, "r", encoding="utf-8") as f:
    src = f.read()
orig = src

old = """    relationships_respond(200, ['ok' => true, 'roster' => $roster, 'counts' => $counts, 'tasks' => $tasks, 'risk_scan_alerts' => $riskScanAlerts, 'risk_scan_assigned' => $riskScanAssigned, 'prospect_alerts' => $prospectAlerts]);
}

if ($action === 'rep_todos') {"""
assert src.count(old) == 1, "anchor count=%d" % src.count(old)

new = """    relationships_respond(200, ['ok' => true, 'roster' => $roster, 'counts' => $counts, 'tasks' => $tasks, 'risk_scan_alerts' => $riskScanAlerts, 'risk_scan_assigned' => $riskScanAssigned, 'prospect_alerts' => $prospectAlerts]);
}

// ---------------------------------------------------------------- todo_overview
// Front-page "To-Do List" gauge drill-down -- added 2026-10-16, per Michael's
// request for a global card with three clickable numbers: Overdue (red), Due
// Today (yellow), and Future (green). The gauge's three counts themselves are
// computed in dashboard.php's 'overview' action (same request that builds the
// rest of the front page); this is the list BEHIND whichever number a rep
// clicks, fetched on demand. Unlike the 'global' action above (meeting_tasks
// only, grouped by rep), this merges BOTH to-do sources -- general_todos (the
// per-customer "To-Do List" card, 2026-10-15) and meeting_tasks -- same as
// todo_summary/todo_list already do per customer, just across every customer
// at once. An undated to-do is not-yet-due (same rule as the per-customer
// badge, AskUserQuestion 2026-10-15), so it falls into the 'future' bucket.
if ($action === 'todo_overview') {
    $bucket = (string) ($_GET['bucket'] ?? '');
    if (!in_array($bucket, ['overdue', 'due_today', 'future'], true)) {
        relationships_respond(400, ['ok' => false, 'error' => 'bucket must be overdue, due_today, or future.']);
    }
    $territoryFilter = $allowedTerritories === null
        ? ['sql' => '', 'params' => []]
        : relationships_territory_filter_sql($allowedTerritories, 'c');
    $today = (new DateTimeImmutable('today', new DateTimeZone('America/New_York')))->format('Y-m-d');
    $bucketClause = [
        'overdue' => 'AND t.due_date IS NOT NULL AND t.due_date < :today',
        'due_today' => 'AND t.due_date = :today',
        'future' => 'AND (t.due_date IS NULL OR t.due_date > :today)',
    ][$bucket];

    $generalStmt = $pdo->prepare(
        "SELECT t.id, t.description, t.assigned_to_name, t.customer_id, c.name AS customer_name, t.created_at, t.due_date
         FROM general_todos t
         JOIN customers c ON c.id = t.customer_id
         WHERE t.completed_at IS NULL AND t.removed_at IS NULL {$bucketClause} {$territoryFilter['sql']}
         ORDER BY (t.due_date IS NULL) ASC, t.due_date ASC, t.created_at ASC
         LIMIT 400"
    );
    $generalStmt->execute(array_merge([':today' => $today], $territoryFilter['params']));
    $todoOverviewGeneral = array_map(static function (array $r): array {
        return [
            'source' => 'general',
            'id' => (int) $r['id'],
            'description' => $r['description'],
            'assigned_to_name' => $r['assigned_to_name'],
            'customer_id' => (int) $r['customer_id'],
            'customer_name' => $r['customer_name'],
            'meeting_id' => null,
            'meeting_subject' => null,
            'created_at' => $r['created_at'],
            'due_date' => $r['due_date'] ?? null,
        ];
    }, $generalStmt->fetchAll(PDO::FETCH_ASSOC));

    $meetingStmt = $pdo->prepare(
        "SELECT t.id, t.description, t.assigned_to_name, t.customer_id, c.name AS customer_name,
                t.meeting_id, m.subject AS meeting_subject, t.created_at, t.due_date
         FROM meeting_tasks t
         JOIN customer_meetings m ON m.id = t.meeting_id
         JOIN customers c ON c.id = t.customer_id
         WHERE t.completed_at IS NULL {$bucketClause} {$territoryFilter['sql']}
         ORDER BY (t.due_date IS NULL) ASC, t.due_date ASC, t.created_at ASC
         LIMIT 400"
    );
    $meetingStmt->execute(array_merge([':today' => $today], $territoryFilter['params']));
    $todoOverviewMeeting = array_map(static function (array $r): array {
        return [
            'source' => 'meeting',
            'id' => (int) $r['id'],
            'description' => $r['description'],
            'assigned_to_name' => $r['assigned_to_name'],
            'customer_id' => (int) $r['customer_id'],
            'customer_name' => $r['customer_name'],
            'meeting_id' => (int) $r['meeting_id'],
            'meeting_subject' => $r['meeting_subject'],
            'created_at' => $r['created_at'],
            'due_date' => $r['due_date'] ?? null,
        ];
    }, $meetingStmt->fetchAll(PDO::FETCH_ASSOC));

    $todoOverviewItems = array_merge($todoOverviewGeneral, $todoOverviewMeeting);
    usort($todoOverviewItems, static fn (array $a, array $b): int =>
        ($a['due_date'] === null ? 1 : 0) <=> ($b['due_date'] === null ? 1 : 0)
            ?: strcmp((string) $a['due_date'], (string) $b['due_date'])
            ?: strcmp((string) $a['created_at'], (string) $b['created_at'])
    );
    $todoOverviewItems = array_slice($todoOverviewItems, 0, 500);

    relationships_respond(200, ['ok' => true, 'bucket' => $bucket, 'items' => $todoOverviewItems]);
}

if ($action === 'rep_todos') {"""
assert src.count(old) == 1
src = src.replace(old, new, 1)

assert src != orig
with open(path, "w", encoding="utf-8") as f:
    f.write(src)
print("meetings.php updated: todo_overview action added")
