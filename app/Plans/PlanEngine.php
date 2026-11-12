<?php

namespace App\Plans;

use App\Models\TenantSubscription;
use Illuminate\Support\Facades\Cache;

/**
 * Core Plan Engine — the single source of truth for what a tenant is
 * allowed to do. Everything is read from the database, cached in Redis and
 * invalidated the moment plans, features or subscriptions change
 * (version-key pattern: one global counter, zero per-tenant invalidation
 * sweeps).
 *
 * There is NO plan-name logic anywhere: only feature keys / limit keys.
 */
class PlanEngine
{
    private const VERSION_KEY = 'plan:version';

    private const DATA_PREFIX = 'plan:data:';

    public static function bumpVersion(): void
    {
        Cache::forever(self::VERSION_KEY, (int) Cache::get(self::VERSION_KEY, 0) + 1);
    }

    /**
     * Resolve the effective plan snapshot for a tenant.
     *
     * @return array{active: bool, subscription: array|null, features: array<string, string>}
     */
    public function snapshot(string $tenantId): array
    {
        $version = (int) Cache::get(self::VERSION_KEY, 0);
        $cached = Cache::get(self::DATA_PREFIX.$tenantId);

        if (is_array($cached) && ($cached['v'] ?? -1) === $version) {
            return $cached['data'];
        }

        $snapshot = $this->resolve($tenantId);

        // Short TTL bounds time-based staleness (subscription expiry) to a
        // few minutes even if the hourly expiry job is delayed or fails.
        Cache::put(self::DATA_PREFIX.$tenantId, ['v' => $version, 'data' => $snapshot], 300);

        return $snapshot;
    }

    public function features(string $tenantId): array
    {
        return $this->snapshot($tenantId)['features'];
    }

    public function hasFeature(string $tenantId, string $key): bool
    {
        $value = $this->features($tenantId)[$key] ?? null;

        if ($value === null) {
            return false;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
            ?? $value !== '0';
    }

    public function value(string $tenantId, string $key, mixed $default = null): mixed
    {
        return $this->features($tenantId)[$key] ?? $default;
    }

    public function intValue(string $tenantId, string $key, int $default = 0): int
    {
        return (int) $this->value($tenantId, $key, $default);
    }

    public function isUnlimited(string $tenantId, string $key): bool
    {
        return $this->value($tenantId, $key) === 'unlimited';
    }

    /**
     * Numeric limit for a key: null when unlimited or unset.
     */
    public function limit(string $tenantId, string $key): ?int
    {
        if ($this->isUnlimited($tenantId, $key)) {
            return null;
        }

        $value = (int) $this->value($tenantId, $key, 0);

        return $value > 0 ? $value : null;
    }

    public function activeSubscription(string $tenantId): ?TenantSubscription
    {
        $sub = $this->snapshot($tenantId)['subscription'];

        // Entitlement-backed tenants have no local subscription row.
        if (! $sub || ($sub['billing_source'] ?? null) === SubscriptionState::SOURCE_FREEMIUS) {
            return null;
        }

        return TenantSubscription::find($sub['id']);
    }

    private function resolve(string $tenantId): array
    {
        // Single source of truth: Freemius entitlement OR legacy subscription.
        $state = SubscriptionState::make()->resolve($tenantId);

        if ($state['source'] === SubscriptionState::SOURCE_NONE || ! $state['plan_id']) {
            return ['active' => false, 'subscription' => null, 'features' => []];
        }

        $plan = \App\Models\SubscriptionPlan::with('features')->find($state['plan_id']);

        if (! $plan) {
            return ['active' => false, 'subscription' => null, 'features' => []];
        }

        $features = $plan->features
            ->pluck('feature_value', 'feature_key')
            ->map(fn ($v) => (string) $v)
            ->all();

        return [
            'active' => true,
            'subscription' => [
                'id' => $state['subscription_id'],
                'plan_id' => $plan->id,
                'plan_name' => $plan->name,
                'plan_slug' => $plan->slug,
                'monthly_price' => (float) $plan->monthly_price,
                'annual_price' => $plan->annual_price,
                'status' => $state['status'],
                'billing_cycle' => $state['billing_cycle'],
                'trial_ends_at' => $state['trial_ends_at']?->toIso8601String(),
                'ends_at' => $state['ends_at']?->toIso8601String(),
                'billing_source' => $state['source'],
            ],
            'features' => $features,
        ];
    }
}
