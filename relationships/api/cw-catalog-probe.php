<?php
/**
 * relationships/api/cw-catalog-probe.php
 *
 * TEMPORARY, READ-ONLY diagnostic for the new Register app's catalog sync
 * (SolutionsHub task tracker #40-#44). We've never touched ConnectWise's
 * Procurement Catalog module before, and this project's hard-won lesson
 * from the Activity-creation bug (2026-09-11) is: don't guess field/endpoint
 * names from docs or general ConnectWise knowledge -- fetch a real record
 * and read the actual field names off of it.
 *
 * Deliberately requests NO `fields` (see ConnectWise's full default field
 * set rather than guessing which ones to ask for) and NO `conditions`
 * filter (avoid guessing the "on hand" condition syntax too). Reuses the
 * already-configured relationships/api/connectwise.php + connectwise-config.php
 * rather than needing the not-yet-scaffolded register/ app's own config.
 *
 * GET /relationships/api/cw-catalog-probe.php
 *   -> { ok: true, rows: [ <raw ConnectWise Procurement Catalog Item>, ... ] }
 *   -> { ok: false, error: "..." } on any ConnectWise-side failure
 *
 * Gated behind relationships_require_login() like every other endpoint in
 * this app -- not public. DELETE THIS FILE once the real field names for
 * on-hand quantity / price / category / description are confirmed and
 * task #42's real sync logic is written against them (same lifecycle as
 * the now-removed checklist.php?action=cw_date_probe).
 */

declare(strict_types=1);

require_once __DIR__ . '/_util.php';
require_once __DIR__ . '/connectwise.php';

$pdo = relationships_db();
relationships_require_login($pdo);

try {
    $rows = relationships_cw_request('/procurement/catalog', [
        'pageSize' => 5,
    ]);
    relationships_respond(200, ['ok' => true, 'rows' => $rows]);
} catch (Throwable $e) {
    relationships_respond(200, ['ok' => false, 'error' => $e->getMessage()]);
}
