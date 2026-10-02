<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SubscriptionPlan extends Model
{
    use HasFactory, HasUuid, SoftDeletes;

    protected $fillable = [
        'name', 'slug', 'description', 'monthly_price', 'yearly_price',
        'currency', 'trial_days', 'is_active', 'is_public', 'is_popular', 'sort_order', 'metadata',
    ];

    protected $casts = [
        'monthly_price' => 'decimal:2',
        'yearly_price' => 'decimal:2',
        'is_active' => 'boolean',
        'is_public' => 'boolean',
        'is_popular' => 'boolean',
        'trial_days' => 'integer',
        'metadata' => 'array',
    ];

    /**
     * Alias for the public API (admin panel edits `yearly_price`).
     */
    public function getAnnualPriceAttribute(): ?float
    {
        return $this->yearly_price !== null ? (float) $this->yearly_price : null;
    }

    public function features(): HasMany
    {
        return $this->hasMany(PlanFeature::class, 'plan_id');
    }

    /**
     * قيمة ميزة الخطة (من قاعدة البيانات — المصدر الوحيد للحقيقة).
     */
    public function featureValue(string $key, mixed $default = null): mixed
    {
        static $cache = [];

        if (! isset($cache[$this->id])) {
            $cache[$this->id] = $this->features()->pluck('feature_value', 'feature_key')->all();
        }

        return $cache[$this->id][$key] ?? $default;
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(TenantSubscription::class, 'plan_id');
    }
}
