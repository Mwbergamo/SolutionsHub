<?php
declare(strict_types=1);

/**
 * auth/local-user.php
 *
 * Bridges a signed-in Microsoft identity (email, name) to each app's own
 * local users table (crc_users, register_users) so existing foreign keys
 * that already reference those tables -- e.g. checklist_progress rows
 * attributing "who completed this step" to a crc_users.id, or a register
 * sale attributing "who rang this up" to a register_users.id -- keep
 * resolving to a valid id exactly as before, without a schema migration.
 * Used by auth/callback.php on every successful sign-in, and by nothing
 * else.
 */

/**
 * Finds the $table row for this email, or creates one. Returns its id.
 *
 * password_hash is a random value nobody knows, not a real password --
 * these tables no longer support password sign-in (see
 * relationships/api/auth.php and register/api/auth.php), but the column
 * is still NOT NULL, so this keeps a syntactically valid, cryptographically
 * useless placeholder in it rather than requiring an ALTER TABLE on a live
 * database.
 *
 * $table is only ever called with the two hardcoded literals below (never
 * user input), so the interpolation here is safe.
 */
function auth_upsert_local_user(PDO $pdo, string $table, string $email, string $name): int
{
    $stmt = $pdo->prepare("SELECT id FROM {$table} WHERE email = :email");
    $stmt->execute([':email' => $email]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row !== false) {
        // Keep the display name in sync with Entra's -- cheap UPDATE on
        // every sign-in, so a name change there (e.g. after marriage)
        // shows up here too instead of freezing at whatever it was on
        // first login.
        $upd = $pdo->prepare("UPDATE {$table} SET name = :name WHERE id = :id");
        $upd->execute([':name' => $name, ':id' => $row['id']]);
        return (int) $row['id'];
    }

    $placeholderHash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
    $ins = $pdo->prepare("INSERT INTO {$table} (name, email, password_hash) VALUES (:name, :email, :hash)");
    $ins->execute([':name' => $name, ':email' => $email, ':hash' => $placeholderHash]);
    return (int) $pdo->lastInsertId();
}
