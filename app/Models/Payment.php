<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payment extends Model
{
    use BelongsToTenant, HasFactory, HasUuid;

    protected $fillable = [
        'tenant_id', 'tenant_subscription_id', 'booking_id', 'transaction_id', 'gateway',
        'amount', 'currency', 'status', 'payment_method', 'gateway_data', 'paid_at',
        'fs_license_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'gateway_data' => 'array',
        'paid_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(TenantSubscription::class, 'tenant_subscription_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'payment_id');
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class, 'booking_id');
    }
}
