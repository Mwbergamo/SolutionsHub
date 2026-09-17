<?php
/**
 * register/api/agreements_probe.php
 *
 * TEMPORARY, READ-ONLY diagnostic for the "Protection Plan items as
 * recurring ConnectWise Agreement Additions" feature (register-app task:
 * upsold/standalone Protection Plan items at the register need to become
 * Agreement Additions on an "IT Services Agreement", billed monthly or
 * annually, followed by creating + closing the first invoice).
 *
 * Per Michael's own explicit choice when asked how to approach this
 * (AskUserQuestion: "Probe first, then build" -- the deliberately SLOWER,
 * safer option, not the "recommended for speed" one), this file makes NO
 * write/POST/PATCH/PUT calls whatsoever. It only reads real, already-
 * existing ConnectWise records to discover actual field names and shapes,
 * the same "diagnose before guessing" discipline this project has used for
 * every other ConnectWise integration (Company/Contact creation, the
 * Company PATCH/PUT quirk, the RMA "no writable endpoint" discovery, the
 * applyToType/applyToId invoice-to-agreement link).
 *
 * DELETE THIS FILE once its findings have been used to write the real
 * Agreement/Addition/Invoice creation code -- it has no place in the
 * shipped app (no UI links to it, but it's reachable by URL to anyone
 * signed in, and it has no reason to exist once its job is done).
 *
 * Usage: sign in to the Register app as normal, then visit this file
 * directly in the browser:
 *
 *   /register/api/agreements_probe.php
 *
 * It always runs the full read-only probe sequence below and returns one
 * JSON report. Each individual sub-probe is wrapped so one failure (wrong
 * guessed field/endpoint name) does not block the others -- a probe that
 * failed is just as informative as one that succeeded, since the error
 * message itself often names the real field/endpoint ConnectWise expected.
 *
 * What it looks at, and why:
 *
 *   1. agreement_types        -- confirms Agreement Type id 65 really is
 *                                 named "IT Services Agreement" on THIS
 *                                 instance (already believed true per
 *                                 relationships-connectwise-sync.md, but
 *                                 never independently re-confirmed from
 *                                 this app).
 *   2. agreement_statuses     -- best-effort; this reference list may or
 *                                 may not exist as its own endpoint.
 *   3. sample_agreements      -- up to 3 REAL existing Agreements of type
 *                                 65, full default field set (no `fields`
 *                                 param sent, so ConnectWise returns
 *                                 whatever it normally returns for a GET --
 *                                 this is how we discover the real field
 *                                 names for: start date, "no ending date"
 *                                 flag vs. end date, billing cycle/
 *                                 frequency (Monthly vs Annual -- the exact
 *                                 string/object shape we'll need to send
 *                                 back), status, company/contact links,
 *                                 and the agreement name field).
 *   4. sample_agreement_additions -- the Additions sub-resource of the
 *                                 first sample agreement found above (if
 *                                 any) -- reveals the real shape of an
 *                                 Addition: how it links to a catalog
 *                                 product, quantity, price, and its own
 *                                 start date / no-end-date fields.
 *   5. sample_invoices_general -- up to 3 REAL invoices, no filter, full
 *                                 default fields -- reveals the general
 *                                 invoice shape and whatever field marks
 *                                 an invoice open vs. closed.
 *   6. sample_invoices_for_agreement -- invoices linked to the same sample
 *                                 agreement via applyToId (the technique
 *                                 already confirmed for the Relationships
 *                                 app's ticket-history sync) -- if any
 *                                 exist, shows a real Agreement-linked
 *                                 invoice specifically.
 *   7. openapi_spec_probe     -- best-effort only. Tries fetching this
 *                                 ConnectWise instance's own OpenAPI/
 *                                 Swagger document directly (this app's
 *                                 server CAN reach connect.codebluetech
 *                                 nology.com over the network -- unlike
 *                                 this Claude session's own build sandbox,
 *                                 which has no route to it at all). If it
 *                                 exists at the guessed URL, this is the
 *                                 most authoritative way to find the real
 *                                 write endpoint + payload shape for
 *                                 closing an invoice, which a sample data
 *                                 dump alone can't reveal. Download is
 *                                 capped and this step is allowed to fail
 *                                 without affecting the rest of the report.
 */

declare(strict_types=1);

require_once __DIR__ . '/_util.php';
require_once __DIR__ . '/connectwise.php';

register_install_error_handlers();

@ini_set('max_execution_time', '55');

$pdo = register_db();
register_require_login($pdo);

/**
 * Runs one sub-probe, catching any error so the rest of the report still
 * comes back. Mirrors this project's established pattern of treating a
 * failed guess as useful diagnostic information, not a fatal error.
 */
function probe_safe(callable $fn): array
{
    try {
        return ['ok' => true, 'data' => $fn()];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Same auth as register_cw_request(), but for a raw GET outside the
 * /v4_6_release/apis/3.0 path root (needed for a possible OpenAPI/Swagger
 * document, which typically lives at the instance root, not under the
 * versioned API path) with a hard byte cap so a huge spec document can't
 * exhaust memory or blow the execution-time budget.
 */
function probe_raw_get(string $url, int $maxBytes = 500000, int $timeoutSeconds = 15): array
{
    $config = register_cw_config();
    $authString = $config['company_id'] . '+' . $config['public_key'] . ':' . $config['private_key'];
    $headers = [
        'Authorization: Basic ' . base64_encode($authString),
        'clientId: ' . $config['client_id'],
        'Accept: application/json',
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => $timeoutSeconds,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_RANGE => '0-' . ($maxBytes - 1),
    ]);
    $body = curl_exec($ch);
    $errNo = curl_errno($ch);
    $errStr = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    if ($errNo !== 0) {
        throw new RuntimeException("cURL error $errNo: $errStr — $url");
    }

    return [
        'url' => $url,
        'http_status' => $status,
        'content_type' => $contentType,
        'byte_count' => is_string($body) ? strlen($body) : 0,
        'body_snippet' => is_string($body) ? substr($body, 0, 4000) : '',
    ];
}

$report = [];

// 1. Confirm Agreement Type id 65 really is "IT Services Agreement" here.
$report['agreement_types'] = probe_safe(function () {
    return register_cw_request('/finance/agreements/types', ['pageSize' => '100'], 'GET', null, 20, 8);
});

// 2. Best-effort -- this reference list may not exist under this path.
$report['agreement_statuses'] = probe_safe(function () {
    return register_cw_request('/finance/agreements/statuses', ['pageSize' => '100'], 'GET', null, 20, 8);
});

// 3. Up to 3 real existing Agreements of type 65, full default fields
//    (no `fields` param sent -- see docblock above).
$report['sample_agreements'] = probe_safe(function () {
    return register_cw_request(
        '/finance/agreements',
        ['conditions' => 'type/id=65', 'pageSize' => '3', 'page' => '1'],
        'GET',
        null,
        25,
        8
    );
});

$sampleAgreementId = null;
if ($report['sample_agreements']['ok'] && !empty($report['sample_agreements']['data'][0]['id'])) {
    $sampleAgreementId = (int) $report['sample_agreements']['data'][0]['id'];
}

// 4. The Additions sub-resource of that sample agreement, if we found one.
if ($sampleAgreementId !== null) {
    $report['sample_agreement_additions'] = probe_safe(function () use ($sampleAgreementId) {
        return register_cw_request(
            '/finance/agreements/' . $sampleAgreementId . '/additions',
            ['pageSize' => '10', 'page' => '1'],
            'GET',
            null,
            25,
            8
        );
    });
} else {
    $report['sample_agreement_additions'] = [
        'ok' => false,
        'error' => 'Skipped -- no sample agreement id was found in step 3 to look up additions for.',
    ];
}

// 5. General invoice shape -- no filter, full default fields.
$report['sample_invoices_general'] = probe_safe(function () {
    return register_cw_request(
        '/finance/invoices',
        ['pageSize' => '3', 'page' => '1'],
        'GET',
        null,
        25,
        8
    );
});

// 6. Invoices linked to the sample agreement via applyToId (same technique
//    already confirmed for the Relationships app's ticket-history sync --
//    see relationships-connectwise-sync.md).
if ($sampleAgreementId !== null) {
    $report['sample_invoices_for_agreement'] = probe_safe(function () use ($sampleAgreementId) {
        return register_cw_request(
            '/finance/invoices',
            ['conditions' => 'applyToId=' . $sampleAgreementId, 'pageSize' => '10', 'page' => '1'],
            'GET',
            null,
            25,
            8
        );
    });
} else {
    $report['sample_invoices_for_agreement'] = [
        'ok' => false,
        'error' => 'Skipped -- no sample agreement id was found in step 3 to look up invoices for.',
    ];
}

// 7. Added after round 1: Michael wants the invoice set to "Approved"
//    rather than fully closed (closing appeared to post a real GL/
//    accounting batch on every closed sample in round 1 -- see
//    register-agreement-billing-probe-findings.md). Round 1 only ever
//    observed "Closed" (id 3) and "Closed - Emailed" (id 6) on real
//    invoices, never "Approved" -- these two steps find its real
//    {id, name} shape before any write code assumes one.
$report['invoice_statuses_endpoint'] = probe_safe(function () {
    // A dedicated reference-list endpoint, if this instance has one --
    // separate resource from /finance/agreements/statuses (which 404'd
    // in round 1), so worth trying on its own rather than assuming the
    // same 404.
    return register_cw_request('/finance/invoices/statuses', ['pageSize' => '100'], 'GET', null, 20, 8);
});

$report['invoice_status_catalog_sample'] = probe_safe(function () {
    // No dedicated reference endpoint may exist, so fall back to scanning
    // a broader sample of real invoices (id/status/date only, to keep the
    // payload small) and returning the distinct status values actually
    // seen -- including, hopefully, "Approved".
    $rows = register_cw_request(
        '/finance/invoices',
        ['fields' => 'id,status,date', 'pageSize' => '100', 'page' => '1'],
        'GET',
        null,
        25,
        8
    );
    $distinct = [];
    foreach ($rows as $row) {
        $status = $row['status'] ?? null;
        if (is_array($status) && isset($status['id'])) {
            $distinct[(int) $status['id']] = $status;
        }
    }
    return [
        'invoices_scanned' => count($rows),
        'distinct_statuses_found' => array_values($distinct),
    ];
});

// 8. Best-effort OpenAPI/Swagger document probe -- see docblock. Tries the
//    instance root's most likely documented location; capped download,
//    allowed to fail without affecting anything above.
$report['openapi_spec_probe'] = probe_safe(function () {
    $config = register_cw_config();
    $root = rtrim((string) $config['base_url'], '/');
    return probe_raw_get($root . '/v4_6_release/apis/3.0/apidocs.json');
});

// 9. Added after round 2: Michael named "ZZ Agreement Test Company" as a
//    safe place to run the eventual LIVE create-test against (a real
//    throwaway Agreement, not a real customer's billing record) -- see
//    register-agreement-billing-probe-findings.md. This step is still
//    read-only: it just looks up that company's real id, its site(s), and
//    any contacts, so the create-test call (a separate step, once this
//    and the Approved-status lookup above are both confirmed) has real
//    ids to reference instead of guessing.
$report['test_company_lookup'] = probe_safe(function () {
    $company = register_cw_request(
        '/company/companies',
        ['conditions' => 'name like "%' . register_cw_condition_escape('ZZ Agreement Test Company') . '%"', 'pageSize' => '5', 'page' => '1'],
        'GET',
        null,
        20,
        8
    );
    $result = ['company_matches' => $company];

    $companyId = null;
    if (!empty($company[0]['id'])) {
        $companyId = (int) $company[0]['id'];
    }

    if ($companyId !== null) {
        $result['sites'] = register_cw_request(
            '/company/companies/' . $companyId . '/sites',
            ['pageSize' => '10', 'page' => '1'],
            'GET',
            null,
            20,
            8
        );
        $result['contacts'] = register_cw_request(
            '/company/contacts',
            ['conditions' => 'company/id=' . $companyId, 'pageSize' => '10', 'page' => '1'],
            'GET',
            null,
            20,
            8
        );
    } else {
        $result['sites'] = [];
        $result['contacts'] = [];
        $result['note'] = 'No company matched "ZZ Agreement Test Company" -- check the exact name in ConnectWise.';
    }

    return $result;
});

register_respond(200, [
    'ok' => true,
    'note' => 'Read-only diagnostic. No ConnectWise records were created, changed, or deleted by this request.',
    'agreement_type_id_probed' => 65,
    'report' => $report,
]);
