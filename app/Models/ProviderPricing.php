<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProviderPricing extends Model
{
    protected $table = 'provider_pricing';

    use HasFactory, HasUuid;

    protected $fillable = ['provider', 'resource', 'unit_price', 'currency', 'effective_from', 'metadata'];

    protected $casts = [
        'unit_price' => 'decimal:6',
        'effective_from' => 'date',
        'metadata' => 'array',
    ];

    /**
     * أحدث سعر مفعّل لمورد معين، أو null إن لم يُعرّف.
     */
    public static function latest(string $provider, string $resource, ?string $currency = null): ?self
    {
        return self::query()
            ->where('provider', $provider)
            ->where('resource', $resource)
            ->when($currency, fn ($q) => $q->where('currency', $currency))
            ->orderByDesc('effective_from')
            ->first();
    }

    public static function unitPrice(string $provider, string $resource): float
    {
        return (float) (self::latest($provider, $resource)?->unit_price ?? 0);
    }
}
