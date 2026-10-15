#!/usr/bin/env python3
path = "api/itglue.php"
with open(path, "r", encoding="utf-8") as f:
    src = f.read()
orig = src

# 1) Add a reader for customer_services (real ConnectWise Agreement line items), right after
#    relationships_itglue_contact_status(), which has the same shape (PDO + customerId -> array).
old1 = """function relationships_itglue_contact_status(PDO $pdo, int $customerId): array
{
    $stmt = $pdo->prepare('SELECT contact_id, inactive, set_by_name, set_at FROM asset_contact_status WHERE customer_id = :c');
    $stmt->execute([':c' => $customerId]);
    return array_map(static fn (array $r): array => [
        'contact_id' => (int) $r['contact_id'], 'inactive' => (bool) $r['inactive'],
        'set_by_name' => (string) $r['set_by_name'], 'set_at' => (string) $r['set_at'],
    ], $stmt->fetchAll(PDO::FETCH_ASSOC));
}"""
assert src.count(old1) == 1, "old1 count=%d" % src.count(old1)
new1 = old1 + """

/**
 * This customer's real managed-service line items from their ConnectWise Agreement
 * (customer_services -- synced by connectwise-sync-core.php from the Agreement's Additions; see
 * customers.php's 'detail' action for the other consumer of this table). Passed through raw --
 * label + quantity only -- so the Managed Services column's device-type/coverage classification
 * (which services a specific person's computer actually gets) happens client-side in assets.js
 * (see SERVICE_FAMILIES there). This app never guesses at CW's product catalog server-side.
 */
function relationships_itglue_customer_services(PDO $pdo, int $customerId): array
{
    $stmt = $pdo->prepare('SELECT product_label, qty, unit FROM customer_services WHERE customer_id = :id ORDER BY product_label');
    $stmt->execute([':id' => $customerId]);
    return array_map(static function (array $r): array {
        $qty = (float) $r['qty'];
        return [
            'label' => (string) $r['product_label'],
            'qty' => $qty == (int) $qty ? (int) $qty : $qty,
            'unit' => (string) ($r['unit'] ?? ''),
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));
}"""
src = src.replace(old1, new1, 1)

# 2) Wire it into the assignments response.
old2 = """            'overrides' => relationships_itglue_overrides($pdo, (int) $cust['id']),
            'contact_status' => relationships_itglue_contact_status($pdo, (int) $cust['id']),
            'columns' => relationships_itglue_grid_columns($pdo),
        ]);"""
assert src.count(old2) == 1, "old2 count=%d" % src.count(old2)
new2 = """            'overrides' => relationships_itglue_overrides($pdo, (int) $cust['id']),
            'contact_status' => relationships_itglue_contact_status($pdo, (int) $cust['id']),
            'columns' => relationships_itglue_grid_columns($pdo),
            'customer_services' => relationships_itglue_customer_services($pdo, (int) $cust['id']),
        ]);"""
src = src.replace(old2, new2, 1)

assert src != orig
with open(path, "w", encoding="utf-8") as f:
    f.write(src)
print("itglue.php updated: assignments now includes real customer_services (ConnectWise Agreement line items)")
