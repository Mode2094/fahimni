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

    /**
     * The Freemius plan has MORE THAN ONE pricing and no explicit Pricing ID is
     * linked locally. We never guess which price the buyer must pay — purchase
     * is blocked until a Super Admin links the exact Pricing ID.
     */
    public const STATUS_AMBIGUOUS = 'ambiguous';

    public const STATUS_UNAVAILABLE = 'unavailable';

    public const STATUS_NOT_CONFIGURED = 'not_configured';

    public const STATUS_INVALID = 'invalid';

    /**
     * A price change was requested from the admin panel but could NOT be
     * applied + verified on Freemius yet. The requested amount is stored as
     * PENDING (never displayed to buyers); the site keeps showing the
     * Freemius-confirmed price and checkout stays blocked while they differ.
     */
    public const STATUS_PENDING = 'pending';

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
     * Live status of one billing cycle.
     *
     * A cycle is `synced` ONLY when ALL of the following hold against Freemius:
     *   1. the pricing is resolved to ONE authoritative Pricing ID (explicitly
     *      linked, or the plan's single pricing — never a random pick),
     *   2. the remote pricing's currency is USD, and
     *   3. the remote amount for THIS cycle equals the displayed amount.
     *
     * @return array<string, mixed>
     */
    public function cycleStatus(SubscriptionPlan $plan, string $cycle): array
    {
        $local = $this->localPrice($plan, $cycle);
        $remote = $this->remotePrice($plan, $cycle);

        // Not resolvable to a single, authoritative pricing → never "synced".
        if ($remote['status'] !== 'ok' || $remote['amount'] === null || $remote['pricing_id'] === null) {
            $status = match ($remote['status']) {
                self::STATUS_NOT_LINKED, self::STATUS_AMBIGUOUS => $remote['status'],
                default => self::STATUS_UNAVAILABLE,
            };

            return $this->withPending($plan, $cycle, $this->cycleResult($cycle, $status, $local, null, $remote['currency'], $remote['plan_id'], $remote['pricing_id'], $remote['error'] ?? $remote['status']));
        }

        // Currency must be USD — a matching amount in another currency is a
        // mismatch, not a sync.
        if (! $this->currencyMatches($remote['currency'])) {
            return $this->withPending($plan, $cycle, $this->cycleResult($cycle, self::STATUS_MISMATCH, $local, $remote['amount'], $remote['currency'], $remote['plan_id'], $remote['pricing_id'], 'currency_mismatch'));
        }

        $status = $this->amountsMatch($local, $remote['amount']) ? self::STATUS_SYNCED : self::STATUS_MISMATCH;

        return $this->withPending($plan, $cycle, $this->cycleResult($cycle, $status, $local, $remote['amount'], $remote['currency'], $remote['plan_id'], $remote['pricing_id']));
    }

    /**
     * Re-apply every PENDING requested price for a plan (Super Admin action).
     *
     * This is the "apply when it can be applied officially" path: it re-runs the
     * exact same verified publish (send → re-read → adopt only on match) for the
     * amounts previously requested, and clears each pending entry that gets
     * confirmed. Nothing is adopted without a confirmed match.
     *
     * @return array{ok: bool, locked: bool, results: array<string, array<string, mixed>>, plan: SubscriptionPlan}
     */
    public function applyPending(SubscriptionPlan $plan, Request $request): array
    {
        $prices = [];

        foreach (self::CYCLES as $cycle) {
            $pending = $this->pendingFor($plan, $cycle);

            if ($pending !== null && isset($pending['amount']) && (float) $pending['amount'] > 0) {
                $prices[$cycle] = (float) $pending['amount'];
            }
        }

        if ($prices === []) {
            return ['ok' => true, 'locked' => false, 'results' => [], 'plan' => $plan->fresh()];
        }

        return $this->publish($plan, $prices, $request);
    }

    /**
     * The PENDING (requested, not yet confirmed) price for a cycle, if any.
     *
     * @return array<string, mixed>|null
     */
    public function pendingFor(SubscriptionPlan $plan, string $cycle): ?array
    {
        $meta = $plan->metadata ?? [];
        $entry = $meta['freemius_prices'][$cycle] ?? null;
        $pending = is_array($entry) ? ($entry['pending'] ?? null) : null;

        return is_array($pending) ? $pending : null;
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
     * Guard a checkout: the displayed price MUST equal the Freemius charge in
     * USD, for the EXACT Pricing ID the buyer will be charged on, and it must be
     * provable. Fails closed (never a random pricing, never a foreign currency).
     *
     * On success the returned `pricing_id` + `billing_cycle` (Freemius values:
     * monthly|annual) are the ones the checkout URL MUST carry.
     *
     * @return array{ok: bool, status: string, message: string, plan_id: ?string, pricing_id: ?string, billing_cycle: string}
     */
    public function guardCheckout(SubscriptionPlan $plan, string $cycle): array
    {
        $status = $this->cycleStatus($plan, $cycle);

        $base = [
            'plan_id' => $status['plan_id'] ?? null,
            'pricing_id' => $status['pricing_id'] ?? null,
            'billing_cycle' => self::freemiusCycle($cycle),
        ];

        if (($status['status'] ?? null) === self::STATUS_SYNCED) {
            return $base + ['ok' => true, 'status' => self::STATUS_SYNCED, 'message' => 'Price verified against the payment gateway.'];
        }

        $reason = (string) ($status['status'] ?? self::STATUS_UNAVAILABLE);

        return $base + [
            'ok' => false,
            'status' => $reason,
            'message' => match ($reason) {
                self::STATUS_MISMATCH => 'The displayed price or currency does not match the payment gateway — purchase is blocked until an administrator republishes the price.',
                self::STATUS_AMBIGUOUS => 'This plan has more than one price in the payment gateway. An administrator must link the exact Pricing ID before it can be purchased.',
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
            return $this->failPending($plan, $cycle, $newPrice, self::STATUS_NOT_LINKED, 'This plan is not linked to a Freemius plan for this billing cycle yet.', $request, $oldPrice, null, null, null);
        }

        if (! FreemiusGateway::developerConfigured()) {
            AuditLogger::log($request, 'subscription.plan_price_publish_blocked', 'plan', $plan->id, [
                'cycle' => $cycle,
                'reason' => self::STATUS_NOT_CONFIGURED,
                'requested_price' => $newPrice,
            ]);

            return $this->failPending(
                $plan,
                $cycle,
                $newPrice,
                self::STATUS_NOT_CONFIGURED,
                'Developer-scope Freemius credentials are not configured on the server, so the price cannot be published automatically. Add FREEMIUS_DEVELOPER_ID, FREEMIUS_DEVELOPER_PUBLIC_KEY and FREEMIUS_DEVELOPER_SECRET_KEY (Dashboard → My Profile → Keys), then retry.',
                $request,
                $oldPrice,
                null,
                $planId,
                null,
                'add_developer_credentials',
            );
        }

        $resolved = $this->resolvePricing($plan, $cycle);

        if ($resolved['status'] !== 'ok' || $resolved['pricing_id'] === null) {
            if ($resolved['status'] === self::STATUS_AMBIGUOUS) {
                return $this->failPending($plan, $cycle, $newPrice, self::STATUS_AMBIGUOUS, 'This Freemius plan has more than one pricing. Link the exact Freemius Pricing ID for this cycle in the admin panel before publishing — the price was NOT changed.', $request, $oldPrice, null, $planId, null);
            }

            if ($resolved['status'] === self::STATUS_NOT_LINKED) {
                return $this->failPending($plan, $cycle, $newPrice, self::STATUS_NOT_LINKED, 'No Freemius pricing id could be resolved for this billing cycle. Set the Freemius Pricing ID for the plan (or ensure the plan has a single pricing in Freemius).', $request, $oldPrice, null, $planId, null);
            }

            return $this->failPending($plan, $cycle, $newPrice, self::STATUS_UNAVAILABLE, 'The linked Freemius pricing could not be read, so the price was NOT changed. No other pricing was substituted.', $request, $oldPrice, null, $planId, $resolved['pricing_id']);
        }

        $planId = $resolved['plan_id'] ?? $planId;
        $pricingId = $resolved['pricing_id'];

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

            return $this->failPending($plan, $cycle, $newPrice, self::STATUS_UNAVAILABLE, 'Freemius rejected the price update (HTTP '.$write['status'].'). The site price was NOT changed.', $request, $oldPrice, null, $planId, $pricingId, null, $write['status']);
        }

        // Re-read the ACTUAL price — never trust the write response alone.
        $remote = $this->remotePrice($plan, $cycle);

        if ($remote['amount'] === null || $remote['status'] !== 'ok') {
            AuditLogger::log($request, 'subscription.plan_price_publish_unverified', 'plan', $plan->id, [
                'cycle' => $cycle,
                'requested_price' => $newPrice,
                'error' => $remote['error'] ?? $remote['status'],
            ]);

            return $this->failPending($plan, $cycle, $newPrice, self::STATUS_UNAVAILABLE, 'The price was sent but could not be re-read from Freemius, so it was NOT adopted. Please retry.', $request, $oldPrice, null, $planId, $pricingId);
        }

        // The re-read must be the SAME pricing we wrote to (never another one).
        if ($remote['pricing_id'] !== $pricingId) {
            return $this->failPending($plan, $cycle, $newPrice, self::STATUS_MISMATCH, 'Freemius returned a different pricing than the one written. The site price was NOT changed.', $request, $oldPrice, $remote['amount'], $planId, $pricingId);
        }

        if (! $this->amountsMatch($newPrice, $remote['amount'])) {
            AuditLogger::log($request, 'subscription.plan_price_publish_mismatch', 'plan', $plan->id, [
                'cycle' => $cycle,
                'requested_price' => $newPrice,
                'remote_price' => $remote['amount'],
            ]);

            return $this->failPending($plan, $cycle, $newPrice, self::STATUS_MISMATCH, 'Freemius reports a different price ('.$remote['amount'].' '.$remote['currency'].') than the requested one. The site price was NOT changed — review the plan pricing in Freemius.', $request, $oldPrice, $remote['amount'], $planId, $pricingId);
        }

        if (! $this->currencyMatches($remote['currency'])) {
            AuditLogger::log($request, 'subscription.plan_price_publish_mismatch', 'plan', $plan->id, [
                'cycle' => $cycle,
                'requested_price' => $newPrice,
                'remote_currency' => $remote['currency'],
            ]);

            return $this->failPending($plan, $cycle, $newPrice, self::STATUS_MISMATCH, 'The Freemius price currency ('.$remote['currency'].') is not '.$currency.'. The site price was NOT changed.', $request, $oldPrice, $remote['amount'], $planId, $pricingId);
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
     * Resolve EXACTLY ONE authoritative Freemius pricing for a local plan +
     * cycle. This never guesses:
     *
     *  - An explicitly linked Pricing ID (metadata/env) is used and MUST exist in
     *    Freemius; if it cannot be read we DO NOT substitute another pricing.
     *  - With no explicit link, the plan must have EXACTLY ONE pricing.
     *  - Multiple pricings without a link → `ambiguous` (purchase is blocked);
     *    we never pick the "first"/"cheapest" one.
     *
     * @return array{status: string, pricing: ?array<string, mixed>, plan_id: ?string, pricing_id: ?string}
     *         status ∈ ok|not_linked|ambiguous|unavailable
     */
    private function resolvePricing(SubscriptionPlan $plan, string $cycle): array
    {
        $planId = FreemiusPlanMapper::planId($plan, $cycle);

        if (! $planId) {
            return ['status' => self::STATUS_NOT_LINKED, 'pricing' => null, 'plan_id' => null, 'pricing_id' => null];
        }

        $explicit = FreemiusPlanMapper::pricingId($plan, $cycle);

        if ($explicit) {
            $pricing = FreemiusGateway::getPricing($planId, $explicit);

            // The specific pricing could not be read → unavailable. Never fall
            // back to a different pricing of the same plan.
            if ($pricing === null) {
                return ['status' => self::STATUS_UNAVAILABLE, 'pricing' => null, 'plan_id' => $planId, 'pricing_id' => $explicit];
            }

            // Defensive: the API must return the pricing we asked for.
            $returnedId = isset($pricing['id']) ? (string) $pricing['id'] : $explicit;

            if ($returnedId !== $explicit) {
                return ['status' => self::STATUS_UNAVAILABLE, 'pricing' => null, 'plan_id' => $planId, 'pricing_id' => $explicit];
            }

            return ['status' => 'ok', 'pricing' => $pricing, 'plan_id' => $planId, 'pricing_id' => $explicit];
        }

        $list = FreemiusGateway::getPlanPricing($planId);

        if ($list === null) {
            return ['status' => self::STATUS_UNAVAILABLE, 'pricing' => null, 'plan_id' => $planId, 'pricing_id' => null];
        }

        if (count($list) > 1) {
            // Ambiguous: several prices exist and none is linked. Block.
            return ['status' => self::STATUS_AMBIGUOUS, 'pricing' => null, 'plan_id' => $planId, 'pricing_id' => null];
        }

        if ($list === []) {
            return ['status' => self::STATUS_NOT_LINKED, 'pricing' => null, 'plan_id' => $planId, 'pricing_id' => null];
        }

        $pricing = $list[0];
        $id = isset($pricing['id']) && $pricing['id'] !== '' ? (string) $pricing['id'] : null;

        if ($id === null) {
            return ['status' => self::STATUS_UNAVAILABLE, 'pricing' => null, 'plan_id' => $planId, 'pricing_id' => null];
        }

        return ['status' => 'ok', 'pricing' => $pricing, 'plan_id' => $planId, 'pricing_id' => $id];
    }

    /**
     * Read the authoritative remote price for a cycle via the strict single
     * pricing resolution (no random pick, no substitution).
     *
     * @return array{amount: ?float, currency: string, pricing_id: ?string, plan_id: ?string, status: string, error: ?string}
     */
    private function remotePrice(SubscriptionPlan $plan, string $cycle): array
    {
        $resolved = $this->resolvePricing($plan, $cycle);

        if ($resolved['status'] !== 'ok' || ! is_array($resolved['pricing'])) {
            return [
                'amount' => null,
                'currency' => self::CURRENCY,
                'pricing_id' => $resolved['pricing_id'],
                'plan_id' => $resolved['plan_id'],
                'status' => $resolved['status'],
                'error' => $resolved['status'],
            ];
        }

        $pricing = $resolved['pricing'];
        $amount = $this->extractAmount($pricing, $cycle);
        $currency = strtoupper((string) ($pricing['currency'] ?? self::CURRENCY));

        return [
            'amount' => $amount,
            'currency' => $currency,
            'pricing_id' => $resolved['pricing_id'],
            'plan_id' => $resolved['plan_id'],
            'status' => $amount === null ? self::STATUS_UNAVAILABLE : 'ok',
            'error' => $amount === null ? 'no_amount_for_cycle' : null,
        ];
    }

    /**
     * Map a local billing cycle to the official Freemius value.
     * Freemius accepts `monthly` | `annual` | `lifetime` (never `yearly`).
     */
    public static function freemiusCycle(string $cycle): string
    {
        return $cycle === 'yearly' ? 'annual' : 'monthly';
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
     * Record + return a NOT-APPLIED publish outcome.
     *
     * The requested price is stored as PENDING (admin-visible) so a Super Admin
     * can see and re-apply the intended change; the DISPLAYED price is left
     * untouched and checkout stays blocked while the two differ. Never a fake
     * success, never a silently-dropped request.
     *
     * @return array<string, mixed>
     */
    private function failPending(
        SubscriptionPlan $plan,
        string $cycle,
        float $newPrice,
        string $status,
        string $message,
        Request $request,
        ?float $oldPrice,
        ?float $remotePrice,
        ?string $planId,
        ?string $pricingId,
        ?string $actionRequired = null,
        ?int $httpStatus = null,
    ): array {
        $this->recordPending($plan, $cycle, $newPrice, $status, $message, $request);

        return $this->publishResult($cycle, $status, false, $oldPrice, $newPrice, $remotePrice, self::CURRENCY, $planId, $pricingId, $message, $actionRequired, $httpStatus);
    }

    /**
     * Persist a requested-but-unconfirmed price under
     * metadata.freemius_prices.{cycle}.pending (no migration needed).
     */
    private function recordPending(SubscriptionPlan $plan, string $cycle, float $newPrice, string $status, string $message, Request $request): void
    {
        $actorId = $request->user()?->id;

        DB::transaction(function () use ($plan, $cycle, $newPrice, $status, $message, $actorId) {
            $fresh = SubscriptionPlan::whereKey($plan->id)->lockForUpdate()->firstOrFail();
            $meta = $fresh->metadata ?? [];
            $prices = is_array($meta['freemius_prices'] ?? null) ? $meta['freemius_prices'] : [];
            $entry = is_array($prices[$cycle] ?? null) ? $prices[$cycle] : [];
            $entry['pending'] = [
                'amount' => round($newPrice, 2),
                'currency' => self::CURRENCY,
                'status' => $status,
                'message' => $message,
                'requested_at' => now()->toIso8601String(),
                'requested_by' => $actorId,
            ];
            $prices[$cycle] = $entry;
            $meta['freemius_prices'] = $prices;

            $fresh->update(['metadata' => $meta]);
        });

        AuditLogger::log($request, 'subscription.plan_price_pending', 'plan', $plan->id, [
            'cycle' => $cycle,
            'requested_price' => round($newPrice, 2),
            'reason' => $status,
        ]);
    }

    /**
     * Attach the pending request (if any) to a cycle status payload so the admin
     * UI can show "requested, not yet applied" without ever displaying it as the
     * live price.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function withPending(SubscriptionPlan $plan, string $cycle, array $result): array
    {
        $result['pending'] = $this->pendingFor($plan, $cycle);

        return $result;
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
