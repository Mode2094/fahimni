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
     * Whether the license currently grants access.
     *
     * IMPORTANT semantics (per Freemius): `is_canceled` means the recurring
     * billing was stopped (the subscription is canceled at period end). It
     * does NOT revoke the current paid period — access must continue until
     * `expiration`. Only an expired or revoked license stops access.
     *
     * A `revoked`/`deactivated` status (license pulled by Freemius) does stop
     * access regardless of expiration.
     */
    public function isActive(): bool
    {
        // A license explicitly revoked by Freemius stops access immediately.
        if (in_array((string) $this->status, ['revoked', 'deactivated', 'deleted'], true)) {
            return false;
        }

        // Null expiration = non-expiring entitlement (lifetime).
        if ($this->expiration === null) {
            return true;
        }

        // Cancellation does NOT end access — only the expiry date does.
        return $this->expiration->isFuture();
    }

    /**
     * True when the recurring billing has been stopped (cancelled at period
     * end) but the paid period may still be running.
     */
    public function isCancelledAtPeriodEnd(): bool
    {
        return $this->is_canceled && $this->isActive();
    }
}
