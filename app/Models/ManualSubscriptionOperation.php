<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One Super-Admin manual subscription operation.
 *
 * @see \App\Services\ManualSubscriptionService for the write path.
 */
class ManualSubscriptionOperation extends Model
{
    use BelongsToTenant, HasFactory, HasUuid;

    protected $fillable = [
        'tenant_id', 'tenant_subscription_id', 'payment_id',
        'operation_type', 'status',
        'previous_plan_id', 'new_plan_id',
        'billing_cycle', 'duration_days',
        'previous_ends_at', 'starts_at', 'ends_at',
        'amount', 'currency', 'payment_method', 'payment_reference', 'payment_date',
        'proof_path', 'reason',
        'performed_by', 'performed_by_name', 'request_id', 'metadata',
    ];

    protected $casts = [
        'previous_ends_at' => 'datetime',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'payment_date' => 'datetime',
        'amount' => 'decimal:2',
        'metadata' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(TenantSubscription::class, 'tenant_subscription_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function previousPlan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'previous_plan_id');
    }

    public function newPlan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'new_plan_id');
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
