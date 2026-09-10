<?php
/**
 * relationships/api/connectwise-contacts-sync-core.php
 *
 * Nightly-synced ConnectWise Company Contacts -- added 2026-09-10 per
 * Michael, for two things at once: (1) an "Active Contacts" count + trend
 * per customer on the front-page Primary Relationship Dashboard
 * (dashboard.php), and (2) letting the main customer search box match a
 * contact's first/last name or email and resolve to their company
 * (customers.php's list action).
 *
 * IMPORTANT / not yet verified against real data: this file's field
 * mapping for a ConnectWise Contact record -- specifically how email is
 * represented -- has NOT been confirmed against connect.codebluetechnology.com
 * (unreachable from the build environment, same limitation as every other
 * "not yet confirmed" note in this integration). ConnectWise's documented
 * Contact object does not have a plain top-level "email" field; email is
 * one of possibly several entries in a `communicationItems` array, each
 * shaped roughly like {type: {name: "Email"}, value: "..."}. This file
 * requests `communicationItems` and picks the first entry whose type name
 * contains "email" (case-insensitive). If that shape turns out to be
 * wrong, contacts would still sync (id/name always populate) but every
 * contact's email would come back empty -- a real, non-erroring, WRONG
 * result, the same failure class this integration has been burned by
 * several times today (see connectwise-activity.php's file header for the
 * board-name saga) -- so this needs checking against a real customer's
 * contacts before the email-search feature can be trusted, even though
 * contact NAME search would still work either way.
 *
 * inactiveFlag is requested but NOT used as a ConnectWise condition --
 * filtered in PHP after the fetch instead, same reasoning as the Prospect
 * sync's Vendor-type filter (connectwise-prospect-sync-core.php): this
 * integration has been burned enough times by an unverified condition on
 * a non-trivial field silently matching nothing that a boolean flag isn't
 * worth the same risk for the small cost of fetching a few extra rows.
 *
 * Same queue-based start()/step() shape as the Billing and Ticket History
 * syncs, keyed by customer. One /company/contacts call per customer.
 */

declare(strict_types=1);

require_once __DIR__ . '/connectwise.php';
require_once __DIR__ . '/connectwise-activity.php'; // relationships_cw_activity_billing_series_from_totals() -- shared trend math

/**
 * True if any of this contact's communicationItems looks like an email
 * type -- see file header for why this is a guess, not confirmed.
 */
function relationships_cw_contacts_extract_email(array $contact): ?string
{
    $items = $contact['communicationItems'] ?? [];
    if (!is_array($items)) {
        return null;
    }
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $typeName = is_array($item['type'] ?? null) ? (string) ($item['type']['name'] ?? '') : (string) ($item['type'] ?? '');
        if (stripos($typeName, 'email') !== false && !empty($item['value'])) {
            return (string) $item['value'];
        }
    }
    return null;
}

/**
 * Rebuilds the contacts sync queue: every real (non-mock), ConnectWise-
 * linked customer -- same rule as the Billing and Ticket History syncs.
 */
function relationships_cw_contacts_sync_start(PDO $pdo): array
{
    $pdo->exec('DELETE FROM cw_contacts_sync_queue');

    $rows = $pdo->query(
        "SELECT id, connectwise_id, name FROM customers
         WHERE is_mock = 0 AND connectwise_id IS NOT NULL AND connectwise_id != ''
           AND connectwise_id NOT LIKE 'MOCK-%'"
    )->fetchAll(PDO::FETCH_ASSOC);

    $insert = $pdo->prepare(
        'INSERT INTO cw_contacts_sync_queue (customer_id, connectwise_id, company_name, status)
         VALUES (:id, :cwid, :name, \'pending\')'
    );
    foreach ($rows as $r) {
        $insert->execute([':id' => $r['id'], ':cwid' => $r['connectwise_id'], ':name' => $r['name']]);
    }

    $total = count($rows);
    $pdo->prepare('INSERT OR REPLACE INTO cw_sync_meta (key, value) VALUES (\'contacts_started_at\', :v)')
        ->execute([':v' => date('c')]);

    return ['total' => $total];
}

/**
 * Processes up to $batchSize pending queue rows: fetches each customer's
 * ConnectWise contacts, keeps only active ones (inactiveFlag filtered in
 * PHP -- see file header), replaces that customer's `contacts` rows,
 * updates customers.active_contact_count, and upserts this month's count
 * into customer_contact_count_history for the trend. One customer's
 * failure is caught and recorded on its own queue row rather than
 * aborting the batch.
 */
function relationships_cw_contacts_sync_step(PDO $pdo, int $batchSize = 20): array
{
    $pending = $pdo->prepare('SELECT * FROM cw_contacts_sync_queue WHERE status = \'pending\' ORDER BY customer_id LIMIT :n');
    $pending->bindValue(':n', $batchSize, PDO::PARAM_INT);
    $pending->execute();
    $rows = $pending->fetchAll(PDO::FETCH_ASSOC);

    $deleteExisting = $pdo->prepare('DELETE FROM contacts WHERE customer_id = :id');
    $insertContact = $pdo->prepare(
        'INSERT INTO contacts (customer_id, connectwise_contact_id, first_name, last_name, email)
         VALUES (:customer_id, :cwid, :first, :last, :email)'
    );
    $updateCount = $pdo->prepare('UPDATE customers SET active_contact_count = :n WHERE id = :id');
    $upsertHistory = $pdo->prepare(
        'INSERT INTO customer_contact_count_history (customer_id, month, count, synced_at)
         VALUES (:cid, :month, :count, datetime(\'now\'))
         ON CONFLICT(customer_id, month) DO UPDATE SET count = excluded.count, synced_at = excluded.synced_at'
    );
    $thisMonth = (new DateTimeImmutable('now'))->format('Y-m');

    $processed = 0;
    $errors = [];
    foreach ($rows as $row) {
        $customerId = (int) $row['customer_id'];
        try {
            $contacts = relationships_cw_list(
                '/company/contacts',
                "company/id=" . (string) $row['connectwise_id'],
                ['id', 'firstName', 'lastName', 'communicationItems', 'inactiveFlag'],
                200
            );

            $active = array_filter($contacts, static fn (array $c): bool => empty($c['inactiveFlag']));

            $deleteExisting->execute([':id' => $customerId]);
            foreach ($active as $c) {
                if (!isset($c['id'])) {
                    continue;
                }
                $insertContact->execute([
                    ':customer_id' => $customerId,
                    ':cwid' => (string) $c['id'],
                    ':first' => (string) ($c['firstName'] ?? ''),
                    ':last' => (string) ($c['lastName'] ?? ''),
                    ':email' => relationships_cw_contacts_extract_email($c),
                ]);
            }

            $count = count($active);
            $updateCount->execute([':n' => $count, ':id' => $customerId]);
            $upsertHistory->execute([':cid' => $customerId, ':month' => $thisMonth, ':count' => $count]);

            $pdo->prepare('UPDATE cw_contacts_sync_queue SET status = \'done\', processed_at = datetime(\'now\'), error_message = NULL WHERE customer_id = :id')
                ->execute([':id' => $customerId]);
        } catch (Throwable $e) {
            $pdo->prepare('UPDATE cw_contacts_sync_queue SET status = \'error\', processed_at = datetime(\'now\'), error_message = :msg WHERE customer_id = :id')
                ->execute([':id' => $customerId, ':msg' => substr($e->getMessage(), 0, 500)]);
            $errors[] = ['customer_id' => $customerId, 'company_name' => $row['company_name'], 'error' => $e->getMessage()];
        }
        $processed++;
    }

    $counts = $pdo->query('SELECT status, COUNT(*) AS n FROM cw_contacts_sync_queue GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
    $remaining = (int) ($counts['pending'] ?? 0);

    return [
        'processed_this_batch' => $processed,
        'remaining' => $remaining,
        'done' => $remaining === 0,
        'totals' => [
            'pending' => (int) ($counts['pending'] ?? 0),
            'done' => (int) ($counts['done'] ?? 0),
            'error' => (int) ($counts['error'] ?? 0),
        ],
        'errors' => $errors,
    ];
}

/**
 * This customer's contact-count trend from stored history. Contact
 * tracking starts from this build's first sync run (per Michael, "start
 * tracking now" rather than trying to reconstruct history from contacts'
 * own add/inactivate dates) -- there is no real prior-period data for the
 * first several months, so the shared trend helper's zero-fill kicks in
 * for those months, which is exactly why it already returns percent: null
 * (rather than a misleading spike) whenever the prior-3-month average is
 * 0 -- see relationships_cw_activity_billing_series_from_totals(). The
 * front end shows that as "not enough history yet" instead of a percent.
 */
function relationships_cw_contacts_trend(PDO $pdo, int $customerId): array
{
    $stmt = $pdo->prepare('SELECT month, count FROM customer_contact_count_history WHERE customer_id = :id');
    $stmt->execute([':id' => $customerId]);
    $byMonth = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    return relationships_cw_activity_billing_series_from_totals($byMonth, 6)['trend'];
}
