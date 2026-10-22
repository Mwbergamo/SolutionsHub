<?php
/**
 * relationships/api/top-clients.php
 *
 * GET /relationships/api/top-clients.php?action=list
 *   -> { ok: true, year, generated_at, lookup_error,
 *        rows: [ { customer_id, business_name, contact_first_name,
 *                  contact_last_name, address1, address2, city, state, zip,
 *                  dollars_ytd }, ... ] (<=50, ranked desc by dollars_ytd) }
 *
 * Powers the front-page "Top 50" gauge (dashboard.php's `top_clients` gauge
 * entry) -- added 2026-10-22 per Michael: "Every year, we need to know who
 * our top 50 clients are by invoiced dollars ... an exportable list via
 * excel." See claude/relationships-top-50-clients.md for the full writeup
 * and the assumptions called out below.
 *
 * The ranking and dollar figures come entirely from the nightly-synced
 * customer_ytd_billing table (connectwise-ytd-billing-sync-core.php). This
 * endpoint's own LIVE ConnectWise calls are just the two bulk lookups
 * below, for address + primary contact, and ONLY for the <=50 winning
 * companies -- never for the full customer base, so it stays fast and
 * cheap no matter how often a rep opens the popover.
 *
 * "Primary billing contact" -- ConnectWise Manage has no real "billing
 * contact" field on a Company record (confirmed against CodeBlue's live
 * data and against ConnectWise's own field reference while building this
 * -- the closest real fields, billToCompany/billingTerms/invoiceTemplate/
 * etc., don't name a contact). This uses the company's defaultContact
 * instead, the same proxy relationships_sell_resolve_primary_contact() in
 * sell.php already uses for Sell quotes, falling back to the first active
 * contact found for that company when no defaultContact is set. Flagged
 * to Michael as an assumption, not something to block on.
 */

declare(strict_types=1);

require_once __DIR__ . '/_util.php';
require_once __DIR__ . '/connectwise.php';

$pdo = relationships_db();
relationships_require_login($pdo);

$action = $_GET['action'] ?? '';

if ($action === 'list') {
    $year = date('Y');
    $stmt = $pdo->prepare(
        'SELECT c.id AS customer_id, c.name AS business_name, c.connectwise_id,
                b.total AS dollars_ytd, b.synced_at
         FROM customer_ytd_billing b
         JOIN customers c ON c.id = b.customer_id
         WHERE b.year = :year
         ORDER BY b.total DESC
         LIMIT 50'
    );
    $stmt->execute([':year' => $year]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $generatedAt = null;
    foreach ($rows as $r) {
        $syncedAt = (string) $r['synced_at'];
        if ($generatedAt === null || $syncedAt > $generatedAt) {
            $generatedAt = $syncedAt;
        }
    }

    $cwIds = [];
    foreach ($rows as $r) {
        $cwId = (string) ($r['connectwise_id'] ?? '');
        if ($cwId !== '') {
            $cwIds[] = $cwId;
        }
    }

    $companiesById = [];  // ConnectWise company id (string) => company row
    $contactsById = [];   // ConnectWise contact id (string), or 'fallback:<companyId>' => contact row
    $lookupError = null;
    if ($cwIds !== []) {
        try {
            $idList = implode(',', $cwIds);
            $companies = relationships_cw_list(
                '/company/companies',
                "id in ($idList)",
                ['id', 'name', 'defaultContact', 'addressLine1', 'addressLine2', 'city', 'state', 'zip'],
                200
            );
            foreach ($companies as $co) {
                $companiesById[(string) ($co['id'] ?? '')] = $co;
            }

            $defaultContactIds = [];
            foreach ($companiesById as $co) {
                $dcId = $co['defaultContact']['id'] ?? null;
                if ($dcId !== null) {
                    $defaultContactIds[] = (string) $dcId;
                }
            }
            if ($defaultContactIds !== []) {
                $contactIdList = implode(',', array_unique($defaultContactIds));
                $contacts = relationships_cw_list(
                    '/company/contacts',
                    "id in ($contactIdList)",
                    ['id', 'firstName', 'lastName'],
                    200
                );
                foreach ($contacts as $ct) {
                    $contactsById[(string) ($ct['id'] ?? '')] = $ct;
                }
            }

            // Fallback, one company at a time -- only for a company with no
            // defaultContact at all (rare). Same fallback
            // relationships_sell_resolve_primary_contact() in sell.php uses.
            foreach ($companiesById as $cwId => $co) {
                if (!empty($co['defaultContact']['id'])) {
                    continue;
                }
                try {
                    $candidates = relationships_cw_request('/company/contacts', [
                        'conditions' => 'company/id = ' . $cwId,
                        'pageSize' => '1',
                    ]);
                    if (!empty($candidates[0])) {
                        $contactsById['fallback:' . $cwId] = $candidates[0];
                    }
                } catch (Throwable $e) {
                    // Best-effort -- this one company's row just shows a blank contact.
                }
            }
        } catch (Throwable $e) {
            // Address/contact lookup failed entirely (e.g. the "id in (...)"
            // condition syntax turns out not to work against these two
            // endpoints) -- never block the list over it. Business name and
            // dollar figures below still come from local data either way;
            // the frontend surfaces $lookupError so blank address/contact
            // columns are explained rather than silently wrong-looking.
            $lookupError = $e->getMessage();
        }
    }

    $result = [];
    foreach ($rows as $r) {
        $cwId = (string) ($r['connectwise_id'] ?? '');
        $company = $companiesById[$cwId] ?? null;
        $contact = null;
        if ($company !== null && !empty($company['defaultContact']['id'])) {
            $contact = $contactsById[(string) $company['defaultContact']['id']] ?? null;
        }
        if ($contact === null) {
            $contact = $contactsById['fallback:' . $cwId] ?? null;
        }

        $result[] = [
            'customer_id' => (int) $r['customer_id'],
            'business_name' => (string) $r['business_name'],
            'contact_first_name' => (string) ($contact['firstName'] ?? ''),
            'contact_last_name' => (string) ($contact['lastName'] ?? ''),
            'address1' => (string) ($company['addressLine1'] ?? ''),
            'address2' => (string) ($company['addressLine2'] ?? ''),
            'city' => (string) ($company['city'] ?? ''),
            'state' => (string) ($company['state'] ?? ''),
            'zip' => (string) ($company['zip'] ?? ''),
            'dollars_ytd' => round((float) $r['dollars_ytd'], 2),
        ];
    }

    relationships_respond(200, [
        'ok' => true,
        'year' => $year,
        'generated_at' => $generatedAt,
        'lookup_error' => $lookupError,
        'rows' => $result,
    ]);
}

relationships_respond(400, ['ok' => false, 'error' => 'Unknown action.']);
