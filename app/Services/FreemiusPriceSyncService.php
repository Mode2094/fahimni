<?php

namespace App\Services;

use App\Models\SubscriptionPlan;
use App\Support\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Verified, honest price synchronisation between a local SubscriptionPlan and
 * Freemius — the ONLY billing gateway for platform (teacher) subscriptions.
 *
 * Rules this service guarantees:
 *  - Local plan prices are the DISPLAYED prices. They are only ever changed
 *    AFTER Freemius confirms the new amount (never before).
 *  - Publishing uses the officially documented DEVELOPER-scope pricing write.
 *    The product Bearer token cannot change a price; if developer credentials
 *    are absent we return `not_configured` (never a fake success).
 *  - After a write we ALWAYS re-read the actual price from Freemius and only
 *    adopt it when it matches the requested amount.
 *  - Checkout is blocked when the displayed price cannot be proven to equal the
 *    Freemius charge (fail-closed) so a buyer never pays a different price than
 *    the one shown.
 *  - Existing subscriptions keep their renewal charge: Freemius applies a new
 *    price to NEW purchases only; we never rewrite historical charges.
 *
 * Freemius charges platform subscriptions in USD only (JOD is unsupported);
 * the local plan also keeps features/quotas (PlanEngine) as the source of truth.
 */
class FreemiusPriceSyncService
{
    public const STATUS_SYNCED = 'synced';

    public const STATUS_MISMATCH = 'mismatch';

    public const STATUS_NOT_LINKED = 'not_linked';

    public const STATUS_UNAVAILABLE = 'unavailable';

    public const STATUS_NOT_CONFIGURED = 'not_configured';

    public const STATUS_INVALID = 'invalid';

    /** Platform-subscription currency — Freemius supports USD only. */
    public const CURRENCY = 'USD';

    private const CYCLES = ['monthly', 'yearly'];

    private const AMOUNT_EPSILON = 0.005;

    /**
     * Live sync status for a plan. Read-only; never writes. Safe to call from
     * the admin UI to render the sync state of each billing cycle.
     *
     * @return array<string, mixed>
     */
    public function status(SubscriptionPlan $plan): array
    {
        return [
            'enabled' => FreemiusGateway::enabled(),
            'configured' => FreemiusGateway::configured(),
            'developer_configured' => FreemiusGateway::developerConfigured(),
            'currency' => self::CURRENCY,
            'monthly' => $this->cycleStatus($plan, 'monthly'),
            'yearly' => $this->cycleStatus($plan, 'yearly'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function cycleStatus(SubscriptionPlan $plan, string $cycle): array
    {
        $local = $this->localPrice($plan, $cycle);
        $planId = FreemiusPlanMapper::planId($plan, $cycle);

        if (! $planId) {
            return $this->cycleResult($cycle, self::STATUS_NOT_LINKED, $local, null, null, null, null);
        }

        $remote = $this->remotePrice($plan, $cycle);

        if ($remote['error'] !== null || $remote['amount'] === null) {
            return $this->cycleResult($cycle, self::STATUS_UNAVAILABLE, $local, null, $remote['currency'], $planId, $remote['pricing_id'], $remote['error']);
        }

        $status = $this->amountsMatch($local, $remote['amount']) ? self::STATUS_SYNCED : self::STATUS_MISMATCH;

        return $this->cycleResult($cycle, $status, $local, $remote['amount'], $remote['currency'], $planId, $remote['pricing_id']);
    }

    /**
     * Publish one or more cycle prices. Serialised per-plan to defeat
     * duplicate/concurrent publishes.
     *
     * @param  array<string, float>  $prices  e.g. ['monthly' => 59.0, 'yearly' => 590.0]
     * @return array{ok: bool, locked: bool, results: array<string, array<string, mixed>>, plan: SubscriptionPlan}
     */
    public function publish(SubscriptionPlan $plan, array $prices, Request $request): array
    {
        $lock = Cache::lock('freemius_price_sync:'.$plan->id, 30);

        if (! $lock->get()) {
            return [
                'ok' => false,
                'locked' => true,
                'results' => ['_' => ['ok' => false, 'status' => 'busy', 'message' => 'Another price update is already in progress for this plan. Please retry in a moment.']],
                'plan' => $plan->fresh(),
            ];
        }

        try {
            $results = [];
            $ok = true;

            foreach (self::CYCLES as $cycle) {
                if (! array_key_exists($cycle, $prices) || $prices[$cycle] === null) {
                    continue;
                }

                $results[$cycle] = $this->publishCycle($plan, $cycle, (float) $prices[$cycle], $request);
                $ok = $ok && ($results[$cycle]['ok'] ?? false);
            }

            return ['ok' => $ok, 'locked' => false, 'results' => $results, 'plan' => $plan->fresh()];
        } finally {
            $lock->release();
        }
    }

    /**
     * Guard a checkout: the displayed price MUST equal the Freemius charge, and
     * it must be provable. Fails closed when the price cannot be verified.
     *
     * @return array{ok: bool, status: string, message: string}
     */
    public function guardCheckout(SubscriptionPlan $plan, string $cycle): array
    {
        $status = $this->cycleStatus($plan, $cycle);

        if (($status['status'] ?? null) === self::STATUS_SYNCED) {
            return ['ok' => true, 'status' => self::STATUS_SYNCED, 'message' => 'Price verified against the payment gateway.'];
        }

        $reason = (string) ($status['status'] ?? self::STATUS_UNAVAILABLE);

        return [
            'ok' => false,
            'status' => $reason,
            'message' => match ($reason) {
                self::STATUS_MISMATCH => 'The displayed price does not match the payment gateway price — purchase is blocked until an administrator republishes the price.',
                self::STATUS_NOT_LINKED => 'This plan is not linked to a payment-gateway price yet.',
                default => 'We could not verify the price with the payment gateway right now. Please try again in a moment.',
            },
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function publishCycle(SubscriptionPlan $plan, string $cycle, float $newPrice, Request $request): array
    {
        $oldPrice = $this->localPrice($plan, $cycle);
        $currency = self::CURRENCY;
        $planId = FreemiusPlanMapper::planId($plan, $cycle);

        if ($newPrice <= 0) {
            return $this->publishResult($cycle, self::STATUS_INVALID, false, $oldPrice, $newPrice, null, $currency, $planId, null, 'Price must be greater than zero.');
        }

        if (! $planId) {
            return $this->publishResult($cycle, self::STATUS_NOT_LINKED, false, $oldPrice, $newPrice, null, $currency, null, null, 'This plan is not linked to a Freemius plan for this billing cycle yet.');
        }

        if (! FreemiusGateway::developerConfigured()) {
            AuditLogger::log($request, 'subscription.plan_price_publish_blocked', 'plan', $plan->id, [
                'cycle' => $cycle,
                'reason' => self::STATUS_NOT_CONFIGURED,
                'requested_price' => $newPrice,
            ]);

            return $this->publishResult(
                $cycle,
                self::STATUS_NOT_CONFIGURED,
                false,
                $oldPrice,
                $newPrice,
                null,
                $currency,
                $planId,
                null,
                'Developer-scope Freemius credentials are not configured on the server, so the price cannot be published automatically. Add FREEMIUS_DEVELOPER_ID, FREEMIUS_DEVELOPER_PUBLIC_KEY and FREEMIUS_DEVELOPER_SECRET_KEY (Dashboard → My Profile → Keys), then retry.',
                'add_developer_credentials',
            );
        }

        $pricingId = $this->resolvePricingId($plan, $cycle, $planId);

        if (! $pricingId) {
            return $this->publishResult($cycle, self::STATUS_NOT_LINKED, false, $oldPrice, $newPrice, null, $currency, $planId, null, 'No Freemius pricing id could be resolved for this billing cycle. Set the Freemius Pricing ID for the plan (or ensure the plan has a single pricing in Freemius).');
        }

        $field = $cycle === 'yearly' ? 'annual_price' : 'monthly_price';
        $payload = [$field => $newPrice, 'currency' => strtolower($currency)];

        $write = FreemiusGateway::updatePricing($planId, $pricingId, $payload);

        if (! $write['ok']) {
            AuditLogger::log($request, 'subscription.plan_price_publish_failed', 'plan', $plan->id, [
                'cycle' => $cycle,
                'requested_price' => $newPrice,
                'error' => $write['error'],
                'http_status' => $write['status'],
            ]);

            return $this->publishResult($cycle, self::STATUS_UNAVAILABLE, false, $oldPrice, $newPrice, null, $currency, $planId, $pricingId, 'Freemius rejected the price update (HTTP '.$write['status'].'). The site price was NOT changed.', null, $write['status']);
        }

        // Re-read the ACTUAL price — never trust the write response alone.
        $remote = $this->remotePrice($plan, $cycle);

        if ($remote['amount'] === null || $remote['error'] !== null) {
            AuditLogger::log($request, 'subscription.plan_price_publish_unverified', 'plan', $plan->id, [
                'cycle' => $cycle,
                'requested_price' => $newPrice,
                'error' => $remote['error'],
            ]);

            return $this->publishResult($cycle, self::STATUS_UNAVAILABLE, false, $oldPrice, $newPrice, null, $currency, $planId, $pricingId, 'The price was sent but could not be re-read from Freemius, so it was NOT adopted. Please retry.');
        }

        if (! $this->amountsMatch($newPrice, $remote['amount'])) {
            AuditLogger::log($request, 'subscription.plan_price_publish_mismatch', 'plan', $plan->id, [
                'cycle' => $cycle,
                'requested_price' => $newPrice,
                'remote_price' => $remote['amount'],
            ]);

            return $this->publishResult($cycle, self::STATUS_MISMATCH, false, $oldPrice, $newPrice, $remote['amount'], $remote['currency'], $planId, $pricingId, 'Freemius reports a different price ('.$remote['amount'].' '.$remote['currency'].') than the requested one. The site price was NOT changed — review the plan pricing in Freemius.');
        }

        if (! $this->currencyMatches($remote['currency'])) {
            AuditLogger::log($request, 'subscription.plan_price_publish_mismatch', 'plan', $plan->id, [
                'cycle' => $cycle,
                'requested_price' => $newPrice,
                'remote_currency' => $remote['currency'],
            ]);

            return $this->publishResult($cycle, self::STATUS_MISMATCH, false, $oldPrice, $newPrice, $remote['amount'], $remote['currency'], $planId, $pricingId, 'The Freemius price currency ('.$remote['currency'].') is not '.$currency.'. The site price was NOT changed.');
        }

        // Verified: the Freemius charge equals the requested price → adopt it.
        $this->adoptLocalPrice($plan, $cycle, $newPrice, $remote['amount'], $currency, $planId, $pricingId, $request);

        return $this->publishResult($cycle, self::STATUS_SYNCED, true, $oldPrice, $newPrice, $remote['amount'], $remote['currency'], $planId, $pricingId, 'Price published and confirmed on Freemius.');
    }

    /**
     * Adopt a VERIFIED price locally (displayed price) and record the sync
     * state + an audit trail (old value, new value, date, actor).
     */
    private function adoptLocalPrice(
        SubscriptionPlan $plan,
        string $cycle,
        float $price,
        float $remotePrice,
        string $currency,
        string $planId,
        string $pricingId,
        Request $request,
    ): void {
        $column = $cycle === 'yearly' ? 'yearly_price' : 'monthly_price';
        $actorId = $request->user()?->id;

        $oldPrice = DB::transaction(function () use ($plan, $cycle, $column, $price, $remotePrice, $currency, $planId, $pricingId, $actorId) {
            $fresh = SubscriptionPlan::whereKey($plan->id)->lockForUpdate()->firstOrFail();
            $old = $cycle === 'yearly'
                ? ($fresh->yearly_price !== null ? (float) $fresh->yearly_price : null)
                : ($fresh->monthly_price !== null ? (float) $fresh->monthly_price : null);

            $meta = $fresh->metadata ?? [];
            $prices = is_array($meta['freemius_prices'] ?? null) ? $meta['freemius_prices'] : [];
            $prices[$cycle] = [
                'amount' => round($price, 2),
                'currency' => $currency,
                'plan_id' => $planId,
                'pricing_id' => $pricingId,
                'remote_amount' => round($remotePrice, 2),
                'synced_at' => now()->toIso8601String(),
                'synced_by' => $actorId,
            ];
            $meta['freemius_prices'] = $prices;

            $fresh->update([$column => $price, 'metadata' => $meta]);

            return $old;
        });

        AuditLogger::log($request, 'subscription.plan_price_published', 'plan', $plan->id, [
            'cycle' => $cycle,
            'old_price' => $oldPrice,
            'new_price' => round($price, 2),
            'currency' => $currency,
            'freemius_plan_id' => $planId,
            'freemius_pricing_id' => $pricingId,
            'freemius_remote_price' => round($remotePrice, 2),
            'published_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Resolve the Freemius pricing id for a cycle: explicit mapping first, then
     * (when the plan has exactly one unambiguous pricing) that pricing.
     */
    private function resolvePricingId(SubscriptionPlan $plan, string $cycle, string $planId): ?string
    {
        $explicit = FreemiusPlanMapper::pricingId($plan, $cycle);

        if ($explicit) {
            return $explicit;
        }

        $list = FreemiusGateway::getPlanPricing($planId);

        if ($list === null) {
            return null;
        }

        $picked = $this->pickPricing($list);

        return $picked !== null ? (string) ($picked['id'] ?? '') ?: null : null;
    }

    /**
     * Read the authoritative remote price for a cycle.
     *
     * @return array{amount: ?float, currency: string, pricing_id: ?string, error: ?string}
     */
    private function remotePrice(SubscriptionPlan $plan, string $cycle): array
    {
        $planId = FreemiusPlanMapper::planId($plan, $cycle);

        if (! $planId) {
            return ['amount' => null, 'currency' => self::CURRENCY, 'pricing_id' => null, 'error' => 'not_linked'];
        }

        $pricingId = FreemiusPlanMapper::pricingId($plan, $cycle);
        $pricing = null;

        if ($pricingId) {
            $pricing = FreemiusGateway::getPricing($planId, $pricingId);
        }

        if ($pricing === null) {
            $list = FreemiusGateway::getPlanPricing($planId);

            if ($list === null) {
                return ['amount' => null, 'currency' => self::CURRENCY, 'pricing_id' => $pricingId, 'error' => 'unavailable'];
            }

            $candidate = $this->pickPricing($list);

            if ($candidate === null) {
                return ['amount' => null, 'currency' => self::CURRENCY, 'pricing_id' => $pricingId, 'error' => 'no_pricing'];
            }

            $pricing = $candidate;
        }

        $amount = $this->extractAmount($pricing, $cycle);
        $currency = strtoupper((string) ($pricing['currency'] ?? self::CURRENCY));

        return [
            'amount' => $amount,
            'currency' => $currency,
            'pricing_id' => isset($pricing['id']) ? (string) $pricing['id'] : $pricingId,
            'error' => $amount === null ? 'no_amount' : null,
        ];
    }

    /**
     * Pick the best pricing row: prefer a non-hidden USD pricing, else the first.
     *
     * @param  array<int, array<string, mixed>>  $list
     * @return array<string, mixed>|null
     */
    private function pickPricing(array $list): ?array
    {
        if ($list === []) {
            return null;
        }

        $usd = array_values(array_filter($list, fn ($p) => $this->currencyMatches(strtoupper((string) ($p['currency'] ?? '')))));

        if ($usd !== []) {
            $visible = array_values(array_filter($usd, fn ($p) => empty($p['is_hidden'])));

            return $visible[0] ?? $usd[0];
        }

        return $list[0];
    }

    /**
     * @param  array<string, mixed>  $pricing
     */
    private function extractAmount(array $pricing, string $cycle): ?float
    {
        $key = $cycle === 'yearly' ? 'annual_price' : 'monthly_price';
        $value = $pricing[$key] ?? ($cycle === 'yearly' ? ($pricing['yearly_price'] ?? null) : null);

        return is_numeric($value) ? (float) $value : null;
    }

    private function localPrice(SubscriptionPlan $plan, string $cycle): ?float
    {
        $value = $cycle === 'yearly' ? $plan->yearly_price : $plan->monthly_price;

        return $value !== null ? (float) $value : null;
    }

    private function amountsMatch(?float $a, ?float $b): bool
    {
        if ($a === null || $b === null) {
            return false;
        }

        return abs($a - $b) < self::AMOUNT_EPSILON;
    }

    private function currencyMatches(string $currency): bool
    {
        return strtoupper($currency) === self::CURRENCY;
    }

    /**
     * @return array<string, mixed>
     */
    private function cycleResult(string $cycle, string $status, ?float $local, ?float $remote, ?string $currency, ?string $planId, ?string $pricingId, ?string $error = null): array
    {
        return [
            'cycle' => $cycle,
            'status' => $status,
            'local_price' => $local,
            'remote_price' => $remote,
            'currency' => $currency ?? self::CURRENCY,
            'plan_id' => $planId,
            'pricing_id' => $pricingId,
            'error' => $error,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function publishResult(
        string $cycle,
        string $status,
        bool $ok,
        ?float $oldPrice,
        float $newPrice,
        ?float $remotePrice,
        string $currency,
        ?string $planId,
        ?string $pricingId,
        string $message,
        ?string $actionRequired = null,
        ?int $httpStatus = null,
    ): array {
        return [
            'ok' => $ok,
            'cycle' => $cycle,
            'status' => $status,
            'old_price' => $oldPrice,
            'new_price' => $newPrice,
            'remote_price' => $remotePrice,
            'currency' => $currency,
            'plan_id' => $planId,
            'pricing_id' => $pricingId,
            'message' => $message,
            'action_required' => $actionRequired,
            'http_status' => $httpStatus,
        ];
    }
}
