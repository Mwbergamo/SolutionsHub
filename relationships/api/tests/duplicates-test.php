<?php
// CLI-only: php relationships/api/tests/duplicates-test.php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../duplicates-core.php';
$fails = 0;
function check(string $l, $g, $w): void { global $fails; if ($g !== $w) { $fails++; echo "FAIL $l\n  got:  " . var_export($g, true) . "\n  want: " . var_export($w, true) . "\n"; } else echo "ok   $l\n"; }

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE customers (id INTEGER PRIMARY KEY AUTOINCREMENT, connectwise_id TEXT, name TEXT NOT NULL, is_mock INTEGER NOT NULL DEFAULT 0, cw_status_name TEXT, cw_bucket TEXT, territory_name TEXT, has_recent_billing INTEGER DEFAULT 0, ticket_count_ytd INTEGER DEFAULT 0, active_contact_count INTEGER DEFAULT 0, is_prospect_only INTEGER DEFAULT 0, is_residential INTEGER DEFAULT 0, is_duplicate_hidden INTEGER NOT NULL DEFAULT 0)");
$pdo->exec("CREATE TABLE customer_services (id INTEGER PRIMARY KEY AUTOINCREMENT, customer_id INTEGER NOT NULL, service_name TEXT)");
$pdo->exec("CREATE TABLE customer_meetings (id INTEGER PRIMARY KEY AUTOINCREMENT, customer_id INTEGER NOT NULL)");
$pdo->exec("CREATE TABLE outgrow_last_touch_history (id INTEGER PRIMARY KEY AUTOINCREMENT, customer_id INTEGER NOT NULL, touch_date TEXT NOT NULL)");
$pdo->exec("CREATE TABLE customer_duplicate_overrides (id INTEGER PRIMARY KEY AUTOINCREMENT, customer_id INTEGER NOT NULL, hidden_connectwise_id TEXT, keeper_customer_id INTEGER NOT NULL, keeper_connectwise_id TEXT, prev_bucket TEXT, prev_is_prospect_only INTEGER NOT NULL DEFAULT 0, prev_is_residential INTEGER NOT NULL DEFAULT 0, created_by TEXT, created_at TEXT NOT NULL DEFAULT (datetime('now')))");
$pdo->exec("CREATE UNIQUE INDEX i1 ON customer_duplicate_overrides(customer_id)");
$pdo->exec("CREATE TABLE customer_duplicate_dismissals (group_key TEXT PRIMARY KEY, dismissed_by TEXT, dismissed_at TEXT NOT NULL DEFAULT (datetime('now')))");

$ins = $pdo->prepare('INSERT INTO customers (connectwise_id, name, cw_status_name, cw_bucket, is_mock) VALUES (?,?,?,?,?)');
foreach ([
    ['10', 'Acme Dental, LLC', 'Active', 'active', 0],     // 1
    ['11', 'Acme Dental', 'Prospect', 'prospect', 0],       // 2
    ['MOCK-1', 'Acme Dental', null, 'excluded', 1],         // 3 mock: ignored
    ['20', 'Solo Co', 'Active', 'active', 0],               // 4
    ['30', 'Twin Inc', 'Active', 'active', 0],              // 5
    ['31', 'Twin LLC', 'Active', 'active', 0],              // 6
] as $r) $ins->execute($r);
$pdo->exec("INSERT INTO customer_services (customer_id, service_name) VALUES (2,'x'),(2,'y'),(1,'z')");
$pdo->exec("INSERT INTO outgrow_last_touch_history (customer_id, touch_date) VALUES (2,'2026-09-01')");

$l = relationships_dup_list($pdo);
check('two groups', array_column($l['groups'], 'key'), ['acme dental', 'twin']);
check('mock not a candidate', array_column($l['groups'][0]['customers'], 'id'), [2, 1]);
check('suggested = active', $l['groups'][0]['suggested_id'], 1);
check('touch shown', $l['groups'][0]['customers'][0]['last_outgrow_touch'], '2026-09-01');

check('bad: keep also hidden', (function () use ($pdo) { try { relationships_dup_keep($pdo, 1, [1], 'm'); } catch (InvalidArgumentException $e) { return 'threw'; } return 'ok'; })(), 'threw');

$r = relationships_dup_keep($pdo, 1, [2], 'mike');
check('hid one, moved services', $r, ['hidden' => 1, 'services_moved' => 2]);
check('row hidden', $pdo->query('SELECT is_duplicate_hidden||cw_bucket FROM customers WHERE id=2')->fetchColumn(), '1excluded');
check('services on keeper', (int) $pdo->query('SELECT COUNT(*) FROM customer_services WHERE customer_id=1')->fetchColumn(), 3);
check('group gone', array_column(relationships_dup_list($pdo)['groups'], 'key'), ['twin']);
check('hidden listed', relationships_dup_list($pdo)['hidden'][0]['keeper_name'], 'Acme Dental, LLC');
check('override cw id', $pdo->query('SELECT hidden_connectwise_id FROM customer_duplicate_overrides')->fetchColumn(), '11');

// matcher now resolves
$pdo->exec('DELETE FROM customers WHERE id=3');
$m = relationships_outgrow_find_customer($pdo, 'Acme Dental');
check('matcher single', [$m['status'], $m['customer']['id']], ['matched', 1]);

check('restore', relationships_dup_restore($pdo, 2), true);
check('restored bucket', $pdo->query('SELECT is_duplicate_hidden||cw_bucket FROM customers WHERE id=2')->fetchColumn(), '0prospect');
check('restore again false', relationships_dup_restore($pdo, 2), false);
check('group back', array_column(relationships_dup_list($pdo)['groups'], 'key'), ['acme dental', 'twin']);

$pdo->exec("INSERT INTO customer_duplicate_dismissals (group_key) VALUES ('twin')");
check('dismissed gone', array_column(relationships_dup_list($pdo)['groups'], 'key'), ['acme dental']);
exit($fails ? 1 : 0);
