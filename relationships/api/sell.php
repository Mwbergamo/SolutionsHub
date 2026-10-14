<?php
/**
 * relationships/api/sell.php
 *
 * ConnectWise CPQ (Sell/Quosal) REST client + the Clyde-solution -> Sell-quote write path
 * (added 2026-10-14, per Michael). Separate from relationships/api/connectwise.php, which talks
 * to ConnectWise MANAGE -- Sell is a completely different product/API (different base URL, auth
 * scheme, and no public documentation; see claude/solutions-hub-cpq-quote-integration.md in the
 * project docs for the full research trail). No public ConnectWise docs exist for this API --
 * every shape below was confirmed live against CodeBlue's real Sell account via temporary,
 * read-only probe scripts (now deleted) before being written here, same discipline as every
 * other ConnectWise integration in this codebase.
 *
 * ---- Scope, per Michael (2026-10-14) ----
 * "so publishing doesn't do anything but generate a client facing version of the quote. I don't
 * actually need to create an opportunity at the quote stage at all. The opportunity is created
 * when the quote is converted to an order which is totally fine. Let's focus on creating the
 * parts on the quote from the Clyde Tool and saving the Sell quote, linking the two together for
 * review and future editing if needed." -- so this file NEVER touches ConnectWise Manage's
 * /sales/opportunities. It only creates/populates a Sell quote and records the link.
 *
 * "Honestly, we will eventually create Sell quotes from Clyde for everything. The only real
 * difference is, every other solution we sell, will start with the Proposal template instead of
 * the PeopleFirst template. We will get into Headings and sources and pricing for those later" --
 * so RELATIONSHIPS_SELL_QUOTE_TEMPLATES below is a small, extensible kind -> template map, and
 * relationships_sell_build_line_items() has a real implementation for 'peoplefirst' today and an
 * intentional stub (an empty line-item list -- the quote shell still gets created) for every
 * other kind, to be filled in as each pillar's Sell pricing/parts mapping gets defined.
 *
 * ---- STILL UNCONFIRMED, flagged rather than guessed ----
 * Every GET-based fact here (auth scheme, base URL, template copy, rep lookup) was confirmed live
 * in this session. The WRITE calls below (PATCH /api/quotes/{id}, PUT /api/quotes/{id}/customers,
 * POST /api/quoteItems) have NOT yet been tried against this account -- this is the first write
 * code in this integration. Michael flagged doing one live write-test (a throwaway, obviously
 * named test quote) before wiring the real "Create Sell Quote" button live; do that before
 * trusting this file's payload shapes blindly. The exact required fields for
 * PUT /api/quotes/{id}/customers in particular are a best-effort guess from the open-source
 * node-connectwise-cpq client's documented shape, not yet confirmed against a real write here.
 *
 * ---- The "Proposal" template id is NOT YET KNOWN ----
 * RELATIONSHIPS_SELL_QUOTE_TEMPLATES['proposal']['id'] is null below. relationships_sell_quote_template()
 * throws a clear RelationshipsSellError if asked for a kind whose template id isn't filled in yet,
 * rather than guessing a wrong GUID -- fill it in once Michael confirms the real template (same
 * way 'peoplefirst' was confirmed: GET /api/templates, match by name).
 */

declare(strict_types=1);

class RelationshipsSellError extends RuntimeException
{
}

function relationships_sell_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }
    $path = __DIR__ . '/../../connectwise-sell-config.php';
    if (!is_file($path)) {
        throw new RelationshipsSellError('connectwise-sell-config.php is missing from the repo root.');
    }
    $config = require $path;
    foreach (['access_key', 'public_key', 'private_key', 'base_url'] as $key) {
        if (empty($config[$key])) {
            throw new RelationshipsSellError("connectwise-sell-config.php is missing required key \"$key\".");
        }
    }
    return $config;
}

/**
 * One authenticated request against the Sell/CPQ REST API. $path is relative to the API root,
 * e.g. "/api/quotes/copyById/{id}". $jsonBody, when given, is sent as the request body (POST by
 * default, or whatever $method is set to) encoded as JSON.
 *
 * Confirmed live (2026-10-14): HTTP Basic auth, base64("{accessKey}+{publicKey}:{privateKey}"),
 * PLUS "Content-Type: application/json; version=1.0" on every request (including GETs). A missing
 * Authorization header produces an ambiguous 500, not a 401 -- a real auth failure's message is
 * "An unknown error has occured during basic auth validation" (vendor typo and all).
 */
function relationships_sell_request(string $path, array $query = [], string $method = 'GET', $jsonBody = null): array
{
    $config = relationships_sell_config();
    $base = rtrim((string) $config['base_url'], '/');
    $url = $base . $path;
    if ($query !== []) {
        $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    $authToken = base64_encode($config['access_key'] . '+' . $config['public_key'] . ':' . $config['private_key']);
    $headers = [
        'Accept: application/json',
        'Content-Type: application/json; version=1.0',
        'Authorization: Basic ' . $authToken,
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    if ($jsonBody !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($jsonBody));
    }
    $body = curl_exec($ch);
    $err = curl_error($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err !== '') {
        throw new RelationshipsSellError("Sell request failed ($method $path): $err");
    }
    $decoded = json_decode((string) $body, true);
    if ($status < 200 || $status >= 300) {
        $msg = is_array($decoded) && isset($decoded['message']) ? $decoded['message'] : substr((string) $body, 0, 500);
        throw new RelationshipsSellError("Sell $method $path returned HTTP $status: $msg");
    }
    if (!is_array($decoded)) {
        // PATCH/PUT sometimes return an empty body on success -- treat that as "ok, nothing to report".
        return [];
    }
    return $decoded;
}

/**
 * Which Sell template a saved solution's Clyde->Sell quote should start from, per Michael
 * (2026-10-14): PeopleFirst-approach Managed IT solutions use the PeopleFirst Support Plan
 * template; everything else uses the (not-yet-configured) Proposal template. Add more kinds here
 * as other pillars get their own template/pricing defined.
 */
function relationships_sell_quote_kind_for_state(array $state): string
{
    $mi = $state['managedIT'] ?? null;
    if (is_array($mi) && ($mi['approach'] ?? '') === 'peoplefirst' && (int) ($mi['members'] ?? 0) > 0) {
        return 'peoplefirst';
    }
    return 'proposal';
}

const RELATIONSHIPS_SELL_QUOTE_TEMPLATES = [
    'peoplefirst' => [
        'id' => 'q638470587029567633CoVvNoC', // "PeopleFirst Support Plan", quoteNumber 6841 -- confirmed live 2026-10-14
        'name' => 'PeopleFirst Support Plan',
    ],
    'proposal' => [
        'id' => 'q636948201801326595zaAqAMD', // "Proposal", quoteNumber 9 -- confirmed live 2026-10-14
        'name' => 'Proposal',
    ],
];

function relationships_sell_quote_template(string $kind): array
{
    $tpl = RELATIONSHIPS_SELL_QUOTE_TEMPLATES[$kind] ?? null;
    if ($tpl === null) {
        throw new RelationshipsSellError("Unknown Sell quote kind \"$kind\".");
    }
    if (empty($tpl['id'])) {
        throw new RelationshipsSellError(
            'The "' . $tpl['name'] . '" Sell template isn\'t configured yet -- fill in its real template id ' .
            'in RELATIONSHIPS_SELL_QUOTE_TEMPLATES (relationships/api/sell.php) before creating this kind of quote.'
        );
    }
    return $tpl;
}

/**
 * Looks up a CPQ user's /settings/user id by email (case-insensitive) -- confirmed live
 * 2026-10-14. Used to set insideRep/primaryRep/quoteCreator to the logged-in Solutions Hub rep,
 * per Michael's scope: "Quotes will be one rep per quote and the main contact."
 */
function relationships_sell_rep_id_by_email(string $email): ?string
{
    $email = trim($email);
    if ($email === '') {
        return null;
    }
    $users = relationships_sell_request('/settings/user');
    foreach ($users as $u) {
        if (isset($u['emailAddress']) && strcasecmp((string) $u['emailAddress'], $email) === 0) {
            return isset($u['id']) ? (string) $u['id'] : null;
        }
    }
    return null;
}

/**
 * Resolves a ConnectWise Manage company's primary contact (for the Sell quote's one "main
 * contact", per Michael's scope -- no inside/outside rep or multi-contact handling). Prefers the
 * company's own defaultContact; falls back to the first active contact found for the company if
 * none is set. Returns null (never throws) if neither is available -- the quote still gets
 * created with just the company attached, rather than failing the whole write path over a
 * missing contact.
 */
function relationships_sell_resolve_primary_contact(int $cwCompanyId): ?array
{
    try {
        $company = relationships_cw_request('/company/companies/' . $cwCompanyId, [
            'fields' => 'id,name,defaultContact,addressLine1,addressLine2,city,state,zip,phoneNumber',
        ]);
    } catch (Throwable $e) {
        return null;
    }
    $contact = null;
    if (!empty($company['defaultContact']['id'])) {
        try {
            $contact = relationships_cw_request('/company/contacts/' . (int) $company['defaultContact']['id']);
        } catch (Throwable $e) {
            $contact = null;
        }
    }
    if ($contact === null) {
        try {
            $candidates = relationships_cw_request('/company/contacts', [
                'conditions' => 'company/id = ' . $cwCompanyId,
                'pageSize' => '1',
            ]);
            $contact = $candidates[0] ?? null;
        } catch (Throwable $e) {
            $contact = null;
        }
    }
    return ['company' => $company, 'contact' => $contact];
}

/**
 * Line items for a 'peoplefirst' quote, built from the EXACT pricing numbers the Hub itself
 * computed and saved on the solution snapshot (state.managedITNums -- see app.js's
 * getSolutionSnapshot()/computeManagedIT()), not re-derived here. This guarantees the Sell
 * quote's dollar amounts match what the rep/customer actually saw in the Hub.
 *
 * For every other kind, this intentionally returns an empty list -- per Michael (2026-10-14):
 * "We will get into Headings and sources and pricing for those later." The quote shell (template
 * copy + customer + unique name) still gets created; parts/pricing for non-PeopleFirst pillars is
 * future work.
 */
function relationships_sell_build_line_items(string $kind, array $state): array
{
    if ($kind !== 'peoplefirst') {
        return [];
    }
    $nums = $state['managedITNums'] ?? [];
    $mi = $state['managedIT'] ?? [];
    $members = (int) ($mi['members'] ?? 0);
    $items = [];

    if ($members > 0 && !empty($nums['perMemberPrice'])) {
        $items[] = [
            'description' => 'PeopleFirst Membership',
            'quantity' => $members,
            'price' => round((float) $nums['perMemberPrice'], 2),
            'isOptional' => false,
            'tab' => 'PeopleFirst Member Pricing',
        ];
    }
    if (!empty($nums['softwareMonthly'])) {
        $items[] = [
            'description' => 'Required Software (Monthly)',
            'quantity' => 1,
            'price' => round((float) $nums['softwareMonthly'], 2),
            'isOptional' => false,
            'tab' => 'Security Software',
        ];
    }
    if (!empty($nums['hardwareMonthly'])) {
        $items[] = [
            'description' => 'Required Hardware (Monthly)' . (!empty($mi['firewallSku']) ? ' - ' . $mi['firewallSku'] : ''),
            'quantity' => 1,
            'price' => round((float) $nums['hardwareMonthly'], 2),
            'isOptional' => false,
            'tab' => 'Provided Firewall',
        ];
    }
    if (!empty($nums['onboardingTotal'])) {
        $items[] = [
            'description' => 'Onboarding (One-Time)',
            'quantity' => 1,
            'price' => round((float) $nums['onboardingTotal'], 2),
            'isOptional' => false,
            'tab' => 'Partner Onboarding',
        ];
    }
    return $items;
}

/** Builds the quote.dashboard deep link for a Sell quote id -- confirmed live 2026-10-14. */
function relationships_sell_quote_link(string $quoteId): string
{
    $accessKey = relationships_sell_config()['access_key'];
    return 'https://codebluetechnology.quosalsell.com/QuosalWeb/quote.dashboard?accesskey=' . rawurlencode($accessKey) .
        '&idquotemain=' . rawurlencode($quoteId) . '&skiplayout=yes&submodules=quote.home';
}

/**
 * The full Clyde-solution -> Sell-quote write path. $solutionName/$customerName are for the
 * quote's unique display name (proposed convention: "{template} - {customer} - {MM/DD/YYYY}",
 * close to Michael's own example "PeopleFirst Solutions - 10/13/2026" -- adjust if he wants it
 * different). $cwCompanyId is the customer's real ConnectWise Manage company id
 * (customers.connectwise_id) -- required; throws if missing/blank, since a Sell quote with no
 * real customer isn't useful.
 *
 * Returns ['quote_id'=>, 'quote_number'=>, 'quote_link'=>, 'template_kind'=>, 'line_item_count'=>].
 * Throws RelationshipsSellError on any failure -- the caller (solutions.php) records that as a
 * 'failed' solution_sell_links row rather than losing the error.
 */
function relationships_sell_create_quote_for_solution(
    array $state,
    string $cwCompanyId,
    string $customerName,
    string $repEmail,
    string $repName
): array {
    if (trim($cwCompanyId) === '') {
        throw new RelationshipsSellError('This customer has no ConnectWise company linked -- cannot create a Sell quote.');
    }

    $kind = relationships_sell_quote_kind_for_state($state);
    $template = relationships_sell_quote_template($kind);

    // 1. Copy the template -- the only quote-creation path Sell's API has (confirmed 2026-10-14).
    $quote = relationships_sell_request('/api/quotes/copyById/' . rawurlencode($template['id']), [], 'POST');
    $quoteId = $quote['id'] ?? null;
    if (!is_string($quoteId) || $quoteId === '') {
        throw new RelationshipsSellError('Sell did not return a new quote id from copyById.');
    }

    // 2. Rename to a unique name + set the rep fields + the customer, all in ONE PATCH (RFC 6902
    //    JSON Patch) against the main quote resource.
    //
    //    CONFIRMED LIVE 2026-10-14 (first real write-test, test quote q639275414649164801ExKQXCM):
    //    PATCH /api/quotes/{id} works (that's how the rename succeeded) -- but the separate
    //    PUT /api/quotes/{id}/customers call this step originally used does NOT: Sell returned
    //    HTTP 400 "The requested resource with API version '2.0' does not support HTTP method
    //    'PUT'." A follow-up read of that same real quote showed QuoteView only exposes customer
    //    info as two plain top-level fields -- accountName and contactName (contactName even
    //    came pre-filled from the template) -- no address/phone/email fields live on the quote
    //    object itself. So "set the customer" folds into this same working PATCH instead of the
    //    broken /customers sub-resource. Deeper fields (address/email/phone) aren't settable this
    //    way -- revisit via /customers only once the right HTTP method for it is confirmed.
    $uniqueName = $template['name'] . ' - ' . $customerName . ' - ' . date('m/d/Y');
    $patchOps = [
        ['op' => 'replace', 'path' => '/name', 'value' => $uniqueName],
    ];
    $repId = relationships_sell_rep_id_by_email($repEmail);
    if ($repId !== null) {
        foreach (['insideRep', 'primaryRep', 'quoteCreator'] as $field) {
            $patchOps[] = ['op' => 'replace', 'path' => '/' . $field, 'value' => $repId];
        }
    }
    $resolved = relationships_sell_resolve_primary_contact((int) $cwCompanyId);
    $accountName = $customerName;
    $contactFullName = null;
    if ($resolved !== null) {
        $company = $resolved['company'];
        $contact = $resolved['contact'];
        $accountName = (string) ($company['name'] ?? $customerName);
        if (is_array($contact)) {
            $full = trim(((string) ($contact['firstName'] ?? '')) . ' ' . ((string) ($contact['lastName'] ?? '')));
            if ($full !== '') {
                $contactFullName = $full;
            }
        }
    }
    $patchOps[] = ['op' => 'replace', 'path' => '/accountName', 'value' => $accountName];
    if ($contactFullName !== null) {
        $patchOps[] = ['op' => 'replace', 'path' => '/contactName', 'value' => $contactFullName];
    }
    relationships_sell_request('/api/quotes/' . rawurlencode($quoteId), [], 'PATCH', $patchOps);

    // 3. Add line items (real for 'peoplefirst' today; an intentional no-op for other kinds).
    //    POST /api/quoteItems needs both idQuote AND idQuoteTabs (community-established, not
    //    publicly documented). CONFIRMED LIVE 2026-10-14 (quoteTabs probe #2, against the real
    //    test quote created by the earlier failed attempts): GET /api/quoteTabs?idQuote={id} and
    //    the dotted GET /api/quoteTabs?quote.id={id} guess BOTH silently ignore the filter and
    //    return an unrelated default list -- the working filter is Manage-style condition syntax:
    //    conditions=quote/id="{id}". That same probe also showed the PeopleFirst template's real
    //    tab names (Coverage Selections, vCIO Services, Provided Firewall, Exhibit A, Security
    //    Software, How-To, PeopleFirst Member Pricing, Mobile Device Management, Included Support
    //    for Members, Partner Onboarding) -- these are MEANINGFULLY NAMED tabs, not one generic
    //    "line items" tab, so each line item now targets its matching tab by name (set via the
    //    'tab' key in relationships_sell_build_line_items()) instead of dumping everything onto
    //    whichever tab happened to come back first. Falls back to the first tab if a named match
    //    isn't found, so a future kind/tab-name mismatch still creates a quote rather than failing.
    $lineItems = relationships_sell_build_line_items($kind, $state);
    if ($lineItems !== []) {
        $tabs = relationships_sell_request('/api/quoteTabs', [
            'conditions' => 'quote/id="' . $quoteId . '"',
        ]);
        if ($tabs === []) {
            throw new RelationshipsSellError('Could not find any quote tabs on the new Sell quote to attach line items to.');
        }
        $tabIdByName = [];
        foreach ($tabs as $tab) {
            if (isset($tab['name'], $tab['id'])) {
                $tabIdByName[(string) $tab['name']] = (string) $tab['id'];
            }
        }
        $fallbackTabId = (string) $tabs[0]['id'];
        foreach ($lineItems as $item) {
            $tabName = $item['tab'] ?? null;
            unset($item['tab']);
            $tabId = ($tabName !== null && isset($tabIdByName[$tabName])) ? $tabIdByName[$tabName] : $fallbackTabId;
            relationships_sell_request('/api/quoteItems', [], 'POST', array_merge($item, [
                'idQuote' => $quoteId,
                'idQuoteTabs' => $tabId,
            ]));
        }
    }

    $refreshed = relationships_sell_request('/api/quotes/' . rawurlencode($quoteId));

    return [
        'quote_id' => $quoteId,
        'quote_number' => isset($refreshed['quoteNumber']) ? (string) $refreshed['quoteNumber'] : (isset($quote['quoteNumber']) ? (string) $quote['quoteNumber'] : null),
        'quote_link' => relationships_sell_quote_link($quoteId),
        'template_kind' => $kind,
        'line_item_count' => count($lineItems),
    ];
}
