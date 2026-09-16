<?php
/**
 * relationships/api/connectwise-territory-sync-core.php
 *
 * Rep-based territory filtering -- added 2026-09-16 per Michael. Pulls
 * ConnectWise Company Territory (a related /system/locations record, e.g.
 * "House accounts" -- see register/api/customers.php's research trail on
 * this same field) onto customers.territory_name, so relationships/api's
 * list endpoints can restrict a customer list to a signed-in rep's
 * assigned territories (see territory-access.php).
 *
 * Deliberately UNFILTERED, unlike connectwise-prospect-sync-core.php's
 * list call: the prospect sync only needs companies matching Michael's
 * "Active/Delinquent/Special Info, not Vendor" saved view, but this sync
 * needs to tag EVERY company ConnectWise knows about with its territory --
 * a real customer with, say, a "Closed" status that the prospect filter
 * would correctly skip still needs its territory_name set here, or a
 * restricted rep would silently never see it. Narrowing this filter later
 * without re-checking that reasoning risks recreating exactly the kind of
 * silent-gap bug this integration has been burned by before (see the
 * prospect sync's file header on related/array-field conditions).
 *
 * Same queue-based start()/step() shape as every other sync in this app,
 * for the same Bluehost execution-time-limit reason. Like the prospect
 * sync (and unlike the agreement/billing syncs), start() already has
 * everything needed (id, name, territory) from the one /company/companies
 * list call, so step() is pure DB work -- no further ConnectWise
 * round-trip -- and can safely use a larger batch size.
 *
 * This sync intentionally does NOT create customer rows -- it only sets
 * territory_name on customers that already exist (from the agreement,
 * billing, or prospect syncs). A ConnectWise company with no matching
 * `customers` row yet (nothing has synced it in for any other reason) is
 * simply skipped; there's nothing here for a territory to attach to, and
 * creating a bare row would risk it showing up as a phantom "customer"
 * with no services, no agreements, nothing -- that's what the prospect
 * sync's own filter is for.
 */

declare(strict_types=1);

require_once __DIR__ . '/connectwise.php';

/**
 * Rebuilds the territory queue from scratch: every ConnectWise Company,
 * unfiltered (see file header), with its id/name/territory. Unlike the
 * prospect sync, this does NOT reset any customers.territory_name column
 * up front -- a customer whose territory was removed in ConnectWise simply
 * gets NULL written back to it during step() (see below), same
 * reset-during-processing behavior, just without a separate blanket
 * UPDATE first since every row gets revisited every run anyway.
 */
function relationships_cw_territory_sync_start(PDO $pdo): array
{
    $pdo->exec('DELETE FROM cw_territory_sync_queue');

    $companies = relationships_cw_list(
        '/company/companies',
        '', // deliberately unconditioned -- see file header
        ['id', 'name', 'territory'],
        200
    );

    $insert = $pdo->prepare(
        'INSERT OR REPLACE INTO cw_territory_sync_queue (connectwise_id, company_name, territory_name, status)
         VALUES (:cwid, :name, :territory, \'pending\')'
    );

    $total = 0;
    foreach ($companies as $c) {
        if (!isset($c['id'], $c['name'])) {
            continue;
        }
        $territoryName = null;
        if (isset($c['territory']) && is_array($c['territory'])) {
            $territoryName = isset($c['territory']['name']) ? (string) $c['territory']['name'] : null;
        }
        $insert->execute([
            ':cwid' => (string) $c['id'],
            ':name' => (string) $c['name'],
            ':territory' => $territoryName,
        ]);
        $total++;
    }

    $pdo->prepare('INSERT OR REPLACE INTO cw_sync_meta (key, value) VALUES (\'territory_started_at\', :v)')
        ->execute([':v' => date('c')]);
    $pdo->prepare('INSERT OR REPLACE INTO cw_sync_meta (key, value) VALUES (\'territory_total_queued\', :v)')
        ->execute([':v' => (string) $total]);

    return ['total' => $total];
}

/**
 * Processes up to $batchSize pending queue rows. No ConnectWise
 * round-trip per row -- start() already has everything -- so this is
 * pure DB work: for each queued company that has a matching `customers`
 * row (by connectwise_id), write its territory_name (which may be NULL,
 * clearing a stale value). Companies with no matching customer row are
 * marked done and skipped (see file header).
 */
function relationships_cw_territory_sync_step(PDO $pdo, int $batchSize = 100): array
{
    $pending = $pdo->prepare('SELECT * FROM cw_territory_sync_queue WHERE status = \'pending\' ORDER BY connectwise_id LIMIT :n');
    $pending->bindValue(':n', $batchSize, PDO::PARAM_INT);
    $pending->execute();
    $rows = $pending->fetchAll(PDO::FETCH_ASSOC);

    $updateCustomer = $pdo->prepare('UPDATE customers SET territory_name = :territory WHERE connectwise_id = :cw');

    $processed = 0;
    $errors = [];
    foreach ($rows as $row) {
        $cwId = (string) $row['connectwise_id'];
        try {
            $updateCustomer->execute([
                ':territory' => $row['territory_name'],
                ':cw' => $cwId,
            ]);

            $pdo->prepare('UPDATE cw_territory_sync_queue SET status = \'done\', processed_at = datetime(\'now\'), error_message = NULL WHERE connectwise_id = :cw')
                ->execute([':cw' => $cwId]);
        } catch (Throwable $e) {
            $pdo->prepare('UPDATE cw_territory_sync_queue SET status = \'error\', processed_at = datetime(\'now\'), error_message = :msg WHERE connectwise_id = :cw')
                ->execute([':cw' => $cwId, ':msg' => substr($e->getMessage(), 0, 500)]);
            $errors[] = ['connectwise_id' => $cwId, 'company_name' => $row['company_name'], 'error' => $e->getMessage()];
        }
        $processed++;
    }

    $counts = $pdo->query('SELECT status, COUNT(*) AS n FROM cw_territory_sync_queue GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
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
 * Distinct, non-empty territory_name values currently on `customers`, i.e.
 * what the LAST completed territory sync actually saw in ConnectWise.
 * Used by territory-admin.php to give Michael a picker of real values
 * instead of a free-text field he could typo (see territory-access.php's
 * file header on why a typo here is a silent, symptom-free failure).
 */
function relationships_cw_synced_territory_names(PDO $pdo): array
{
    $rows = $pdo->query(
        "SELECT DISTINCT territory_name FROM customers WHERE territory_name IS NOT NULL AND territory_name != '' ORDER BY territory_name COLLATE NOCASE"
    )->fetchAll(PDO::FETCH_COLUMN);
    return array_values($rows);
}
