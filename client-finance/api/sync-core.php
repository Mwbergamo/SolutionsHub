<?php
/**
 * client-finance/api/sync-core.php
 *
 * Real ConnectWise sync for the Client Finance dashboard, written against
 * confirmed field shapes only -- every endpoint, field name, and
 * classification rule below was verified against live production data by
 * Michael via client-finance/api/probe.php before this file existed; see
 * claude/client-finance-probe-findings.md for the full probe trail. This
 * is NOT a nightly batch sync like relationships/commissions -- a rep
 * opens ONE customer's dashboard and this syncs THAT customer's data,
 * on demand, section by section (see db.php's header for why).
 *
 * Confirmed sources, section by section (see the findings doc for detail):
 *   - Invoice category (standard/sales_order/agreement/credit/other) from
 *     `type` + `applyToType` on /finance/invoices -- findings doc #1.
 *   - Sales Orders live at /sales/orders, NOT /procurement/orders --
 *     findings doc #3. Their own `/lineitems` sub-resource has no useful
 *     product detail; real line detail is /procurement/products.
 *   - Agreement additions at /finance/agreements/{id}/additions --
 *     findings doc #4 (same shape commissions/api/sync-core.php already
 *     uses via commissions_agreement_lookup()).
 *   - Product/line detail (both Sales-Order product lines AND any
 *     invoice's line items) at /procurement/products?conditions=invoice/
 *     id=N -- findings doc #5. No per-line date (inherited from the
 *     invoice) and no ticket/project tie-back -- only salesOrder +
 *     opportunity.
 *   - Computer vs. Networking hardware classification from the
 *     Procurement Catalog's category/subcategory -- findings doc #6.
 *     Clean for subcategory Desktop/Laptop/Server/Servers/Workstation
 *     (computer) and Network Device (networking). Two newer catch-all
 *     subcategories ("IT HW/SW Sold", "Premise HW/SW Sold") mix
 *     computer/networking/unrelated items together; per Michael's
 *     decision (2026-10-16) those two get a description-keyword fallback,
 *     tightened against the "Desktop Switch" / "Wireless Keyboard"
 *     false-positive pattern the findings doc documents --
 *     client_finance_classify_hardware() below. Every catalog-driven
 *     classification records its confidence ('clean' vs. 'keyword') on
 *     the opportunities row so a false positive can be told apart from a
 *     clean match later if one ever surfaces on real data.
 *
 * One deliberate reuse-not-a-new-guess: Credits' "invoice-level detail of
 * what was credited" fetches product lines for CreditMemo invoices via
 * the SAME /procurement/products?conditions=invoice/id=N linkage already
 * confirmed generic (every product line carries its own `invoice: {id}`
 * regardless of how that invoice originated -- findings doc #5). This was
 * only directly observed on a SalesOrder-sourced invoice, not a
 * CreditMemo one specifically, so if a given credit memo turns out to
 * have no matching product rows (e.g. a straight dollar credit with no
 * catalog items behind it), that invoice just shows its own total with no
 * line breakdown -- it fails soft, not loud.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../../relationships/api/connectwise.php';

const CLIENT_FINANCE_STANDARD_MONTHS_BACK = 7;      // 6 full months + current partial
const CLIENT_FINANCE_CREDITS_MONTHS_BACK = 7;
const CLIENT_FINANCE_PRODUCT_SALES_MONTHS_BACK = 25; // this year-to-date + full prior year, for YoY
const CLIENT_FINANCE_AGREEMENTS_MONTHS_BACK = 13;    // 12 full months + current partial, for the trend chart
const CLIENT_FINANCE_SECTIONS = ['standard', 'product_sales', 'agreements', 'credits', 'opportunities'];

/** Clean-signal subcategories -- see findings doc #6. */
const CLIENT_FINANCE_COMPUTER_SUBCATS = ['Desktop', 'Laptop', 'Server', 'Servers', 'Workstation'];
const CLIENT_FINANCE_NETWORK_SUBCATS = ['Network Device'];
/** The two newer catch-all subcategories that get the keyword fallback instead of a clean match. */
const CLIENT_FINANCE_CATCHALL_SUBCATS = ['IT HW/SW Sold', 'Premise HW/SW Sold'];

// ---------------------------------------------------------------------
// Classification
// ---------------------------------------------------------------------

function client_finance_classify_invoice_category(string $type, string $applyToType): string
{
    if ($type === 'CreditMemo') {
        return 'credit';
    }
    if ($applyToType === 'SalesOrder') {
        return 'sales_order';
    }
    if ($applyToType === 'Agreement') {
        return 'agreement';
    }
    if ($type === 'Standard' || $type === 'Miscellaneous') {
        return 'standard';
    }
    return 'other'; // DownPayment and the rare Progress row -- excluded from every section
}

/**
 * Computer vs. Networking classification for one Procurement Catalog
 * item. Returns null when the item isn't hardware this scan cares about.
 * `confidence` is 'clean' for the two unambiguous subcategory signals and
 * 'keyword' for the catch-all-subcategory + description-keyword fallback
 * Michael asked for on 2026-10-16 -- see this file's header and findings
 * doc #6 for exactly which real items drove each keyword choice.
 *
 * @return array{category: string, confidence: string}|null
 */
function client_finance_classify_hardware(string $subcategory, string $description): ?array
{
    if (in_array($subcategory, CLIENT_FINANCE_COMPUTER_SUBCATS, true)) {
        return ['category' => 'computer', 'confidence' => 'clean'];
    }
    if (in_array($subcategory, CLIENT_FINANCE_NETWORK_SUBCATS, true)) {
        return ['category' => 'networking', 'confidence' => 'clean'];
    }
    if (!in_array($subcategory, CLIENT_FINANCE_CATCHALL_SUBCATS, true)) {
        return null;
    }

    // Brand/model terms specific enough to always mean real hardware, even
    // alongside an accessory/service word (findings doc: HPE ProLiant fan
    // kits and ProSupport lines are the one place this still needs the
    // negation check too, so it runs through the same gate below).
    static $compStrong = '/\b(optiplex|latitude|proliant|poweredge)\b/i';
    static $netStrong = '/\b(sonicwall|aironet|firewall|unified\s+threat|security\s+appliance|fortiswitch|fortigate)\b/i';
    // Generic terms that need the negation check -- "desktop"/"workstation"
    // alone catch desktop printers and server shelves too (see findings doc).
    static $compWeak = '/\b(laptop|notebook|workstation|desktop\s+computer|all-in-one\s+computer)\b/i';
    static $netWeak = '/\b(router|switch|access\s+point|network\s+card)\b/i';
    // Accessories, parts, services, warranties, software/subscriptions --
    // the "Desktop Switch" / "Wireless Keyboard" false-positive family,
    // widened after the HPE fan-kit / SonicWall-subscription findings.
    static $neg = '/\b(charger|keyboard|mouse|case|sleeve|bag|stand|mount|dock(?:ing\s+station)?|'
        . 'screen\s+protector|cable|adapter|bracket|shelf|cabinet|enclosure|pdu|'
        . 'hdmi|kvm|vga|matrix|video\s+bar|battery|batteries|fan\s+kit|cpu\s+fan|'
        . 'enablement\s+kit|\bkit\b|install(?:ation)?|configure|\blabor\b|warranty|'
        . 'prosupport|protection\s+plan|\bupgrade\b|subscription|\blicense\b|renewal|'
        . 'service\s+suite|\breport(?:ing)?\b|high\s+availability|secure\s+upgrade|'
        . 'totalsecure|advanced\s+edition|next\s+business\s+day|\bnbd\b|support\s+suite|'
        . 'care\s+pack|managed\s+service|maintenance)\b/i';

    if (preg_match($neg, $description) === 1) {
        return null;
    }
    if (preg_match($compStrong, $description) === 1) {
        return ['category' => 'computer', 'confidence' => 'keyword'];
    }
    if (preg_match($netStrong, $description) === 1) {
        return ['category' => 'networking', 'confidence' => 'keyword'];
    }
    if (preg_match($compWeak, $description) === 1) {
        return ['category' => 'computer', 'confidence' => 'keyword'];
    }
    if (preg_match($netWeak, $description) === 1) {
        return ['category' => 'networking', 'confidence' => 'keyword'];
    }
    return null;
}

// ---------------------------------------------------------------------
// ConnectWise helpers
// ---------------------------------------------------------------------

/**
 * Product-line rows for a batch of invoice ids, grouped by invoice id.
 * /procurement/products?conditions=invoice/id in (...) first (one round
 * trip); falls back to one request per id if the batched form ever comes
 * back empty for a chunk that should have real rows (same defensive
 * shape as commissions_cw_rows_for_invoices(), just without its 24h
 * cross-request trust cache -- a single customer's line count is small
 * enough that always trying the batch first costs nothing).
 *
 * @return array<int,array> invoice id => list of raw product-line rows
 */
function client_finance_products_for_invoices(array $invoiceIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $invoiceIds))));
    $grouped = array_fill_keys($ids, []);
    if ($ids === []) {
        return $grouped;
    }

    foreach (array_chunk($ids, 40) as $chunk) {
        try {
            $rows = relationships_cw_list('/procurement/products', 'invoice/id in (' . implode(',', $chunk) . ')', [
                'id', 'catalogItem', 'description', 'customerDescription', 'quantity', 'unitOfMeasure',
                'price', 'cost', 'listPrice', 'salesOrder', 'opportunity', 'invoice', 'cancelledFlag',
            ]);
            $matched = [];
            foreach ($rows as $row) {
                $iid = (int) ($row['invoice']['id'] ?? 0);
                if (isset($grouped[$iid])) {
                    $grouped[$iid][] = $row;
                    $matched[$iid] = true;
                }
            }
            // Only fall back for ids the batch call genuinely missed.
            $missing = array_diff($chunk, array_keys($matched));
        } catch (Throwable $e) {
            $missing = $chunk;
        }
        foreach ($missing as $id) {
            try {
                $grouped[$id] = relationships_cw_list('/procurement/products', 'invoice/id=' . $id, [
                    'id', 'catalogItem', 'description', 'customerDescription', 'quantity', 'unitOfMeasure',
                    'price', 'cost', 'listPrice', 'salesOrder', 'opportunity', 'invoice', 'cancelledFlag',
                ]);
            } catch (Throwable $e) {
                // leave empty -- this invoice just shows its own total, no line breakdown
            }
        }
    }
    return $grouped;
}

/** Procurement Catalog items by id -- identifier, description, category/subcategory names. */
function client_finance_catalog_lookup(array $catalogIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $catalogIds))));
    $out = [];
    foreach (array_chunk($ids, 40) as $chunk) {
        if ($chunk === []) {
            continue;
        }
        try {
            $rows = relationships_cw_list('/procurement/catalog', 'id in (' . implode(',', $chunk) . ')', [
                'id', 'identifier', 'description', 'category', 'subcategory',
            ]);
        } catch (Throwable $e) {
            continue;
        }
        foreach ($rows as $r) {
            $cid = (int) ($r['id'] ?? 0);
            if ($cid <= 0) {
                continue;
            }
            $out[$cid] = [
                'identifier' => (string) ($r['identifier'] ?? ''),
                'description' => (string) ($r['description'] ?? ''),
                'category' => (string) ($r['category']['name'] ?? ''),
                'subcategory' => (string) ($r['subcategory']['name'] ?? ''),
            ];
        }
    }
    return $out;
}

/** Inserts the product-line rows for one invoice, resolving catalog classification as it goes. */
function client_finance_store_product_lines(PDO $pdo, int $invoiceId, array $rows, array $catalog): void
{
    $ins = $pdo->prepare(
        'INSERT INTO invoice_lines (invoice_id, kind, description, product_identifier, catalog_id, catalog_category, catalog_subcategory,
                                     qty, unit_cost, unit_price, cost, price, sales_order_id, sales_order_identifier, opportunity_id, opportunity_name)
         VALUES (:iid, \'product\', :d, :pi, :cid, :cc, :cs, :q, :uc, :up, :c, :p, :soid, :soident, :oppid, :oppname)'
    );
    foreach ($rows as $r) {
        $catalogId = (int) ($r['catalogItem']['id'] ?? 0);
        $cat = $catalog[$catalogId] ?? null;
        $qty = (float) ($r['quantity'] ?? 1);
        $unitPrice = (float) ($r['price'] ?? 0);
        $unitCost = (float) ($r['cost'] ?? 0);
        $ins->execute([
            ':iid' => $invoiceId,
            ':d' => (string) ($r['customerDescription'] ?? $r['description'] ?? ($cat['description'] ?? '')),
            ':pi' => (string) ($r['catalogItem']['identifier'] ?? ($cat['identifier'] ?? '')),
            ':cid' => $catalogId > 0 ? $catalogId : null,
            ':cc' => $cat['category'] ?? null,
            ':cs' => $cat['subcategory'] ?? null,
            ':q' => $qty,
            ':uc' => $unitCost,
            ':up' => $unitPrice,
            ':c' => $unitCost * $qty,
            ':p' => $unitPrice * $qty,
            ':soid' => isset($r['salesOrder']['id']) ? (int) $r['salesOrder']['id'] : null,
            ':soident' => (string) ($r['salesOrder']['identifier'] ?? ''),
            ':oppid' => isset($r['opportunity']['id']) ? (int) $r['opportunity']['id'] : null,
            ':oppname' => (string) ($r['opportunity']['name'] ?? ''),
        ]);
    }
}

// ---------------------------------------------------------------------
// start() / step()
// ---------------------------------------------------------------------

/** Wipes this company's previously-synced data and queues all 5 sections fresh. */
function client_finance_sync_start(PDO $pdo, int $cwCompanyId, string $companyName): array
{
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM invoices WHERE cw_company_id = :c')->execute([':c' => $cwCompanyId]); // cascades invoice_lines, opportunities
        $pdo->prepare('DELETE FROM agreements WHERE cw_company_id = :c')->execute([':c' => $cwCompanyId]); // cascades agreement_additions
        $pdo->prepare('DELETE FROM sync_queue WHERE cw_company_id = :c')->execute([':c' => $cwCompanyId]);
        $pdo->prepare(
            'INSERT INTO company_sync_state (cw_company_id, company_name, standard_synced_at, product_sales_synced_at, agreements_synced_at, credits_synced_at, opportunities_synced_at, last_error)
             VALUES (:c, :n, NULL, NULL, NULL, NULL, NULL, NULL)
             ON CONFLICT(cw_company_id) DO UPDATE SET company_name = excluded.company_name, standard_synced_at = NULL,
               product_sales_synced_at = NULL, agreements_synced_at = NULL, credits_synced_at = NULL, opportunities_synced_at = NULL, last_error = NULL'
        )->execute([':c' => $cwCompanyId, ':n' => $companyName]);
        $insQ = $pdo->prepare("INSERT INTO sync_queue (cw_company_id, section, status) VALUES (:c, :s, 'pending')");
        foreach (CLIENT_FINANCE_SECTIONS as $section) {
            $insQ->execute([':c' => $cwCompanyId, ':s' => $section]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return ['queued' => count(CLIENT_FINANCE_SECTIONS)];
}

/** Processes the oldest pending section (any company). One call = one section.
 * Returns `cw_company_id` alongside `section` (added 2026-10-21 for
 * client-finance-cron.php's error logging across a bulk run spanning
 * many companies) -- sync.php's frontend caller already just forwards
 * this whole array through unread fields, so this is additive, not a
 * behavior change for the on-demand "Sync now" path. */
function client_finance_sync_step(PDO $pdo): array
{
    $row = $pdo->query("SELECT id, cw_company_id, section FROM sync_queue WHERE status = 'pending' ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        return ['done' => true, 'remaining' => 0, 'section' => null];
    }

    $queueId = (int) $row['id'];
    $cwCompanyId = (int) $row['cw_company_id'];
    $section = (string) $row['section'];
    $syncedAtCol = $section . '_synced_at';

    try {
        switch ($section) {
            case 'standard':
                client_finance_sync_standard($pdo, $cwCompanyId);
                break;
            case 'product_sales':
                client_finance_sync_product_sales($pdo, $cwCompanyId);
                break;
            case 'agreements':
                client_finance_sync_agreements($pdo, $cwCompanyId);
                break;
            case 'credits':
                client_finance_sync_credits($pdo, $cwCompanyId);
                break;
            case 'opportunities':
                client_finance_sync_opportunities($pdo, $cwCompanyId);
                break;
        }
        $pdo->prepare("UPDATE sync_queue SET status = 'done' WHERE id = :id")->execute([':id' => $queueId]);
        $pdo->prepare("UPDATE company_sync_state SET $syncedAtCol = :t, last_error = NULL WHERE cw_company_id = :c")
            ->execute([':t' => gmdate('c'), ':c' => $cwCompanyId]);
    } catch (Throwable $e) {
        $msg = substr($e->getMessage(), 0, 400);
        $pdo->prepare("UPDATE sync_queue SET status = 'error', error_message = :e WHERE id = :id")->execute([':e' => $msg, ':id' => $queueId]);
        $pdo->prepare('UPDATE company_sync_state SET last_error = :e WHERE cw_company_id = :c')->execute([':e' => "$section: $msg", ':c' => $cwCompanyId]);
        $remaining = (int) $pdo->query("SELECT COUNT(*) FROM sync_queue WHERE status = 'pending'")->fetchColumn();
        return ['done' => $remaining === 0, 'remaining' => $remaining, 'section' => $section, 'cw_company_id' => $cwCompanyId, 'error' => $msg];
    }

    $remaining = (int) $pdo->query("SELECT COUNT(*) FROM sync_queue WHERE status = 'pending'")->fetchColumn();
    return ['done' => $remaining === 0, 'remaining' => $remaining, 'section' => $section, 'cw_company_id' => $cwCompanyId];
}

// ---------------------------------------------------------------------
// Section 1 -- Standard Invoices (T&M)
// ---------------------------------------------------------------------

function client_finance_sync_standard(PDO $pdo, int $cwCompanyId): void
{
    $cutoff = gmdate('Y-m-d\TH:i:s\Z', strtotime('-' . CLIENT_FINANCE_STANDARD_MONTHS_BACK . ' months'));
    $rows = relationships_cw_list('/finance/invoices',
        "company/id=$cwCompanyId and date>=[$cutoff] and (type='Standard' or type='Miscellaneous')",
        ['id', 'invoiceNumber', 'date', 'type', 'applyToType', 'applyToId', 'total']);

    $ins = $pdo->prepare(
        'INSERT INTO invoices (id, cw_company_id, invoice_number, invoice_date, month, cw_type, cw_apply_to_type, category, agreement_id, total, synced_at)
         VALUES (:id, :c, :num, :d, :m, :t, :att, :cat, NULL, :tot, :sa)
         ON CONFLICT(id) DO UPDATE SET invoice_number = excluded.invoice_number, invoice_date = excluded.invoice_date, month = excluded.month,
           cw_type = excluded.cw_type, cw_apply_to_type = excluded.cw_apply_to_type, category = excluded.category, total = excluded.total, synced_at = excluded.synced_at'
    );
    foreach ($rows as $r) {
        $type = (string) ($r['type'] ?? '');
        $applyToType = (string) ($r['applyToType'] ?? '');
        if (client_finance_classify_invoice_category($type, $applyToType) !== 'standard') {
            continue; // same raw type, but it's really a SalesOrder/Agreement-applied row -- not this section
        }
        $date = substr((string) ($r['date'] ?? ''), 0, 10);
        $ins->execute([
            ':id' => (int) $r['id'], ':c' => $cwCompanyId, ':num' => (string) ($r['invoiceNumber'] ?? ''),
            ':d' => $date, ':m' => substr($date, 0, 7), ':t' => $type, ':att' => $applyToType,
            ':cat' => 'standard', ':tot' => (float) ($r['total'] ?? 0), ':sa' => gmdate('c'),
        ]);
    }
}

// ---------------------------------------------------------------------
// Section 2 -- Product Sales (Sales Orders)
// ---------------------------------------------------------------------

function client_finance_sync_product_sales(PDO $pdo, int $cwCompanyId): void
{
    $cutoff = gmdate('Y-m-d\TH:i:s\Z', strtotime('-' . CLIENT_FINANCE_PRODUCT_SALES_MONTHS_BACK . ' months'));
    $rows = relationships_cw_list('/finance/invoices',
        "company/id=$cwCompanyId and date>=[$cutoff] and applyToType='SalesOrder'",
        ['id', 'invoiceNumber', 'date', 'type', 'applyToType', 'applyToId', 'total', 'salesOrder']);

    if ($rows === []) {
        return;
    }

    $insInv = $pdo->prepare(
        'INSERT INTO invoices (id, cw_company_id, invoice_number, invoice_date, month, cw_type, cw_apply_to_type, category, agreement_id, total, synced_at)
         VALUES (:id, :c, :num, :d, :m, :t, :att, \'sales_order\', NULL, :tot, :sa)
         ON CONFLICT(id) DO UPDATE SET invoice_number = excluded.invoice_number, invoice_date = excluded.invoice_date, month = excluded.month,
           cw_type = excluded.cw_type, cw_apply_to_type = excluded.cw_apply_to_type, category = \'sales_order\', total = excluded.total, synced_at = excluded.synced_at'
    );
    $invoiceDates = [];
    foreach ($rows as $r) {
        $id = (int) $r['id'];
        $date = substr((string) ($r['date'] ?? ''), 0, 10);
        $invoiceDates[$id] = $date;
        $insInv->execute([
            ':id' => $id, ':c' => $cwCompanyId, ':num' => (string) ($r['invoiceNumber'] ?? ''),
            ':d' => $date, ':m' => substr($date, 0, 7), ':t' => (string) ($r['type'] ?? ''),
            ':att' => (string) ($r['applyToType'] ?? ''), ':tot' => (float) ($r['total'] ?? 0), ':sa' => gmdate('c'),
        ]);
    }

    $pdo->prepare('DELETE FROM invoice_lines WHERE invoice_id IN (' . implode(',', array_keys($invoiceDates)) . ") AND kind = 'product'")->execute();
    $productsByInvoice = client_finance_products_for_invoices(array_keys($invoiceDates));

    $catalogIds = [];
    foreach ($productsByInvoice as $lines) {
        foreach ($lines as $l) {
            $catalogIds[] = (int) ($l['catalogItem']['id'] ?? 0);
        }
    }
    $catalog = client_finance_catalog_lookup($catalogIds);

    foreach ($productsByInvoice as $invoiceId => $lines) {
        client_finance_store_product_lines($pdo, $invoiceId, $lines, $catalog);
    }
}

// ---------------------------------------------------------------------
// Section 3 -- Agreement Invoicing
// ---------------------------------------------------------------------

function client_finance_sync_agreements(PDO $pdo, int $cwCompanyId): void
{
    $agreements = relationships_cw_list('/finance/agreements', "company/id=$cwCompanyId", [
        'id', 'name', 'type', 'agreementStatus', 'startDate', 'cancelledDate',
    ]);

    $insAg = $pdo->prepare(
        'INSERT INTO agreements (id, cw_company_id, name, type_id, type_name, status, start_date, cancelled_date, synced_at)
         VALUES (:id, :c, :n, :tid, :tn, :st, :sd, :cd, :sa)
         ON CONFLICT(id) DO UPDATE SET name = excluded.name, type_id = excluded.type_id, type_name = excluded.type_name,
           status = excluded.status, start_date = excluded.start_date, cancelled_date = excluded.cancelled_date, synced_at = excluded.synced_at'
    );
    $insAdd = $pdo->prepare(
        'INSERT INTO agreement_additions (agreement_id, cw_addition_id, product_identifier, description, qty, unit_cost, unit_price, start_date, cancelled_date)
         VALUES (:aid, :caid, :pi, :d, :q, :uc, :up, :sd, :cd)'
    );

    foreach ($agreements as $a) {
        $agreementId = (int) $a['id'];
        $insAg->execute([
            ':id' => $agreementId, ':c' => $cwCompanyId, ':n' => (string) ($a['name'] ?? ''),
            ':tid' => isset($a['type']['id']) ? (int) $a['type']['id'] : null, ':tn' => (string) ($a['type']['name'] ?? ''),
            ':st' => (string) ($a['agreementStatus'] ?? ''), ':sd' => substr((string) ($a['startDate'] ?? ''), 0, 10) ?: null,
            ':cd' => substr((string) ($a['cancelledDate'] ?? ''), 0, 10) ?: null, ':sa' => gmdate('c'),
        ]);

        $additions = relationships_cw_list("/finance/agreements/$agreementId/additions", '', [
            'id', 'product', 'description', 'invoiceDescription', 'quantity', 'unitCost', 'unitPrice',
            'effectiveDate', 'cancelledDate', 'additionStatus', 'billCustomer',
        ]);
        foreach ($additions as $ad) {
            $insAdd->execute([
                ':aid' => $agreementId, ':caid' => (int) $ad['id'],
                ':pi' => (string) ($ad['product']['identifier'] ?? ''),
                ':d' => (string) ($ad['invoiceDescription'] ?? $ad['description'] ?? ''),
                ':q' => (float) ($ad['quantity'] ?? 1), ':uc' => (float) ($ad['unitCost'] ?? 0), ':up' => (float) ($ad['unitPrice'] ?? 0),
                ':sd' => substr((string) ($ad['effectiveDate'] ?? ''), 0, 10) ?: null,
                ':cd' => substr((string) ($ad['cancelledDate'] ?? ''), 0, 10) ?: null,
            ]);
        }
    }

    // Agreement-applied invoices, for the trend chart.
    $cutoff = gmdate('Y-m-d\TH:i:s\Z', strtotime('-' . CLIENT_FINANCE_AGREEMENTS_MONTHS_BACK . ' months'));
    $rows = relationships_cw_list('/finance/invoices',
        "company/id=$cwCompanyId and date>=[$cutoff] and applyToType='Agreement'",
        ['id', 'invoiceNumber', 'date', 'type', 'applyToType', 'applyToId', 'total']);
    $insInv = $pdo->prepare(
        'INSERT INTO invoices (id, cw_company_id, invoice_number, invoice_date, month, cw_type, cw_apply_to_type, category, agreement_id, total, synced_at)
         VALUES (:id, :c, :num, :d, :m, :t, :att, \'agreement\', :aid, :tot, :sa)
         ON CONFLICT(id) DO UPDATE SET invoice_number = excluded.invoice_number, invoice_date = excluded.invoice_date, month = excluded.month,
           cw_type = excluded.cw_type, cw_apply_to_type = excluded.cw_apply_to_type, category = \'agreement\', agreement_id = excluded.agreement_id,
           total = excluded.total, synced_at = excluded.synced_at'
    );
    foreach ($rows as $r) {
        $type = (string) ($r['type'] ?? '');
        $applyToType = (string) ($r['applyToType'] ?? '');
        if (client_finance_classify_invoice_category($type, $applyToType) !== 'agreement') {
            continue; // the rare CreditMemo|Agreement edge case (findings doc #1) belongs to Credits, not here
        }
        $date = substr((string) ($r['date'] ?? ''), 0, 10);
        $insInv->execute([
            ':id' => (int) $r['id'], ':c' => $cwCompanyId, ':num' => (string) ($r['invoiceNumber'] ?? ''),
            ':d' => $date, ':m' => substr($date, 0, 7), ':t' => $type,
            ':att' => $applyToType, ':aid' => (int) ($r['applyToId'] ?? 0) ?: null,
            ':tot' => (float) ($r['total'] ?? 0), ':sa' => gmdate('c'),
        ]);
    }
}

// ---------------------------------------------------------------------
// Section 4 -- Credits
// ---------------------------------------------------------------------

function client_finance_sync_credits(PDO $pdo, int $cwCompanyId): void
{
    $cutoff = gmdate('Y-m-d\TH:i:s\Z', strtotime('-' . CLIENT_FINANCE_CREDITS_MONTHS_BACK . ' months'));
    $rows = relationships_cw_list('/finance/invoices',
        "company/id=$cwCompanyId and date>=[$cutoff] and type='CreditMemo'",
        ['id', 'invoiceNumber', 'date', 'type', 'applyToType', 'total']);

    if ($rows === []) {
        return;
    }

    $insInv = $pdo->prepare(
        'INSERT INTO invoices (id, cw_company_id, invoice_number, invoice_date, month, cw_type, cw_apply_to_type, category, agreement_id, total, synced_at)
         VALUES (:id, :c, :num, :d, :m, :t, :att, \'credit\', NULL, :tot, :sa)
         ON CONFLICT(id) DO UPDATE SET invoice_number = excluded.invoice_number, invoice_date = excluded.invoice_date, month = excluded.month,
           cw_type = excluded.cw_type, cw_apply_to_type = excluded.cw_apply_to_type, category = \'credit\', total = excluded.total, synced_at = excluded.synced_at'
    );
    $invoiceDates = [];
    foreach ($rows as $r) {
        $id = (int) $r['id'];
        $date = substr((string) ($r['date'] ?? ''), 0, 10);
        $invoiceDates[$id] = $date;
        $insInv->execute([
            ':id' => $id, ':c' => $cwCompanyId, ':num' => (string) ($r['invoiceNumber'] ?? ''),
            ':d' => $date, ':m' => substr($date, 0, 7), ':t' => (string) ($r['type'] ?? ''),
            ':att' => (string) ($r['applyToType'] ?? ''), ':tot' => (float) ($r['total'] ?? 0), ':sa' => gmdate('c'),
        ]);
    }

    // Reuse the confirmed invoice->product-lines linkage -- see this
    // file's header for why this is a reuse, not a new guess, and what
    // happens when a given credit memo has no matching product rows.
    $pdo->prepare('DELETE FROM invoice_lines WHERE invoice_id IN (' . implode(',', array_keys($invoiceDates)) . ") AND kind = 'product'")->execute();
    $productsByInvoice = client_finance_products_for_invoices(array_keys($invoiceDates));
    $catalogIds = [];
    foreach ($productsByInvoice as $lines) {
        foreach ($lines as $l) {
            $catalogIds[] = (int) ($l['catalogItem']['id'] ?? 0);
        }
    }
    $catalog = client_finance_catalog_lookup($catalogIds);
    foreach ($productsByInvoice as $invoiceId => $lines) {
        if ($lines !== []) {
            client_finance_store_product_lines($pdo, $invoiceId, $lines, $catalog);
        }
    }
}

// ---------------------------------------------------------------------
// Section 5 -- Opportunities (Computer/Networking hardware, 3+ years old)
// ---------------------------------------------------------------------

function client_finance_sync_opportunities(PDO $pdo, int $cwCompanyId): void
{
    // Unbounded on purpose -- this scan is explicitly about OLD sales, so
    // it can't share Product Sales' ~2-year window. Re-fetches every
    // SalesOrder-sourced invoice for the company's full history; the
    // UPSERT on `invoices` means this never conflicts with what Section 2
    // already wrote for the recent window.
    $rows = relationships_cw_list('/finance/invoices',
        "company/id=$cwCompanyId and applyToType='SalesOrder'",
        ['id', 'invoiceNumber', 'date', 'type', 'applyToType', 'total']);

    $pdo->prepare('DELETE FROM opportunities WHERE cw_company_id = :c')->execute([':c' => $cwCompanyId]);
    if ($rows === []) {
        return;
    }

    $insInv = $pdo->prepare(
        'INSERT INTO invoices (id, cw_company_id, invoice_number, invoice_date, month, cw_type, cw_apply_to_type, category, agreement_id, total, synced_at)
         VALUES (:id, :c, :num, :d, :m, :t, :att, \'sales_order\', NULL, :tot, :sa)
         ON CONFLICT(id) DO UPDATE SET invoice_number = excluded.invoice_number, invoice_date = excluded.invoice_date, month = excluded.month,
           cw_type = excluded.cw_type, cw_apply_to_type = excluded.cw_apply_to_type, category = \'sales_order\', total = excluded.total, synced_at = excluded.synced_at'
    );
    $invoiceDates = [];
    foreach ($rows as $r) {
        $id = (int) $r['id'];
        $date = substr((string) ($r['date'] ?? ''), 0, 10);
        $invoiceDates[$id] = $date;
        $insInv->execute([
            ':id' => $id, ':c' => $cwCompanyId, ':num' => (string) ($r['invoiceNumber'] ?? ''),
            ':d' => $date, ':m' => substr($date, 0, 7), ':t' => (string) ($r['type'] ?? ''),
            ':att' => (string) ($r['applyToType'] ?? ''), ':tot' => (float) ($r['total'] ?? 0), ':sa' => gmdate('c'),
        ]);
    }

    $pdo->prepare('DELETE FROM invoice_lines WHERE invoice_id IN (' . implode(',', array_keys($invoiceDates)) . ") AND kind = 'product'")->execute();
    $productsByInvoice = client_finance_products_for_invoices(array_keys($invoiceDates));
    $catalogIds = [];
    foreach ($productsByInvoice as $lines) {
        foreach ($lines as $l) {
            $catalogIds[] = (int) ($l['catalogItem']['id'] ?? 0);
        }
    }
    $catalog = client_finance_catalog_lookup($catalogIds);
    foreach ($productsByInvoice as $invoiceId => $lines) {
        client_finance_store_product_lines($pdo, $invoiceId, $lines, $catalog);
    }

    $thresholdYears = (float) client_finance_setting($pdo, 'opportunity_years_threshold', '3');
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

    $lineRows = $pdo->prepare(
        'SELECT il.id, il.catalog_subcategory, il.description, i.invoice_date
         FROM invoice_lines il JOIN invoices i ON i.id = il.invoice_id
         WHERE i.cw_company_id = :c AND i.category = \'sales_order\' AND il.kind = \'product\' AND il.catalog_id IS NOT NULL'
    );
    $lineRows->execute([':c' => $cwCompanyId]);

    $insOpp = $pdo->prepare(
        'INSERT INTO opportunities (cw_company_id, invoice_line_id, category, subcategory, match_confidence, sold_date, years_ago, status, created_at)
         VALUES (:c, :lid, :cat, :sub, :conf, :sd, :ya, \'open\', :ca)'
    );
    foreach ($lineRows->fetchAll(PDO::FETCH_ASSOC) as $line) {
        $match = client_finance_classify_hardware((string) $line['catalog_subcategory'], (string) $line['description']);
        if ($match === null) {
            continue;
        }
        $soldDate = (string) $line['invoice_date'];
        if ($soldDate === '') {
            continue;
        }
        $years = ($now->getTimestamp() - (new DateTimeImmutable($soldDate, new DateTimeZone('UTC')))->getTimestamp()) / 31557600.0; // 365.25 days
        if ($years < $thresholdYears) {
            continue;
        }
        $insOpp->execute([
            ':c' => $cwCompanyId, ':lid' => (int) $line['id'], ':cat' => $match['category'],
            ':sub' => (string) $line['catalog_subcategory'], ':conf' => $match['confidence'],
            ':sd' => $soldDate, ':ya' => round($years, 1), ':ca' => gmdate('c'),
        ]);
    }
}
