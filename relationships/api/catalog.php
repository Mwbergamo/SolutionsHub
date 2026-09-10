<?php
/**
 * relationships/api/catalog.php
 *
 * Single source of truth for the pillar/service catalog used across the
 * Relationships dashboard (mirrors SolutionsHub's real PILLARS ids/names —
 * see app.js PILLARS — kept as a small hand-maintained list rather than
 * importing that file, since it's a large, tightly-coupled quoting-SPA
 * bundle this mini-app has no reason to pull in). db.php, customers.php,
 * and checklist.php all require this instead of keeping their own copy —
 * with three consumers, a hand-copied catalog is a drift risk worth
 * removing. A future real ConnectWise sync should validate any
 * pillar_id/service_id it writes against this same list.
 */

declare(strict_types=1);

function relationships_catalog(): array
{
    return [
        'it' => ['name' => 'IT Services', 'services' => [
            'managed-it' => 'Managed IT Services',
            'vcio' => 'vCIO',
            'cyber-security' => 'Cyber Security',
            'provided-equipment' => 'Provided Equipment',
            'help-desk' => 'Help Desk Support',
            'onsite-support' => 'On-Site Technical Support',
            'equipment-sales' => 'Equipment Sales',
        ]],
        'dc' => ['name' => 'Data Center Services', 'services' => [
            'private-cloud' => 'Private Cloud Hosting',
            'public-cloud' => 'Public Cloud Hosting',
            'internet-sourcing' => 'Internet Connectivity Sourcing',
            'hardware-hosting' => 'Hardware Hosting',
            'disaster-recovery' => 'Failover and Disaster Recovery',
        ]],
        'voip' => ['name' => 'Voice over IP Services', 'services' => [
            'cloud-voice' => 'Cloud Voice System',
            'premise-voice' => 'Premise Voice System',
            'sip-trunking' => 'SIP Trunking',
            'call-center' => 'Call Center',
            'phone-hardware' => 'Phone Hardware Solutions',
            'conference-room' => 'Conference Room Solutions',
        ]],
        'cabling' => ['name' => 'Data Cabling', 'services' => [
            'cabling-business' => 'Data Cabling for Business',
            'cabling-repair' => 'Cabling Repair',
            'data-closet' => 'Data Closet Installation',
            'cabling-docs' => 'Cabling Documentation',
            'cabling-supplies' => 'Cabling Supplies',
        ]],
        'security' => ['name' => 'Premise Security', 'services' => [
            'ip-cameras' => 'IP Security Camera Systems',
            'access-control' => 'Access Control Systems',
        ]],
    ];
}

/**
 * The 7-step cross-sell checklist, in order. Steps 2/4/6 are deliberately
 * the same label ("Phone Call Follow-Up") — they're the same action
 * repeated between the marketing touches, per CodeBlue's cross-sell
 * process; the step number is what distinguishes them.
 */
function relationships_checklist_steps(): array
{
    return [
        1 => 'Initial Marketing Outreach (Phone and Email)',
        2 => 'Phone Call Follow-Up',
        3 => 'Comparison Marketing',
        4 => 'Phone Call Follow-Up',
        5 => 'Meeting Request',
        6 => 'Phone Call Follow-Up',
        7 => 'Close-Out — Re-Address in 180 Days',
    ];
}

/**
 * Only these services get pushed through the Relationships dashboard's
 * blanket cross-sell mechanism — the missing-services roster, the
 * marketing link, the 7-step checklist, and the step-queue report.
 * Per CodeBlue: everything else in the catalog is only worth cross-selling
 * case by case, when a rep spots an actual need, not via an automatic
 * "you're missing this" prompt. A service missing outside this list still
 * shows in the pillar drill-down (so a CRC sees the full picture) — it
 * just doesn't get a marketing link or a checklist.
 */
function relationships_cross_sell_map(): array
{
    return [
        'it' => ['managed-it', 'cyber-security', 'provided-equipment'],
        'voip' => ['cloud-voice'],
        'security' => ['ip-cameras', 'access-control'],
    ];
}

function relationships_is_cross_sell_eligible(string $pillarId, string $serviceId): bool
{
    $map = relationships_cross_sell_map();
    return isset($map[$pillarId]) && in_array($serviceId, $map[$pillarId], true);
}
