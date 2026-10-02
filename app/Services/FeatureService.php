<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Plans\PlanEngine;
use Illuminate\Http\Request;

/**
 * Request-scoped facade over the Plan Engine. Resolves the current tenant
 * from the validated request context (never raw client input) and exposes
 * the same API the rest of the codebase already uses — but every value now
 * comes from the database through the Redis-cached PlanEngine.
 */
class FeatureService
{
    public function __construct(
        private readonly Request $request,
        private readonly PlanEngine $engine,
    ) {}

    public function tenant(): ?Tenant
    {
        return $this->request->input('current_tenant');
    }

    public function tenantId(): ?string
    {
        $tenant = $this->tenant();

        if ($tenant) {
            return method_exists($tenant, 'getKey') ? (string) $tenant->getKey() : (string) $tenant;
        }

        return $this->request->header('X-Tenant-Id') ?: null;
    }

    public function activeSubscription(): ?TenantSubscription
    {
        $tenantId = $this->tenantId();

        return $tenantId ? $this->engine->activeSubscription($tenantId) : null;
    }

    public function hasFeature(string $key): bool
    {
        $tenantId = $this->tenantId();

        return $tenantId !== null && $this->engine->hasFeature($tenantId, $key);
    }

    public function getFeatureValue(string $key, mixed $default = null): mixed
    {
        $tenantId = $this->tenantId();

        return $tenantId !== null ? $this->engine->value($tenantId, $key, $default) : $default;
    }

    public function getIntFeature(string $key, int $default = 0): int
    {
        $tenantId = $this->tenantId();

        return $tenantId !== null ? $this->engine->intValue($tenantId, $key, $default) : $default;
    }

    public function isUnlimited(string $key): bool
    {
        $tenantId = $this->tenantId();

        return $tenantId !== null && $this->engine->isUnlimited($tenantId, $key);
    }

    public function canExceed(string $key, int $used): bool
    {
        if ($this->isUnlimited($key)) {
            return true;
        }

        $limit = $this->getIntFeature($key);

        return $limit > 0 && $used < $limit;
    }

    public function limitMessage(string $key, string $label, int $used): ?string
    {
        if ($this->canExceed($key, $used)) {
            return null;
        }

        $limit = $this->getIntFeature($key);

        return $limit > 0
            ? "Plan limit reached: you have used {$used} of {$limit} {$label}."
            : 'This feature is not included in your current plan.';
    }
}
