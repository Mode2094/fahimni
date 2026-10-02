<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PlanProfitability extends Model
{
    use HasFactory, HasUuid;

    protected $table = 'plan_profitability';

    protected $fillable = [
        'plan_id', 'billing_cycle', 'subscription_price', 'estimated_cost',
        'expected_profit', 'margin_percentage', 'subscribers_count',
        'recalculated_at', 'metadata',
    ];

    protected $casts = [
        'subscription_price' => 'decimal:2',
        'estimated_cost' => 'decimal:2',
        'expected_profit' => 'decimal:2',
        'margin_percentage' => 'decimal:2',
        'subscribers_count' => 'integer',
        'recalculated_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function plan()
    {
        return $this->belongsTo(SubscriptionPlan::class);
    }
}
