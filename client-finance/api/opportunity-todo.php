<?php
/**
 * client-finance/api/opportunity-todo.php
 *
 * POST ?action=create   body { opportunity_id: N }
 *   Pushes one flagged Opportunity row into Relationships as a general
 *   to-do ("New Opportunity - Proactive Upgrade"), per Michael's original
 *   spec. Mirrors relationships_itglue_create_todo()'s body (local save
 *   first, unconditionally; then try a ConnectWise Activity; a
 *   ConnectWise failure never blocks or reverts the local save) rather
 *   than require_once'ing relationships/api/itglue.php directly --
 *   itglue.php runs its own top-level request dispatch at file scope
 *   (same reason itglue.php itself keeps local copies of a couple of
 *   relationships/api/meetings.php's helpers instead of requiring that
 *   file). The pieces this DOES reuse (relationships_todo_roster(),
 *   relationships_cw_create_general_todo_activity()) live in files that
 *   are pure function definitions, safe to require_once.
 *
 * Marks the opportunity 'actioned' with who/when and the resulting
 * general_todos.id, so the Opportunity list can show "Already sent to
 * Relationships" instead of offering the button again.
 */

declare(strict_types=1);

require_once __DIR__ . '/_util.php';
require_once __DIR__ . '/../../relationships/api/db.php';
require_once __DIR__ . '/../../relationships/api/catalog.php';
require_once __DIR__ . '/../../relationships/api/connectwise-meeting-activity.php';

$user = client_finance_require_access();
$pdo = client_finance_db();
$action = $_GET['action'] ?? '';

function client_finance_relationships_user_by_email(PDO $rpdo, string $email): ?array
{
    $stmt = $rpdo->prepare('SELECT id, name FROM crc_users WHERE LOWER(email) = LOWER(:e) LIMIT 1');
    $stmt->execute([':e' => $email]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row !== false ? ['id' => (int) $row['id'], 'name' => (string) $row['name']] : null;
}

if ($action === 'create') {
    client_finance_require_post();
    $body = client_finance_read_json_body();
    $oppId = (int) ($body['opportunity_id'] ?? 0);
    if ($oppId <= 0) {
        client_finance_respond(400, ['ok' => false, 'error' => 'Missing opportunity_id.']);
    }

    $stmt = $pdo->prepare(
        'SELECT o.id, o.cw_company_id, o.category, o.subcategory, o.sold_date, o.years_ago, o.status,
                il.description, il.product_identifier, i.invoice_number
         FROM opportunities o
         JOIN invoice_lines il ON il.id = o.invoice_line_id
         JOIN invoices i ON i.id = il.invoice_id
         WHERE o.id = :id'
    );
    $stmt->execute([':id' => $oppId]);
    $opp = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($opp === false) {
        client_finance_respond(404, ['ok' => false, 'error' => 'Opportunity not found.']);
    }
    if ($opp['status'] !== 'open') {
        client_finance_respond(409, ['ok' => false, 'error' => 'This opportunity was already actioned.']);
    }

    $rpdo = relationships_db();
    $custStmt = $rpdo->prepare('SELECT id, name FROM customers WHERE connectwise_id = :cw LIMIT 1');
    $custStmt->execute([':cw' => (string) $opp['cw_company_id']]);
    $cust = $custStmt->fetch(PDO::FETCH_ASSOC);
    if ($cust === false) {
        client_finance_respond(404, ['ok' => false, 'error' => 'That customer was not found in Relationships.']);
    }
    $customerId = (int) $cust['id'];

    $roster = relationships_todo_roster();
    $me = client_finance_relationships_user_by_email($rpdo, $user['email']);
    $assignedToName = ($me !== null && in_array($me['name'], $roster, true)) ? $me['name']
        : (in_array($user['name'], $roster, true) ? $user['name'] : $roster[0]);
    $createdByName = $me['name'] ?? $user['name'];

    $host = $_SERVER['HTTP_HOST'] ?? 'portal.codebluetechnology.com';
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $link = "$scheme://$host/client-finance/?cw_company_id=" . (int) $opp['cw_company_id'];

    $categoryLabel = $opp['category'] === 'computer' ? 'Computer' : 'Networking';
    $description = sprintf(
        'New Opportunity - Proactive Upgrade: %s %s (%s) sold on invoice %s, ~%.1f years ago. %s',
        $categoryLabel,
        (string) $opp['subcategory'],
        (string) $opp['description'],
        (string) $opp['invoice_number'],
        (float) $opp['years_ago'],
        $link
    );
    if (mb_strlen($description) > 500) {
        $description = mb_substr($description, 0, 497) . '...';
    }

    $insert = $rpdo->prepare(
        'INSERT INTO general_todos (customer_id, description, assigned_to_user_id, assigned_to_name, created_by_user_id, created_by_name, due_date)
         VALUES (:cid, :desc, :auid, :aname, :cuid, :cname, NULL)'
    );
    $insert->execute([
        ':cid' => $customerId, ':desc' => $description,
        ':auid' => $me['id'] ?? null, ':aname' => $assignedToName,
        ':cuid' => $me['id'] ?? null, ':cname' => $createdByName,
    ]);
    $todoId = (int) $rpdo->lastInsertId();

    $cwCompanyIdStr = (string) $opp['cw_company_id'];
    try {
        $nowEastern = new DateTimeImmutable('now', new DateTimeZone('America/New_York'));
        $result = relationships_cw_create_general_todo_activity($rpdo, [
            'customer_id' => $customerId,
            'cw_company_id' => $cwCompanyIdStr,
            'description' => $description,
            'assigned_to_name' => $assignedToName,
            'assigned_to_email' => relationships_todo_roster_cw_email($assignedToName),
            'created_by_name' => $createdByName,
            'created_at_display' => $nowEastern->format('M j, Y g:i A T'),
            'due_date' => null,
        ]);
        $rpdo->prepare('UPDATE general_todos SET cw_activity_id = :aid, cw_push_status = :s WHERE id = :id')
            ->execute([':aid' => $result['id'], ':s' => 'pushed', ':id' => $todoId]);
    } catch (Throwable $e) {
        $rpdo->prepare('UPDATE general_todos SET cw_push_status = :s, cw_push_error = :err WHERE id = :id')
            ->execute([':s' => 'error', ':err' => substr($e->getMessage(), 0, 4000), ':id' => $todoId]);
    }

    $pdo->prepare(
        "UPDATE opportunities SET status = 'actioned', actioned_at = :t, actioned_by_name = :n, relationships_todo_id = :tid WHERE id = :id"
    )->execute([':t' => gmdate('c'), ':n' => $user['name'], ':tid' => $todoId, ':id' => $oppId]);

    client_finance_respond(200, ['ok' => true, 'relationships_todo_id' => $todoId, 'assigned_to_name' => $assignedToName]);
}

client_finance_respond(400, ['ok' => false, 'error' => 'Unknown action.']);
