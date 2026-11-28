<?php

namespace App\Services;

use App\Models\SubscriptionPlan;

/**
 * Idempotent provisioning of the Freemius plans + the single USD pricing that
 * back the local subscription tiers.
 *
 * Model (Freemius' recommended SaaS shape): ONE plan per tier, with ONE USD
 * pricing that carries BOTH `monthly_price` and `annual_price`. The billing
 * cycle is chosen at checkout via `billing_cycle=monthly|annual`, so a tier
 * needs exactly one plan and one pricing — not one plan per cycle.
 *
 * Guarantees:
 *  - Idempotent: existing plans/pricings are reused, never duplicated.
 *  - Never guesses: a plan with MORE THAN ONE pricing is reported `ambiguous`
 *    and left untouched.
 *  - Honest: the local plan is linked only after the remote pricing reads back
 *    with the SAME USD amount for both cycles.
 *  - Dry-run never mutates Freemius; it only reports what would happen.
 *
 * Reads use the PRODUCT-scope Bearer token; writes use the DEVELOPER scope
 * (see FreemiusGateway::developerWrite()).
 */
class FreemiusPlanProvisioner
{
    /** Local tier slugs, in display order. `elite` may also exist as legacy `enterprise`. */
    public const TIERS = ['starter', 'professional', 'elite'];

    /** Canonical English titles (the local plan stores only the Arabic name). */
    private const TITLES = [
        'starter' => 'Starter',
        'professional' => 'Professional',
        'elite' => 'Elite',
        'enterprise' => 'Elite',
    ];

    private const AMOUNT_EPSILON = 0.005;

    public const CURRENCY = 'USD';

    /**
     * @param  bool  $apply  false = dry-run (report only), true = create/link
     * @param  array<int, string>  $only  restrict to these slugs (defaults to all tiers)
     * @return array{ok: bool, applied: bool, tiers: array<int, array<string, mixed>>}
     */
    public function provision(bool $apply = false, array $only = []): array
    {
        $slugs = $only !== [] ? array_values($only) : self::TIERS;

        $remote = FreemiusGateway::listPlans();
        $rows = [];

        foreach ($slugs as $slug) {
            $rows[] = $this->provisionTier($slug, $remote, $apply);
        }

        $ok = ! in_array(false, array_map(static fn (array $r): bool => (bool) $r['ok'], $rows), true);

        return ['ok' => $ok, 'applied' => $apply, 'tiers' => $rows];
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $remotePlans
     * @return array<string, mixed>
     */
    private function provisionTier(string $slug, ?array $remotePlans, bool $apply): array
    {
        $plan = $this->localPlan($slug);

        if (! $plan) {
            return $this->row($slug, false, 'local_plan_missing', 'No local SubscriptionPlan with this slug.');
        }

        $monthly = $plan->monthly_price !== null ? round((float) $plan->monthly_price, 2) : null;
        $annual = $plan->yearly_price !== null ? round((float) $plan->yearly_price, 2) : null;
        $prices = ['monthly' => $monthly, 'annual' => $annual];

        if ($monthly === null || $monthly <= 0 || $annual === null || $annual <= 0) {
            return $this->row($slug, false, 'invalid_local_price', 'The local monthly and yearly prices must both be greater than zero.');
        }

        if ($remotePlans === null) {
            return $this->row($slug, false, 'unavailable', 'Could not read the product plans from Freemius (product Bearer token / network).');
        }

        // 1) Resolve (or create) the Freemius plan.
        $remotePlan = $this->matchPlan($remotePlans, $slug);
        $planCreated = false;

        if ($remotePlan === null) {
            if (! $apply) {
                return $this->row($slug, true, 'would_create_plan', "Would create the Freemius plan `{$slug}` with one USD pricing ({$monthly} monthly / {$annual} annual).", $prices);
            }

            $created = FreemiusGateway::createPlan($this->planPayload($slug));

            if (! $created['ok']) {
                return $this->row($slug, false, 'create_plan_failed', "Freemius rejected plan creation (HTTP {$created['status']}, {$created['error']}).", $prices, null, null, $created['status']);
            }

            $planId = $this->extractId($created['data']);

            if ($planId === null) {
                return $this->row($slug, false, 'create_plan_failed', 'The plan was created but no id was returned.', $prices);
            }

            $planCreated = true;
        } else {
            $planId = $this->extractId($remotePlan);

            if ($planId === null) {
                return $this->row($slug, false, 'unavailable', 'A matching Freemius plan exists but has no id.', $prices);
            }
        }

        // 2) Resolve exactly one pricing (never guess between several).
        $pricing = $this->pricingForTier($planId, $monthly, $annual, $apply);

        if ($pricing['pricing_id'] === null) {
            return $this->row($slug, $pricing['ok'], $pricing['status'], $pricing['detail'], $prices, $planId, null, $pricing['http_status']);
        }

        $pricingId = $pricing['pricing_id'];

        // 3) Verify the remote pricing BEFORE linking anything locally.
        $verified = $this->verify($planId, $pricingId, $monthly, $annual);

        if (! $verified['ok']) {
            return $this->row($slug, false, $verified['status'], $verified['message'], $prices, $planId, $pricingId);
        }

        if ($apply) {
            $this->link($plan, $planId, $pricingId, $monthly, $annual);
        }

        return $this->row(
            $slug,
            true,
            ! $apply ? 'would_link' : (($planCreated || $pricing['created']) ? 'created' : 'linked'),
            $apply
                ? 'Plan + single USD pricing linked and verified.'
                : "Would link plan {$planId} / pricing {$pricingId} (verified {$monthly}/{$annual} USD).",
            $prices,
            $planId,
            $pricingId,
            null,
            (bool) $pricing['created'],
        );
    }

    /**
     * Resolve EXACTLY ONE authoritative pricing for the plan, creating the single
     * USD pricing when none exists and we are applying. Never substitutes one
     * pricing for another.
     *
     * @return array{ok: bool, pricing_id: ?string, created: bool, status: string, detail: string, http_status: ?int}
     */
    private function pricingForTier(string $planId, float $monthly, float $annual, bool $apply): array
    {
        $list = FreemiusGateway::getPlanPricing($planId);

        if ($list === null) {
            return $this->pricingRow(false, null, false, 'unavailable', 'Could not read the plan pricing from Freemius.');
        }

        if (count($list) > 1) {
            return $this->pricingRow(false, null, false, 'ambiguous', 'The Freemius plan has more than one pricing — link the exact Pricing ID manually (never guessed).');
        }

        if ($list === []) {
            if (! $apply) {
                return $this->pricingRow(true, null, false, 'would_create_pricing', "Would create one USD pricing ({$monthly} monthly / {$annual} annual).");
            }

            $created = FreemiusGateway::createPricing($planId, [
                'monthly_price' => $monthly,
                'annual_price' => $annual,
                'currency' => strtolower(self::CURRENCY),
            ]);

            if (! $created['ok']) {
                return $this->pricingRow(false, null, false, 'create_pricing_failed', "Freemius rejected pricing creation (HTTP {$created['status']}, {$created['error']}).", $created['status']);
            }

            $id = $this->extractId($created['data']);

            if ($id === null) {
                return $this->pricingRow(false, null, false, 'create_pricing_failed', 'The pricing was created but no id was returned.');
            }

            return $this->pricingRow(true, $id, true, 'created', 'Created one USD pricing.', $created['status']);
        }

        $id = $this->extractId($list[0]);

        if ($id === null) {
            return $this->pricingRow(false, null, false, 'unavailable', 'The plan pricing has no id.');
        }

        return $this->pricingRow(true, $id, false, 'exists', 'Reusing the existing plan pricing.');
    }

    /**
     * @return array{ok: bool, status: string, message: string, currency: string}
     */
    private function verify(string $planId, string $pricingId, float $monthly, float $annual): array
    {
        $pricing = FreemiusGateway::getPricing($planId, $pricingId);

        if ($pricing === null) {
            return ['ok' => false, 'status' => 'unavailable', 'message' => 'The created/linked pricing could not be read back from Freemius.', 'currency' => self::CURRENCY];
        }

        $currency = strtoupper((string) ($pricing['currency'] ?? ''));

        if ($currency !== self::CURRENCY) {
            return ['ok' => false, 'status' => 'mismatch', 'message' => "The pricing currency is {$currency}, expected ".self::CURRENCY.'.', 'currency' => $currency];
        }

        $remoteMonthly = $pricing['monthly_price'] ?? null;
        $remoteAnnual = $pricing['annual_price'] ?? null;

        if (! is_numeric($remoteMonthly) || ! is_numeric($remoteAnnual)
            || abs((float) $remoteMonthly - $monthly) >= self::AMOUNT_EPSILON
            || abs((float) $remoteAnnual - $annual) >= self::AMOUNT_EPSILON) {
            return [
                'ok' => false,
                'status' => 'mismatch',
                'message' => sprintf('Freemius reports %s/%s but the local plan expects %s/%s USD.', $remoteMonthly, $remoteAnnual, $monthly, $annual),
                'currency' => $currency,
            ];
        }

        return ['ok' => true, 'status' => 'verified', 'message' => 'Remote pricing matches the local price.', 'currency' => $currency];
    }

    /**
     * Link the verified plan/pricing ids to the local plan metadata. The DISPLAYED
     * price columns are left untouched (they already equal the verified remote
     * amount). Any resolved pending entry is cleared.
     */
    private function link(SubscriptionPlan $plan, string $planId, string $pricingId, float $monthly, float $annual): void
    {
        $meta = $plan->metadata ?? [];

        $meta['freemius_plan_id'] = $planId;
        $meta['freemius_monthly_plan_id'] = $planId;
        $meta['freemius_yearly_plan_id'] = $planId;
        $meta['freemius_pricing_id'] = $pricingId;
        $meta['freemius_monthly_pricing_id'] = $pricingId;
        $meta['freemius_yearly_pricing_id'] = $pricingId;

        $prices = is_array($meta['freemius_prices'] ?? null) ? $meta['freemius_prices'] : [];
        $now = now()->toIso8601String();

        foreach (['monthly' => $monthly, 'yearly' => $annual] as $cycle => $amount) {
            $entry = is_array($prices[$cycle] ?? null) ? $prices[$cycle] : [];
            unset($entry['pending']);
            $entry['amount'] = round($amount, 2);
            $entry['currency'] = self::CURRENCY;
            $entry['plan_id'] = $planId;
            $entry['pricing_id'] = $pricingId;
            $entry['remote_amount'] = round($amount, 2);
            $entry['synced_at'] = $now;
            $entry['synced_by'] = null;
            $prices[$cycle] = $entry;
        }

        $meta['freemius_prices'] = $prices;

        $plan->update(['metadata' => $meta]);
    }

    private function localPlan(string $slug): ?SubscriptionPlan
    {
        $slugs = $slug === 'elite' ? ['elite', 'enterprise'] : [$slug];

        return SubscriptionPlan::whereIn('slug', $slugs)->first();
    }

    /**
     * @param  array<int, array<string, mixed>>  $plans
     * @return array<string, mixed>|null
     */
    private function matchPlan(array $plans, string $slug): ?array
    {
        foreach ($plans as $plan) {
            $name = strtolower((string) ($plan['name'] ?? ''));

            if ($name === strtolower($slug)) {
                return $plan;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function planPayload(string $slug): array
    {
        return [
            'name' => strtolower($slug),
            'title' => self::TITLES[strtolower($slug)] ?? ucfirst($slug),
        ];
    }

    /**
     * @param  mixed  $data
     */
    private function extractId($data): ?string
    {
        if (is_array($data) && isset($data['id']) && $data['id'] !== '') {
            return (string) $data['id'];
        }

        return null;
    }

    /**
     * @return array{ok: bool, pricing_id: ?string, created: bool, status: string, detail: string, http_status: ?int}
     */
    private function pricingRow(bool $ok, ?string $pricingId, bool $created, string $status, string $detail, ?int $httpStatus = null): array
    {
        return ['ok' => $ok, 'pricing_id' => $pricingId, 'created' => $created, 'status' => $status, 'detail' => $detail, 'http_status' => $httpStatus];
    }

    /**
     * @param  array{monthly?: ?float, annual?: ?float}  $prices
     * @return array<string, mixed>
     */
    private function row(
        string $slug,
        bool $ok,
        string $status,
        string $detail,
        array $prices = [],
        ?string $planId = null,
        ?string $pricingId = null,
        ?int $httpStatus = null,
        bool $created = false,
    ): array {
        return [
            'tier' => $slug,
            'ok' => $ok,
            'status' => $status,
            'detail' => $detail,
            'monthly' => $prices['monthly'] ?? null,
            'annual' => $prices['annual'] ?? null,
            'plan_id' => $planId,
            'pricing_id' => $pricingId,
            'created' => $created,
            'http_status' => $httpStatus,
        ];
    }
}
