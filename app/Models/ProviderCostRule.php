<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * قاعدة تكلفة مزود/مورد — المصدر الوحيد للحقيقة لأسعار البنية التحتية.
 * أي تعديل على الأسعار يتم هنا (أو من لوحة الأدمن) وليس في الكود.
 */
class ProviderCostRule extends Model
{
    use HasFactory, HasUuid;

    protected $fillable = [
        'provider', 'resource', 'unit', 'cost', 'currency', 'is_active', 'metadata',
    ];

    protected $casts = [
        'cost' => 'decimal:6',
        'is_active' => 'boolean',
        'metadata' => 'array',
    ];

    /**
     * سعر الوحدة لموارد معين (المفعل فقط).
     */
    public static function unitCost(string $provider, string $resource, string $currency = 'USD'): float
    {
        return (float) static::query()
            ->where('provider', $provider)
            ->where('resource', $resource)
            ->where('is_active', true)
            ->value('cost') ?? 0.0;
    }

    public static function rule(string $provider, string $resource): ?self
    {
        return static::query()
            ->where('provider', $provider)
            ->where('resource', $resource)
            ->where('is_active', true)
            ->first();
    }
}
