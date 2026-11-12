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
     * Statuses that mean the license was HARD-cancelled / revoked by Freemius
     * (merchant-side cancel, refund, revocation, deletion) — access must stop
     * immediately, even if a future expiration date is still present.
     */
    public const REVOKED_STATUSES = ['revoked', 'deactivated', 'deleted', 'refunded', 'chargeback'];

    /**
     * Whether the license currently grants access, derived ONLY from the
     * documented Freemius license fields:
     *
     *   - A HARD cancellation/revocation/refund stops access immediately,
     *     regardless of `expiration` (status in REVOKED_STATUSES, or
     *     metadata.hard_cancelled === true).
     *   - `expiration` (nullable; null = lifetime) is otherwise AUTHORITATIVE.
     *   - `is_canceled` (renewal stopped / cancel-at-period-end) does NOT stop
     *     access — only the expiry date does.
     *
     * This is the SINGLE source of truth for entitlement access; other layers
     * (SubscriptionState) reuse it rather than duplicating the rules.
     */
    public function isActive(): bool
    {
        return self::grantsAccess(
            status: (string) $this->status,
            expiration: $this->expiration,
            hardCancelled: ($this->metadata['hard_cancelled'] ?? false) === true,
        );
    }

    /**
     * Pure access rule, shared by model instances and query scopes so there is
     * exactly one definition of "does this license grant access".
     */
    public static function grantsAccess(?string $status, ?\DateTimeInterface $expiration, bool $hardCancelled): bool
    {
        // Hard revocation/refund/merchant-cancel stops access immediately.
        if ($hardCancelled) {
            return false;
        }

        if (in_array((string) $status, self::REVOKED_STATUSES, true)) {
            return false;
        }

        // Null expiration = non-expiring (lifetime) license.
        if ($expiration === null) {
            return true;
        }

        // Cancellation does NOT end access — only the expiry date does.
        return $expiration->isFuture();
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
