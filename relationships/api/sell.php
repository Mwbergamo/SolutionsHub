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
 * Line items for a 'peoplefirst' quote -- QUANTITIES to apply to EXISTING catalog items already
 * on the copied quote, keyed by their real ConnectWise part number (manufacturerPartNumber),
 * NOT new ad-hoc items. Confirmed live 2026-10-14 (second live write-test + two follow-up probes):
 * a copied PeopleFirst quote is not a blank slate -- it's pre-loaded with 50 real catalog line
 * items, organized by device type/service, each already carrying the SAME ConnectWise part number
 * used on the Manage side ("IT Services Agreement - PeopleFirst" monthly additions + their
 * one-time onboarding fees) -- Michael confirmed this 1:1 with two reference screenshots of the
 * Manage agreement part numbers, which line up with the live Sell template's own
 * manufacturerPartNumber field exactly (10/10 device onboarding parts + Member Setup matched).
 * So the write path (relationships_sell_create_quote_for_solution()) fetches the quote's own
 * items, matches each entry below by 'part', and PATCHes ITS quantity -- it never creates a new
 * item. Each $state source field is read directly off state.managedIT (devices/addons/members),
 * not the aggregated dollar totals in managedITNums, since the whole point of this catalog is to
 * let Sell's own catalog pricing do the math per real part, not a hand-computed lump sum.
 *
 * Known gaps, flagged rather than guessed (2026-10-14):
 * - No confirmed monthly "CBT-PF-MEMBER" catalog line exists on the live template yet (Michael's
 *   Manage reference shows it as a real monthly agreement addition) -- only the one-time Member
 *   Setup (CBT-EOB) onboarding fee is wired up below.
 * - "Managed Care Maintenance - Server" on the live template carries part "CBT-MS-SERVER", not
 *   "CBT-MS-TM-SERVER" like every other device type and like Michael's own reference screenshot
 *   shows for Servers -- likely a typo in the Sell template itself. Matched on the real
 *   (CBT-MS-SERVER) value below so quantities still land; flag to Michael to fix in Sell.
 * - "Remote Agent Software" and "Patch Management" (would cover AUTO_SOFTWARE's remoteWs/
 *   remoteSrv/patch items) have NO manufacturerPartNumber set on the live template at all --
 *   left out rather than matched by fragile description text.
 * - "Risk and Vulnerability Scanning" has a section header on the template but no actual catalog
 *   product under it -- left out; Clyde's riskScan add-on has nowhere confirmed to land yet.
 * - "Mobile Device Management" (its own tab) and "Managed Care Maintenance - Mobile Phones"
 *   (Coverage Selections tab) share the SAME part (CBT-MS-TM-MDM) on the live template -- both
 *   will be set to the same mdm device count below, which is at worst harmless duplication.
 * - Veeam backup LICENSE (recurring monthly) and off-site storage-by-the-TB are real catalog
 *   products Michael pointed out are NOT on this template at all (only their one-time onboarding
 *   fees are) -- adding those needs a different write operation (attaching a new catalog product
 *   to the quote, not patching an existing item's quantity) and is deliberately deferred.
 *
 * For every kind other than 'peoplefirst', this intentionally returns an empty list -- per
 * Michael (2026-10-14): "We will get into Headings and sources and pricing for those later." The
 * quote shell (template copy + customer + unique name) still gets created either way.
 */
function relationships_sell_build_line_items(string $kind, array $state): array
{
    if ($kind !== 'peoplefirst') {
        return [];
    }
    $mi = $state['managedIT'] ?? [];
    $d = is_array($mi['devices'] ?? null) ? $mi['devices'] : [];
    $addons = is_array($mi['addons'] ?? null) ? $mi['addons'] : [];
    $members = (int) ($mi['members'] ?? 0);
    $items = [];

    // Device type -> [monthly Managed Care Maintenance part, one-time onboarding part]. Confirmed
    // 2026-10-14 against both the live Sell template's manufacturerPartNumber field AND Michael's
    // ConnectWise Manage "IT Services Agreement - PeopleFirst" part-number reference screenshots.
    $deviceParts = [
        'workstation' => ['monthly' => 'CBT-MS-TM-WORKSTATION', 'onboard' => 'CBT-DOB'],
        'server'      => ['monthly' => 'CBT-MS-SERVER',         'onboard' => 'CBT-SOB'], // see docblock -- template typo, matched as-is
        'firewall'    => ['monthly' => 'CBT-MS-TM-FIREWALL',    'onboard' => 'CBT-FOB'],
        'mdm'         => ['monthly' => 'CBT-MS-TM-MDM',         'onboard' => 'CBT-MDOB'],
        'switch'      => ['monthly' => 'CBT-MS-TM-SWITCH',      'onboard' => 'CBT-SWOB'],
        'wap'         => ['monthly' => 'CBT-MS-TM-WAP',         'onboard' => 'CBT-WAPOB'],
        'camera'      => ['monthly' => 'CBT-MS-TM-SEC',         'onboard' => 'CBT-COB'],
        'phone'       => ['monthly' => 'CBT-MS-TM-PHONE',       'onboard' => 'CBT-POB'],
        'printer'     => ['monthly' => 'CBT-MS-TM-PRINT',       'onboard' => 'CBT-PROB'],
        'network'     => ['monthly' => 'CBT-MS-TM-NET',         'onboard' => 'CBT-NETOB'],
    ];
    foreach ($deviceParts as $deviceId => $parts) {
        $qty = (int) ($d[$deviceId] ?? 0);
        if ($qty <= 0) {
            continue;
        }
        $items[] = ['part' => $parts['monthly'], 'quantity' => $qty];
        $items[] = ['part' => $parts['onboard'], 'quantity' => $qty];
    }

    // Member Setup -- one-time, per PeopleFirst member (Michael, 2026-10-14: "When you see 5
    // PeopleFirst Members on the quote tool, you would add 5 Member Setup fees").
    if ($members > 0) {
        $items[] = ['part' => 'CBT-EOB', 'quantity' => $members];
    }

    // EDR/Anti-Virus -- Clyde counts this per workstation+server (peoplefirst path only; the
    // ala-carte alaCarteSoftware path isn't handled here, matching build_line_items' existing
    // peoplefirst-only scope).
    $wsSrv = (int) ($d['workstation'] ?? 0) + (int) ($d['server'] ?? 0);
    if ($wsSrv > 0) {
        $items[] = ['part' => 'SENT-ONE-CTRL', 'quantity' => $wsSrv];
    }

    // CCOB (cyber-security endpoint onboarding) fee -- Michael's own CBT-CCOB part, scaled by the
    // same ccobQty formula computeManagedIT() already uses: EDR count + Cyber Security Bundle Pro.
    $ccobQty = $wsSrv + (int) ($addons['cyberSecPro'] ?? 0);
    if ($ccobQty > 0) {
        $items[] = ['part' => 'CBT-CCOB', 'quantity' => $ccobQty];
    }

    // MFA hardware fobs -- sold in packs of 10, minimum 10, no partial packs (Michael, 2026-10-14).
    $fobUsers = (int) ($addons['duoFob'] ?? 0);
    if ($fobUsers > 0) {
        $items[] = ['part' => 'DUO-FOB', 'quantity' => (int) (ceil($fobUsers / 10) * 10)];
    }

    // Duo MFA (per user) and Microsoft 365 Backup Protection (per user).
    if (!empty($addons['duoMfa'])) {
        $items[] = ['part' => 'DUO-MFA', 'quantity' => (int) $addons['duoMfa']];
    }
    if (!empty($addons['m365Backup'])) {
        $items[] = ['part' => 'COVE-BACKUP-365', 'quantity' => (int) $addons['m365Backup']];
    }

    // Server/Workstation backup onboarding -- billed 1:1 with the backup count, not a flat fee
    // (Michael, 2026-10-14: "3 servers to manage = 3 backup onboardings").
    if (!empty($addons['veeamSrvBackup'])) {
        $items[] = ['part' => 'CBT-SRBK', 'quantity' => (int) $addons['veeamSrvBackup']];
    }
    if (!empty($addons['veeamWsBackup'])) {
        $items[] = ['part' => 'CBT-DTBK', 'quantity' => (int) $addons['veeamWsBackup']];
    }

    // vCIO -- sold in whole hours, minimum 2 hours/120 minutes per month (Michael, 2026-10-14).
    // minutesTotal is the Hub's own computed support-minutes total (computeManagedIT()), captured
    // at save time in managedITNums so this doesn't re-derive it.
    $minutesTotal = (float) ($state['managedITNums']['minutesTotal'] ?? 0);
    if ($minutesTotal > 0) {
        $items[] = ['part' => 'CBT-R', 'quantity' => max(2, (int) ceil($minutesTotal / 60))];
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

    // 3. Update quantities on the quote's EXISTING catalog line items (real for 'peoplefirst'
    //    today; an intentional no-op for other kinds). CONFIRMED LIVE 2026-10-14 (third live
    //    write-test): a copied quote is pre-loaded with real catalog products, not blank -- the
    //    original POST /api/quoteItems approach was wrong at the root; the fix is PATCHing the
    //    QUANTITY on the matching existing item (found by its real ConnectWise part number,
    //    manufacturerPartNumber -- see relationships_sell_build_line_items()'s docblock for the
    //    full confirmation trail), never creating a new ad-hoc item.
    //
    //    UNCONFIRMED: PATCH /api/quoteItems/{itemId} itself has never been tried live -- modeled
    //    on the same RFC 6902 shape already confirmed working for PATCH /api/quotes/{id}. This is
    //    the next live write-test. $unmatchedParts surfaces anything that couldn't be matched
    //    (e.g. a part number that doesn't exist on this particular quote) so a rep/Michael can see
    //    it rather than it silently vanishing.
    $lineItems = relationships_sell_build_line_items($kind, $state);
    $unmatchedParts = [];
    $matchedCount = 0;
    if ($lineItems !== []) {
        $existingItems = relationships_sell_request('/api/quoteItems', [
            'conditions' => 'quote/id="' . $quoteId . '"',
        ]);
        $itemIdByPart = [];
        foreach ($existingItems as $existing) {
            $part = $existing['manufacturerPartNumber'] ?? null;
            $itemId = $existing['id'] ?? null;
            if (is_string($part) && $part !== '' && is_string($itemId) && $itemId !== '') {
                $itemIdByPart[$part] = $itemId;
            }
        }
        foreach ($lineItems as $item) {
            $itemId = $itemIdByPart[$item['part']] ?? null;
            if ($itemId === null) {
                $unmatchedParts[] = $item['part'];
                continue;
            }
            relationships_sell_request('/api/quoteItems/' . rawurlencode($itemId), [], 'PATCH', [
                ['op' => 'replace', 'path' => '/quantity', 'value' => $item['quantity']],
            ]);
            $matchedCount++;
        }
    }

    $refreshed = relationships_sell_request('/api/quotes/' . rawurlencode($quoteId));

    return [
        'quote_id' => $quoteId,
        'quote_number' => isset($refreshed['quoteNumber']) ? (string) $refreshed['quoteNumber'] : (isset($quote['quoteNumber']) ? (string) $quote['quoteNumber'] : null),
        'quote_link' => relationships_sell_quote_link($quoteId),
        'template_kind' => $kind,
        'line_item_count' => $matchedCount,
        'unmatched_parts' => $unmatchedParts,
    ];
}
