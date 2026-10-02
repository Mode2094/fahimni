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

        return $sub ? TenantSubscription::find($sub['id']) : null;
    }

    private function resolve(string $tenantId): array
    {
        $subscription = TenantSubscription::with('plan.features')
            ->where('tenant_id', $tenantId)
            ->whereIn('status', ['active', 'trialing'])
            ->latest('starts_at')
            ->first();

        if (! $subscription || ! $subscription->plan) {
            return ['active' => false, 'subscription' => null, 'features' => []];
        }

        // Trial / paid period must still be valid.
        if ($subscription->status === 'trialing'
            && $subscription->trial_ends_at
            && now()->gt($subscription->trial_ends_at)) {
            return ['active' => false, 'subscription' => null, 'features' => []];
        }

        if ($subscription->status === 'active'
            && $subscription->ends_at
            && now()->gt($subscription->ends_at)) {
            return ['active' => false, 'subscription' => null, 'features' => []];
        }

        $features = $subscription->plan->features
            ->pluck('feature_value', 'feature_key')
            ->map(fn ($v) => (string) $v)
            ->all();

        return [
            'active' => true,
            'subscription' => [
                'id' => $subscription->id,
                'plan_id' => $subscription->plan_id,
                'plan_name' => $subscription->plan->name,
                'plan_slug' => $subscription->plan->slug,
                'monthly_price' => (float) $subscription->plan->monthly_price,
                'annual_price' => $subscription->plan->annual_price,
                'status' => $subscription->status,
                'billing_cycle' => $subscription->billing_cycle,
                'trial_ends_at' => $subscription->trial_ends_at?->toIso8601String(),
                'ends_at' => $subscription->ends_at?->toIso8601String(),
            ],
            'features' => $features,
        ];
    }
}
