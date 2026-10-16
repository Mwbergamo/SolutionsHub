<?php
/**
 * client-finance/api/customers.php
 *
 * Customer type-ahead search for the Client Finance picker, per Michael:
 * "We need a the ability to search clients by typing them, just like in
 * Relationships." Deliberately reuses relationships/ for identity rather
 * than keeping its own customer list (see client-finance/api/db.php's
 * header) -- this endpoint is read-only against relationships.sqlite and
 * writes nothing there.
 *
 * GET /client-finance/api/customers.php?action=search&q=search+text
 *   -> { ok: true, customers: [{ cw_company_id, name, is_peoplefirst,
 *                                 is_prospect_only, is_residential,
 *                                 matched_contact_name }, ...] }
 *
 * Only customers with a numeric ConnectWise company id (customers.
 * connectwise_id) can appear -- financials are pulled live by that id, so
 * a customer relationships/ hasn't synced a real ConnectWise company for
 * yet has nothing here to show. Empty q returns the first 200 alphabetically
 * (browse mode, same as relationships' own picker); non-empty q matches the
 * company name OR a synced contact's first/last name/email, same two-query
 * approach as relationships/api/customers.php's 'list' action -- see that
 * file's header for why contact-matching is local-data-only.
 *
 * No territory filtering here: Client Finance's access list (currently
 * Kasie Van Fossen, Michael Bergamo, Trey Hayden, Jaclyn Kelley) sees every
 * customer, unlike Relationships' per-rep territory scoping.
 */

declare(strict_types=1);

require_once __DIR__ . '/_util.php';
require_once __DIR__ . '/../../relationships/api/db.php';

client_finance_require_access();

$action = $_GET['action'] ?? '';

if ($action === 'search') {
    $rpdo = relationships_db();
    $q = trim((string) ($_GET['q'] ?? ''));
    $matchedContact = []; // customer id => "First Last" of the contact that matched

    $hasCwId = "connectwise_id IS NOT NULL AND TRIM(connectwise_id) <> '' AND connectwise_id GLOB '[0-9]*'";

    if ($q === '') {
        $stmt = $rpdo->prepare(
            "SELECT id, connectwise_id, name, is_peoplefirst, is_prospect_only, is_residential
             FROM customers
             WHERE COALESCE(is_duplicate_hidden, 0) = 0 AND $hasCwId
             ORDER BY name ASC LIMIT 200"
        );
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $like = '%' . $q . '%';

        $nameStmt = $rpdo->prepare(
            "SELECT id, connectwise_id, name, is_peoplefirst, is_prospect_only, is_residential
             FROM customers
             WHERE COALESCE(is_duplicate_hidden, 0) = 0 AND $hasCwId AND name LIKE :q
             ORDER BY name ASC LIMIT 50"
        );
        $nameStmt->execute([':q' => $like]);
        $nameRows = $nameStmt->fetchAll(PDO::FETCH_ASSOC);

        $contactStmt = $rpdo->prepare(
            "SELECT c.id, c.connectwise_id, c.name, c.is_peoplefirst, c.is_prospect_only, c.is_residential,
                    ct.first_name AS matched_first_name, ct.last_name AS matched_last_name
             FROM contacts ct
             JOIN customers c ON c.id = ct.customer_id
             WHERE COALESCE(c.is_duplicate_hidden, 0) = 0 AND $hasCwId
               AND (ct.first_name LIKE :q1 OR ct.last_name LIKE :q2 OR ct.email LIKE :q3
                    OR (ct.first_name || ' ' || ct.last_name) LIKE :q4)
             ORDER BY c.name ASC LIMIT 50"
        );
        $contactStmt->execute([':q1' => $like, ':q2' => $like, ':q3' => $like, ':q4' => $like]);
        $contactRows = $contactStmt->fetchAll(PDO::FETCH_ASSOC);

        $rows = [];
        foreach ($nameRows as $r) {
            $rows[(int) $r['id']] = $r;
        }
        foreach ($contactRows as $r) {
            $id = (int) $r['id'];
            if (!isset($rows[$id])) {
                $rows[$id] = $r;
            }
            if (!isset($matchedContact[$id])) {
                $matchedContact[$id] = trim($r['matched_first_name'] . ' ' . $r['matched_last_name']);
            }
        }
        $rows = array_values($rows);
    }

    $customers = array_map(
        static function (array $r) use ($matchedContact): array {
            $id = (int) $r['id'];
            return [
                'cw_company_id' => (int) $r['connectwise_id'],
                'name' => $r['name'],
                'is_peoplefirst' => (bool) $r['is_peoplefirst'],
                'is_prospect_only' => (bool) $r['is_prospect_only'],
                'is_residential' => (bool) $r['is_residential'],
                'matched_contact_name' => $matchedContact[$id] ?? null,
            ];
        },
        $rows
    );
    client_finance_respond(200, ['ok' => true, 'customers' => $customers]);
}

client_finance_respond(400, ['ok' => false, 'error' => 'Unknown action.']);
