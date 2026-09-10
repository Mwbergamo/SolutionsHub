<?php
/**
 * relationships/api/connectwise-classify.php
 *
 * Maps a ConnectWise agreement addition (product identifier + description)
 * to one of this dashboard's pillar_id/service_id pairs (catalog.php).
 *
 * ConnectWise's own product Category/Subcategory fields turned out NOT to
 * be a reliable signal here -- this instance's catalog has ~20 years of
 * inconsistently-applied categorization, so a product's category is more
 * about how old it is than what it does. Classification below instead
 * matches on the product identifier / description text itself, built from
 * a real sample of CodeBlue's live active agreement additions and
 * confirmed against Michael directly (2026-09-10) for the pieces that
 * matter to the cross-sell mechanism:
 *
 *   - Every "Provided Equipment" product identifier starts with "PE-"
 *     (confirmed -- this is the authoritative rule for provided-equipment).
 *   - DUO-MFA (Multi-Factor Authentication) is Cyber Security (confirmed).
 *   - Ancillary IT Services line items (M365 licensing, hosted email,
 *     website hosting, domain registration, Azure Info Protection, and
 *     anything else that doesn't match a more specific rule below) fall
 *     under IT Services generally and aren't their own pillar/service --
 *     they're the Managed IT catch-all (confirmed).
 *
 * Precision matters most for the 6 cross-sell-eligible services
 * (relationships_cross_sell_map() in catalog.php) -- getting one of those
 * wrong either hides a real cross-sell opportunity or manufactures a fake
 * one. It matters less for Data Center and the non-cross-sell IT/VoIP/
 * Security services, which are classified here on a best-effort basis
 * (pillar-level accuracy, i.e. the pillar tile lights up correctly) rather
 * than a fully verified one -- there was no live agreement data clean
 * enough to nail those with confidence, and they don't drive the
 * checklist/report mechanism. Data Cabling isn't synced at all: no
 * ConnectWise agreement type maps to it.
 */

declare(strict_types=1);

/**
 * @return array{pillar_id: string, service_id: string}|null  null means
 *   "skip this addition" -- used only for Premise Security, where an
 *   unrecognized item is left out rather than guessed at (that pillar
 *   currently has exactly one active real agreement, so there's no
 *   meaningful "common case" to default to).
 */
function relationships_cw_classify(int $agreementTypeId, string $productIdentifier, string $description, string $invoiceDescription): ?array
{
    $haystack = strtolower($productIdentifier . ' ' . $description . ' ' . $invoiceDescription);

    switch ($agreementTypeId) {
        case 65: // IT Services Agreement
            return ['pillar_id' => 'it', 'service_id' => relationships_cw_classify_it($productIdentifier, $haystack)];

        case 66: // Voice Agreement
            return ['pillar_id' => 'voip', 'service_id' => relationships_cw_classify_voip($haystack)];

        case 67: // Premise Security
            $service = relationships_cw_classify_security($haystack);
            return $service === null ? null : ['pillar_id' => 'security', 'service_id' => $service];

        case 64: // Data Center Agreement
            return ['pillar_id' => 'dc', 'service_id' => relationships_cw_classify_dc($haystack)];

        default:
            return null;
    }
}

function relationships_cw_classify_it(string $productIdentifier, string $haystack): string
{
    // Authoritative: every Provided Equipment product identifier starts
    // with "PE-".
    if (stripos($productIdentifier, 'PE-') === 0) {
        return 'provided-equipment';
    }

    static $cyberSecurityExact = [
        'cbt-ms-av-edr', 'cbt-ms-av-basic-workstation', 'sent-one-ctrl',
        'se-man-antispam', 'duo-mfa',
    ];
    $idLower = strtolower($productIdentifier);
    if (in_array($idLower, $cyberSecurityExact, true)) {
        return 'cyber-security';
    }

    // SonicWall subscription/renewal SKUs (Total Secure / threat
    // protection / Capture Client / Cloud App Security) and web filtering.
    if (preg_match('/^0[12]-ssc-/', $idLower) === 1 || strpos($idLower, 's2webfilter') === 0) {
        return 'cyber-security';
    }

    static $cyberSecurityKeywords = [
        'anti-virus', 'antivirus', 'endpoint detection', 'edr',
        'anti-spam', 'antispam', 'multi-factor authentication',
        'web filter', 'webfilter', 'threat protection', 'capture client',
        'cloud application security',
    ];
    foreach ($cyberSecurityKeywords as $kw) {
        if (strpos($haystack, $kw) !== false) {
            return 'cyber-security';
        }
    }

    // Everything else on an active IT Services Agreement -- monitoring
    // agents, patch management, backup, managed maintenance, and the
    // ancillary items (M365, hosted email, website hosting, domain
    // registration, Azure Info Protection, etc.) -- is the Managed IT
    // catch-all, per Michael's confirmation that those ancillary items
    // "fall under IT Services" rather than any other pillar/service.
    return 'managed-it';
}

function relationships_cw_classify_voip(string $haystack): string
{
    if (strpos($haystack, 'sip trunk') !== false || strpos($haystack, 'sip-unlimted') !== false || strpos($haystack, 'sip-unlimited') !== false) {
        return 'sip-trunking';
    }
    if (strpos($haystack, 'zultys') !== false) {
        return 'premise-voice';
    }
    if (strpos($haystack, 'provided voice hardware') !== false || strpos($haystack, 'phone hardware') !== false) {
        return 'phone-hardware';
    }
    if (strpos($haystack, 'conference room') !== false || strpos($haystack, 'conference phone') !== false) {
        return 'conference-room';
    }
    if (
        strpos($haystack, 'codeblue cloud voice') !== false
        || strpos($haystack, 'bcmone') !== false
        || strpos($haystack, 'intermedia') !== false
        || strpos($haystack, 'teams voice') !== false
        || strpos($haystack, 'microsoft teams voice') !== false
    ) {
        return 'cloud-voice';
    }

    // Anything unrecognized (On-Hold Marketing, misc line items) defaults
    // to premise-voice rather than cloud-voice -- cloud-voice is the one
    // cross-sell-tracked VoIP service, so an unsure guess should never
    // land there and falsely suggest a customer already has it.
    return 'premise-voice';
}

function relationships_cw_classify_security(string $haystack): ?string
{
    if (strpos($haystack, 'camera') !== false || strpos($haystack, 'video') !== false) {
        return 'ip-cameras';
    }
    if (strpos($haystack, 'access control') !== false || strpos($haystack, 'door') !== false) {
        return 'access-control';
    }
    return null;
}

function relationships_cw_classify_dc(string $haystack): string
{
    if (strpos($haystack, 'disaster recovery') !== false || strpos($haystack, 'veeam replication') !== false || strpos($haystack, 'cloud dr') !== false) {
        return 'disaster-recovery';
    }
    if (strpos($haystack, 'rack') !== false) {
        return 'hardware-hosting';
    }
    if (strpos($haystack, 'bandwidth') !== false || strpos($haystack, 'internet') !== false) {
        return 'internet-sourcing';
    }
    if (strpos($haystack, 'azure') !== false || strpos($haystack, 'aws') !== false || strpos($haystack, 'public cloud') !== false) {
        return 'public-cloud';
    }
    // Hosted servers/RAM/CPU/storage and anything else unrecognized --
    // the generic "we host your infrastructure" bucket.
    return 'private-cloud';
}
