<?php
/**
 * register/api/tax-core.php
 *
 * Pure ConnectWise Tax Code helpers -- no login gate, no action dispatch,
 * safe to require from any other register/api/*.php file (same "-core.php
 * holds shared functions only" convention this codebase already uses for
 * connectwise-activity.php, connectwise-billing-sync-core.php, etc.). The
 * actual GET/POST endpoints (action=list, action=sync) live in tax.php,
 * which requires this file.
 *
 * See tax.php's own docblock for the full research trail confirming these
 * field names/shapes against real ConnectWise data (2026-09-16).
 */

declare(strict_types=1);

/**
 * Sum of a ConnectWise Tax Code's six rate levels -- ConnectWise splits a
 * combined state+local rate (e.g. VA-Hamp, VA-NOVA) across two of these six
 * "levels"; summing all six is what matches ConnectWise's own Tax Code
 * screen's "Total Tax Rate" column (confirmed against Michael's screenshot).
 */
function register_tax_code_total_rate(array $row): float
{
    $total = 0.0;
    foreach (['levelOneRate', 'levelTwoRate', 'levelThreeRate', 'levelFourRate', 'levelFiveRate', 'levelSixRate'] as $key) {
        $total += (float) ($row[$key] ?? 0);
    }
    return $total;
}

/**
 * Fetches every active (non-cancelled) Tax Code from ConnectWise and
 * replaces the local tax_codes table. Confirmed against real data: 6 rows
 * come back total, 5 have no cancelDate (the active set Michael's
 * screenshot shows) and 1 is a superseded old "VA" code -- see tax.php.
 */
function register_sync_tax_codes(PDO $pdo): array
{
    $rows = register_cw_request('/finance/taxCodes', ['pageSize' => '100', 'page' => '1'], 'GET', null, 20, 6);

    $active = array_values(array_filter($rows, static fn (array $r): bool => empty($r['cancelDate'])));

    $pdo->beginTransaction();
    try {
        $pdo->exec('DELETE FROM tax_codes');
        $insert = $pdo->prepare(
            'INSERT INTO tax_codes (id, identifier, name, rate, is_default, synced_at)
             VALUES (:id, :identifier, :name, :rate, :is_default, datetime(\'now\'))'
        );
        foreach ($active as $r) {
            $insert->execute([
                ':id' => (int) $r['id'],
                ':identifier' => (string) ($r['identifier'] ?? ''),
                ':name' => (string) ($r['description'] ?? ''),
                ':rate' => register_tax_code_total_rate($r),
                ':is_default' => !empty($r['defaultFlag']) ? 1 : 0,
            ]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return register_list_tax_codes($pdo);
}

function register_list_tax_codes(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT id, identifier, name, rate, is_default FROM tax_codes ORDER BY is_default DESC, name ASC');
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['id'] = (int) $r['id'];
        $r['rate'] = (float) $r['rate'];
        $r['is_default'] = (int) $r['is_default'] === 1;
    }
    unset($r);
    return $rows;
}

function register_tax_code_by_id(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT id, identifier, name, rate, is_default FROM tax_codes WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        return null;
    }
    $row['id'] = (int) $row['id'];
    $row['rate'] = (float) $row['rate'];
    $row['is_default'] = (int) $row['is_default'] === 1;
    return $row;
}

/**
 * The register's own default tax code for a brand-new customer -- the
 * ConnectWise-flagged default (confirmed: id 15, identifier "VA",
 * "State-New", 6%). Returns null if tax codes haven't been synced yet --
 * callers treat that as "could not resolve a tax code" and flag it rather
 * than guessing/hardcoding an id.
 */
function register_default_tax_code(PDO $pdo): ?array
{
    $stmt = $pdo->query('SELECT id, identifier, name, rate, is_default FROM tax_codes WHERE is_default = 1 LIMIT 1');
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        return null;
    }
    $row['id'] = (int) $row['id'];
    $row['rate'] = (float) $row['rate'];
    $row['is_default'] = true;
    return $row;
}

/**
 * The "Tax Exempt" code (confirmed: id 1, identifier "Exem", rate 0%) --
 * matched by identifier rather than a hardcoded id, so a future re-sync
 * that somehow renumbers ConnectWise's ids still resolves correctly.
 */
function register_exempt_tax_code(PDO $pdo): ?array
{
    $stmt = $pdo->prepare('SELECT id, identifier, name, rate, is_default FROM tax_codes WHERE identifier = :identifier LIMIT 1');
    $stmt->execute([':identifier' => 'Exem']);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        return null;
    }
    $row['id'] = (int) $row['id'];
    $row['rate'] = (float) $row['rate'];
    $row['is_default'] = (int) $row['is_default'] === 1;
    return $row;
}

/**
 * Live-reads a company's currently-assigned ConnectWise Tax Code -- used by
 * both customers.php's action=company-tax (so checkout's customer panel can
 * show it) and checkout.php's action=create (so the sale's tax is computed
 * from what ConnectWise says RIGHT NOW, never trusted from the client). No
 * `fields` restriction -- same shape already proven safe by register_cw_
 * finalize_company_invoicing()'s fetch and the tax-code probe (confirmed
 * `taxCode: {id, name}` is a normal top-level field on the full default
 * record). Returns just the raw ConnectWise id/name; callers join against
 * the local tax_codes table for the identifier/rate. Requires
 * connectwise.php to already be loaded (register_cw_request()).
 */
function register_cw_company_tax_code_id(int $companyId): ?array
{
    $full = register_cw_request('/company/companies/' . $companyId, [], 'GET', null, 12, 4);
    $taxCode = $full['taxCode'] ?? null;
    if (!is_array($taxCode) || !isset($taxCode['id'])) {
        return null;
    }
    return ['id' => (int) $taxCode['id'], 'name' => (string) ($taxCode['name'] ?? '')];
}
