<?php
declare(strict_types=1);
chdir(__DIR__);
require_once __DIR__ . '/api/db.php';
require_once __DIR__ . '/api/sync-core.php';

// Raw time entries for ticket 931457 (Eastern Henrico FISH), to see what fields ConnectWise
// actually carries -- specifically looking for anything that distinguishes a Project ticket's
// time entry from a Service ticket's (workType name, chargeToType, ticket recordType, etc.)
// per Michael's 2026-10-15 note that Project tickets bill at raw/actual hours, no 30-min bracket.
try {
    $rows = relationships_cw_request('/time/entries', [
        'conditions' => 'chargeToId=931457',
        'pageSize' => '50',
    ]);
    echo "Row count: " . count($rows) . "\n\n";
    foreach ($rows as $r) {
        echo json_encode($r, JSON_PRETTY_PRINT) . "\n---\n";
    }
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

// Also fetch the ticket itself to see its own fields (recordType, project, board...).
echo "\n\n=== TICKET 931457 ===\n";
try {
    $ticket = relationships_cw_request('/service/tickets', ['conditions' => 'id=931457']);
    echo json_encode($ticket, JSON_PRETTY_PRINT) . "\n";
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
