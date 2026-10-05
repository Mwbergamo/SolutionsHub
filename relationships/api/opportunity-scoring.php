<?php
/**
 * relationships/api/opportunity-scoring.php
 *
 * Account Opportunity/Risk scoring -- added 2026-10-05 per Michael's
 * "routine recommendation agent" request, extracted into its own shared
 * file 2026-10-07 so both dashboard.php's bulk front-page Overview list
 * AND customers.php's single-customer detail view (the "direct report"
 * shown on a customer's own profile, per Michael's follow-up request) use
 * the exact same formula rather than two copies that could drift apart.
 *
 * Ranks every account on one spectrum from "Likely to Need Services" (an
 * upsell/outreach opportunity) to "Account in Danger" (declining, worth a
 * service-experience check-in), using three trend signals (Billing,
 * Ticket volume, Active contacts) plus the Customer-Experience ticket
 * signal (see connectwise-opportunity-sync-core.php).
 *
 * THIS IS A FIRST CUT, not a finished/confirmed spec -- same posture as
 * dashboard.php's original gauge set ("there will be a dozen gauges very
 * soon," never separately confirmed as a finished list). The weights below
 * are a reasonable starting point, not something Michael has tuned: billing
 * trend counts most (0.4), ticket-volume and contact-count trends split the
 * rest evenly (0.3 each, matching Michael's own framing -- all three are
 * "leading indicators of whether a customer is growing or shrinking"), and
 * each flagged multi-dispatch ticket in the trailing 90 days docks a flat
 * 15 points, capped at -60 so a handful of bad tickets alone can't bottom
 * out an otherwise-healthy account's score. Worth revisiting once Michael
 * has seen real scores against real accounts and has an opinion on whether
 * any of this should be weighted differently.
 *
 * A trend with percent: null (not enough synced history yet) contributes
 * 0, i.e. neutral, neither helping nor hurting the score -- NOT the same
 * as a confirmed 0% change. A brand-new or recently-resynced account can
 * read as "Stable" by default simply for lack of data yet, not because
 * it's actually steady; the real trend badges shown alongside this score
 * still say "not enough history yet" honestly, so nobody is misled by the
 * score alone.
 */

declare(strict_types=1);

const RELATIONSHIPS_OPPORTUNITY_WEIGHT_BILLING = 0.4;
const RELATIONSHIPS_OPPORTUNITY_WEIGHT_TICKETS = 0.3;
const RELATIONSHIPS_OPPORTUNITY_WEIGHT_CONTACTS = 0.3;
const RELATIONSHIPS_OPPORTUNITY_CX_PENALTY_PER_TICKET = 15.0;
const RELATIONSHIPS_OPPORTUNITY_CX_PENALTY_MAX = 60.0;

/** Folds a {direction, percent} trend back into one signed, clamped percent -- null/'flat' both read as 0 (neutral). */
function relationships_account_opportunity_signed_percent(array $trend): float
{
    if ($trend['percent'] === null || $trend['direction'] === 'flat') {
        return 0.0;
    }
    $percent = (float) $trend['percent'];
    if ($trend['direction'] === 'down') {
        $percent = -$percent;
    }
    return max(-100.0, min(100.0, $percent));
}

/**
 * Returns ['score' => float (-100..100), 'label' => string]. Score sign
 * matches Michael's own framing: positive = opportunity (toward "Likely
 * to Need Services"), negative = risk (toward "Account in Danger").
 * Thresholds (+/-20) are as much a first cut as the weights above --
 * picked to keep the vast majority of ordinary, unremarkable accounts
 * out of either extreme bucket, not derived from any real distribution of
 * scores yet.
 */
function relationships_account_opportunity_score(array $billingTrend, array $ticketTrend, array $contactTrend, int $cxIssueCount): array
{
    $weighted = (relationships_account_opportunity_signed_percent($billingTrend) * RELATIONSHIPS_OPPORTUNITY_WEIGHT_BILLING)
        + (relationships_account_opportunity_signed_percent($ticketTrend) * RELATIONSHIPS_OPPORTUNITY_WEIGHT_TICKETS)
        + (relationships_account_opportunity_signed_percent($contactTrend) * RELATIONSHIPS_OPPORTUNITY_WEIGHT_CONTACTS);

    $cxPenalty = min(RELATIONSHIPS_OPPORTUNITY_CX_PENALTY_MAX, $cxIssueCount * RELATIONSHIPS_OPPORTUNITY_CX_PENALTY_PER_TICKET);
    $score = max(-100.0, min(100.0, $weighted - $cxPenalty));

    if ($score >= 20.0) {
        $label = 'Likely to need services';
    } elseif ($score <= -20.0) {
        $label = 'Account in danger';
    } else {
        $label = 'Stable';
    }

    return ['score' => round($score, 1), 'label' => $label];
}
