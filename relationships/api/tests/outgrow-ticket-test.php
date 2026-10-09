<?php
// CLI-only: php relationships/api/tests/outgrow-ticket-test.php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../outgrow-ticket-parse.php';

$fails = 0;
function check(string $label, $got, $want): void {
    global $fails;
    if ($got !== $want) { $fails++; echo "FAIL $label\n  got:  " . var_export($got, true) . "\n  want: " . var_export($want, true) . "\n"; }
    else echo "ok   $label\n";
}

$action = "Fri 10/9/2026/8:10 AM EDT/ Chuck Fleet (time)-\nGood morning Robin, yes even if the scanner is shipped here to CBT HQ we can then ship it to you. As far as installation if you can physically install it then we can remotely set it up for you. We'll get this over to our sales team to quote a scanner.";
$tail = "Thu 10/8/2026/8:49 PM EDT/ Robin LaPointe\nNeed to get a small scanner like you buy for front desk.  Can it be shipped to me?  Will you be able to install remotely?\n\nRobin\nNoencrypt:\n\nRobin LaPointe, Administrative Support Manager\nrlapointe@elevatedermrva.com\n\nGeneral & Cosmetic Dermatology\n7813 Shrader Road, Henrico, VA  23294";

// Format A: label and value on one line (tab separated, as copied from Outlook)
$a = "Full ticket details\nTicket #953911\nTicket:\tRemote - New Install - Need a small scanner like the front desks have\nStatus:\tNew\n\nCompany:\tChristine S Rausch MD PC\nContact:\tRobin LaPointe\nPhone:\t302-897-3990\nAddress:\t2510 Gaskins Road\nHenrico, VA 23238\nDiscussion:\n$action\n$tail\n";
// Format B: label and value on separate lines
$b = "Ticket #953911\nCompany:\nChristine S Rausch MD PC\nContact:\nRobin LaPointe\nPhone:\n302-897-3990\nDiscussion:\n$action\n$tail\n";

foreach (['tab-separated' => $a, 'label-then-value lines' => $b] as $name => $text) {
    $r = relationships_outgrow_parse_ticket($text);
    check("$name: ok", $r['ok'], true);
    $v = $r['values'];
    check("$name: email", $v['email'], 'cfleet@codebluetechnology.com');
    check("$name: name", $v['name'], 'Chuck Fleet');
    check("$name: company", $v['company'], 'Christine S Rausch MD PC');
    check("$name: contact", $v['contact'], 'Robin LaPointe');
    check("$name: actions", $v['actions'], $action);
    check("$name: ticket", $v['ticket'], '953911');
    check("$name: call type", $v['call_type'], 'NOT a call');
}

// Customer-first ordering: the customer's note is on top, ours below -> ours is chosen.
$c = "Company: Acme\nContact: Robin LaPointe\nDiscussion:\n$tail\n$action\n";
$r = relationships_outgrow_parse_ticket($c);
check('skips contact-authored entry', $r['values']['name'], 'Chuck Fleet');

// Nothing parseable
check('no entry -> error', relationships_outgrow_parse_ticket("hello world")['ok'], false);

// URL
$u = relationships_outgrow_form_url(relationships_outgrow_parse_ticket($a)['values']);
parse_str((string) parse_url($u, PHP_URL_QUERY), $q);
check('url field406', $q['field190744406'], 'Christine S Rausch MD PC');
check('url field412', $q['field190744412'], 'NOT a call');
check('url field408', $q['field190744408'], $action);
check('dyk warning present', (bool) array_filter(relationships_outgrow_parse_ticket($a)['warnings'], fn($w) => str_contains($w, 'DYK')), true);

exit($fails ? 1 : 0);
