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

/**
 * The fixed 7-person assignee roster for meeting to-dos (Customer Meeting
 * Capture, added 2026-09-15 per Michael) -- verbatim names/order as given.
 * Not a crc_users query: this list is the source of truth for which 7
 * names the assignee dropdown always offers, regardless of who has
 * actually registered a Relationships login yet (registration is
 * self-service -- auth.php's 'register' action -- so a teammate who hasn't
 * signed in yet must still be assignable). meetings.php matches a picked
 * name against crc_users by name (case-insensitive) to fill in
 * assigned_to_user_id when a real account already exists, but always
 * stores the plain name regardless of whether that match succeeds.
 */
function relationships_todo_roster(): array
{
    return [
        'Claire Hayden',
        'Jake Bradshaw',
        'Casey Mayes',
        'Michael Bergamo',
        'Chester Sienko',
        'Moe Okeilli',
        'Walter Drew',
    ];
}

/**
 * Real ConnectWise office email for each of the 7 fixed roster names above
 * -- added 2026-09-16 (bug fix: a meeting to-do task assigned to a roster
 * member with no Relationships login yet couldn't reach ConnectWise at
 * all. ConnectWise's `assignTo/id` field on an Activity is REQUIRED, not
 * optional (confirmed 2026-09-11, see connectwise-activity-create.php's
 * header) -- so a task whose assignee had never registered a Relationships
 * login had no email to resolve a ConnectWise Member id from, and the
 * WHOLE Activity create was rejected, not just that one field. Live error
 * Michael hit: "The assignTo/id field is required.").
 *
 * Michael supplied these directly (chat, 2026-09-16) specifically so a
 * meeting to-do task's ConnectWise Activity assignment never depends on
 * whether that person has registered a Relationships login. This is now
 * the source of truth meetings.php uses for the `assigned_to_email` it
 * hands to relationships_cw_create_task_activity() -- separate from (and
 * more reliable than) `relationships_meetings_user_by_name()`'s crc_users
 * lookup, which stays in use only for the LOCAL `assigned_to_user_id`
 * bookkeeping column, unrelated to the ConnectWise push.
 *
 * Returns null for any name not in the roster (shouldn't happen --
 * meetings.php validates assigned_to_name against relationships_todo_roster()
 * before this is ever called -- but null here just means the existing
 * "create the Activity memberless" degradation applies, same as any other
 * unresolved assignee).
 */
function relationships_todo_roster_cw_email(string $name): ?string
{
    static $map = [
        'Claire Hayden' => 'Chayden@codebluetechnology.com',
        'Jake Bradshaw' => 'Jbradshaw@codebluetechnology.com',
        'Casey Mayes' => 'cmayes@codebluetechnology.com',
        'Michael Bergamo' => 'Mbergamo@codebluetechnology.com',
        'Chester Sienko' => 'Csienko@codebluetechnology.com',
        'Moe Okeilli' => 'Mokeilli@codebluetechnology.com',
        'Walter Drew' => 'Wdrew@codebluetechnology.com',
    ];
    return $map[$name] ?? null;
}

function relationships_is_cross_sell_eligible(string $pillarId, string $serviceId): bool
{
    $map = relationships_cross_sell_map();
    return isset($map[$pillarId]) && in_array($serviceId, $map[$pillarId], true);
}
