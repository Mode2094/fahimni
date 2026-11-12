<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Canonical Freemius license → tenant entitlement.
 *
 * One row per Freemius license. Per Freemius' SaaS integration guidance the
 * *license* (not the subscription) governs account level: access must survive
 * a mid-term cancellation and must stop when the license expires. PlanEngine
 * therefore reads the active entitlement, never the raw subscription status.
 */
class FsEntitlement extends Model
{
    use HasUuid;

    protected $table = 'fs_entitlements';

    protected $fillable = [
        'tenant_id', 'fs_license_id', 'fs_user_id', 'fs_plan_id',
        'fs_pricing_id', 'fs_subscription_id', 'plan_id', 'type',
        'status', 'expiration', 'is_canceled', 'synced_at', 'metadata',
    ];

    protected $casts = [
        'expiration' => 'datetime',
        'is_canceled' => 'boolean',
        'synced_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'plan_id');
    }

    /**
     * An entitlement is active when it is not canceled and not expired.
     * Mirrors Freemius' own `getActiveEntitlement` semantics.
     */
    public function isActive(): bool
    {
        if ($this->is_canceled) {
            return false;
        }

        // Null expiration = non-expiring entitlement (lifetime).
        if ($this->expiration === null) {
            return true;
        }

        return $this->expiration->isFuture();
    }
}
