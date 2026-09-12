<?php
/**
 * relationships/api/cw-catalog-probe.php
 *
 * TEMPORARY, READ-ONLY diagnostic for the new Register app's catalog sync
 * (SolutionsHub task tracker #40-#44). We've never touched ConnectWise's
 * Procurement Catalog module before, and this project's hard-won lesson
 * from the Activity-creation bug (2026-09-11) is: don't guess field/endpoint
 * names from docs or general ConnectWise knowledge -- fetch real data (or a
 * real error naming what's wrong) and go from that.
 *
 * Round 1 (fields=none) showed the base /procurement/catalog fields have NO
 * on-hand-quantity column at all -- not even on id=743 ("Patch Cable"),
 * the one productClass="Inventory" item in the first page. ConnectWise's
 * "On Hand" View Editor column is very likely computed from a separate
 * warehouse/inventory tracking resource, not a plain catalog field. Rather
 * than guess that sub-resource's path one redeploy at a time, round 2 fires
 * several safe, read-only candidate GETs at once and reports every outcome
 * (success or the exact ConnectWise error) so real ground truth comes back
 * in a single round trip:
 *
 *   1. GET /procurement/catalog/743            -- does a single-item GET
 *      expose fields the list GET (round 1) omitted?
 *   2. GET /procurement/catalog?conditions=id=743&fields=onHand
 *   3. GET /procurement/catalog?conditions=id=743&fields=quantityOnHand
 *   4. GET /procurement/catalog/743/inventory   -- possible sub-resource
 *   5. GET /procurement/warehouses?pageSize=3   -- is the warehouse module
 *      reachable at all in this instance?
 *
 * GET /relationships/api/cw-catalog-probe.php
 *   -> { ok: true, attempts: [ { label, ok, status_or_error, rows|null }, ... ] }
 *
 * Gated behind relationships_require_login() like every other endpoint in
 * this app -- not public. DELETE THIS FILE once the real on-hand-quantity
 * field/endpoint is confirmed and task #42's real sync logic is written
 * against it (same lifecycle as the now-removed checklist.php?action=cw_date_probe).
 */

declare(strict_types=1);

require_once __DIR__ . '/_util.php';
require_once __DIR__ . '/connectwise.php';

$pdo = relationships_db();
relationships_require_login($pdo);

/**
 * Runs one candidate GET and captures success or the exact ConnectWise
 * error, never throwing -- so one bad guess doesn't stop the rest of the
 * candidates from running in this same pass.
 */
function relationships_cw_probe_attempt(string $label, string $path, array $query = []): array
{
    try {
        $result = relationships_cw_request($path, $query);
        return ['label' => $label, 'ok' => true, 'result' => $result];
    } catch (Throwable $e) {
        return ['label' => $label, 'ok' => false, 'error' => $e->getMessage()];
    }
}

$attempts = [
    relationships_cw_probe_attempt('single item GET /procurement/catalog/743', '/procurement/catalog/743'),
    relationships_cw_probe_attempt(
        'list GET with fields=onHand',
        '/procurement/catalog',
        ['conditions' => 'id=743', 'fields' => 'id,identifier,onHand']
    ),
    relationships_cw_probe_attempt(
        'list GET with fields=quantityOnHand',
        '/procurement/catalog',
        ['conditions' => 'id=743', 'fields' => 'id,identifier,quantityOnHand']
    ),
    relationships_cw_probe_attempt('sub-resource GET /procurement/catalog/743/inventory', '/procurement/catalog/743/inventory'),
    relationships_cw_probe_attempt('module GET /procurement/warehouses', '/procurement/warehouses', ['pageSize' => 3]),
];

relationships_respond(200, ['ok' => true, 'attempts' => $attempts]);
