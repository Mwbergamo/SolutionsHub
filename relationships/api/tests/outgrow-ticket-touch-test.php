<?php
// CLI-only: php relationships/api/tests/outgrow-ticket-touch-test.php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../outgrow-ticket-parse.php';
require __DIR__ . '/../outgrow-ticket-touch.php';

$fails = 0;
function check(string $label, $got, $want): void {
    global $fails;
    if ($got !== $want) { $fails++; echo "FAIL $label\n  got:  " . var_export($got, true) . "\n  want: " . var_export($want, true) . "\n"; }
    else echo "ok   $label\n";
}

// header date parsing
check('header date', relationships_outgrow_header_date('Fri 10/9/2026/8:10 AM EDT/ Chuck Fleet (time)-'), '2026-10-09');
check('header date 2-digit', relationships_outgrow_header_date('Wed 12/31/2025/11:05 PM EST/ A B'), '2025-12-31');
check('header date invalid', relationships_outgrow_header_date('Fri 13/45/2026/8:10 AM EDT/ X Y'), null);
check('not a header', relationships_outgrow_header_date('Good morning Robin'), null);

$pdo = new PDO('sqlite::memory:');
$pdo->exec('CREATE TABLE customers (id INTEGER PRIMARY KEY AUTOINCREMENT, connectwise_id TEXT, name TEXT NOT NULL, territory_name TEXT)');
$pdo->exec('CREATE TABLE outgrow_last_touch_history (id INTEGER PRIMARY KEY AUTOINCREMENT, customer_id INTEGER NOT NULL, touch_date TEXT NOT NULL, source TEXT NOT NULL DEFAULT \'manual\', set_by_user_id INTEGER, set_by_name TEXT NOT NULL, cw_push_status TEXT, cw_push_error TEXT, created_at TEXT NOT NULL DEFAULT (datetime(\'now\')))');
$ins = $pdo->prepare('INSERT INTO customers (connectwise_id, name, territory_name) VALUES (?,?,?)');
foreach ([
    ['6790', 'Christine S Rausch MD PC', 'Richmond'],      // 1
    ['111', 'Acme Widgets, Inc.', 'Richmond'],              // 2
    ['MOCK-1', 'Dup Co', 'Richmond'],                       // 3 (mock)
    ['222', 'Dup Co', 'Richmond'],                          // 4 (real) -> wins over mock
    ['333', 'Twin Dental LLC', 'Richmond'],                 // 5
    ['444', 'Twin Dental Inc', 'Richmond'],                 // 6  -> loose match to 5 is ambiguous
    ['555', 'Far Away Corp', 'Norfolk'],                    // 7
] as $r) $ins->execute($r);

$user = ['id' => 7, 'name' => 'Michael Bergamo'];
$cwCalls = [];
$cw = function (string $id, string $date) use (&$cwCalls): void { $cwCalls[] = [$id, $date]; };
$apply = fn (string $co, ?string $d, ?array $terr = null, ?callable $w = null) => relationships_outgrow_apply_touch($pdo, $user, $terr, $co, $d, $w ?? $cw);
$rows = fn (int $id) => $pdo->query("SELECT touch_date, source, set_by_name, cw_push_status FROM outgrow_last_touch_history WHERE customer_id = $id ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

// 1. basic: exact name, note date (NOT today)
$r = $apply('Christine S Rausch MD PC', '2026-10-09');
check('updated', $r['status'], 'updated');
check('customer id', $r['customer_id'], 1);
check('history row', $rows(1), [['touch_date' => '2026-10-09', 'source' => 'ticket', 'set_by_name' => 'Michael Bergamo', 'cw_push_status' => 'pushed']]);
check('cw pushed note date', $cwCalls, [['6790', '2026-10-09']]);

// 2. same ticket again -> no duplicate
check('already', $apply('Christine S Rausch MD PC', '2026-10-09')['status'], 'already');
check('still one row', count($rows(1)), 1);

// 3. older note never rolls the date back
check('newer_exists', $apply('Christine S Rausch MD PC', '2026-09-01')['status'], 'newer_exists');
check('still one row (older)', count($rows(1)), 1);

// 4. a later note moves it forward
check('forward', $apply('Christine S Rausch MD PC', '2026-10-12')['status'], 'updated');
check('two rows', array_column($rows(1), 'touch_date'), ['2026-10-09', '2026-10-12']);

// 5. name normalization: case, punctuation, entity suffix
check('case/punct', $apply('ACME WIDGETS INC', '2026-10-01')['customer_id'], 2);
check('ampersand', relationships_outgrow_company_key('Smith & Sons'), 'smith and sons');

// 6. mock duplicate loses to the real one
check('real beats mock', $apply('Dup Co', '2026-10-02')['customer_id'], 4);

// 7. ambiguous / none
check('ambiguous', $apply('Twin Dental', '2026-10-03')['status'], 'ambiguous');
check('none', $apply('Nobody Here LLC', '2026-10-03')['status'], 'no_match');
check('no company', $apply('  ', '2026-10-03')['status'], 'no_company');
check('no date', $apply('Acme Widgets', null)['status'], 'no_date');

// 8. territory scope
check('out of scope', $apply('Far Away Corp', '2026-10-04', ['Richmond'])['status'], 'out_of_scope');
check('out of scope wrote nothing', count($rows(7)), 0);
check('in scope', $apply('Far Away Corp', '2026-10-04', ['Norfolk'])['status'], 'updated');

// 9. ConnectWise failure is recorded but the local save stands
$r = $apply('Twin Dental LLC', '2026-10-05', null, function () { throw new RuntimeException('HTTP 400'); });
check('cw error still updated', $r['status'], 'updated');
check('cw error recorded', $rows(5)[0]['cw_push_status'], 'error');
check('cw error in message', str_contains($r['message'], 'didn’t reach ConnectWise'), true);

// 10. end to end with the parser on the SR#953911 text
$ticket = "Company:\tChristine S Rausch MD PC\nContact:\tRobin LaPointe\nDiscussion:\nFri 10/16/2026/8:10 AM EDT/ Chuck Fleet (time)-\nHello\n";
$p = relationships_outgrow_parse_ticket($ticket);
check('parsed note_date', $p['values']['note_date'], '2026-10-16');
check('e2e', $apply($p['values']['company'], $p['values']['note_date'])['status'], 'updated');

exit($fails ? 1 : 0);
