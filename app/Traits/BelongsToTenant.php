<?php

namespace App\Traits;

use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToTenant
{
    protected static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder) {
            $tenantId = static::currentTenantId();

            if ($tenantId) {
                $builder->where($builder->qualifyColumn('tenant_id'), $tenantId);
            }
        });

        static::creating(function (Model $model) {
            $tenantId = static::currentTenantId();

            if ($tenantId && empty($model->tenant_id)) {
                $model->tenant_id = $tenantId;
            }
        });
    }

    /**
     * Resolve the validated tenant context. Never reads the raw request
     * header/input, so a client-supplied X-Tenant-Id cannot widen scoping on
     * routes outside the tenant middleware group. Falls back to the current
     * HTTP request when no explicit context was registered (e.g. routes that
     * set the tenant through other means) — but never in CLI/queue where
     * request() has no meaningful tenant.
     */
    protected static function currentTenantId(): ?string
    {
        $context = app()->bound(TenantContext::class)
            ? app(TenantContext::class)
            : null;

        if ($context && $context->current() !== null) {
            return $context->current();
        }

        $request = request();

        if ($request === null) {
            return null;
        }

        $currentTenant = $request->attributes->get('current_tenant');

        if ($currentTenant !== null) {
            return method_exists($currentTenant, 'getKey')
                ? (string) $currentTenant->getKey()
                : (string) $currentTenant;
        }

        return null;
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function scopeByTenant(Builder $query, string $tenantId): void
    {
        $query->where('tenant_id', $tenantId);
    }

    public function scopeWithoutTenant(Builder $query): void
    {
        $query->withoutGlobalScope('tenant');
    }
}
