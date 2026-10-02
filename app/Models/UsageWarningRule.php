<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UsageWarningRule extends Model
{
    use HasFactory, HasUuid;

    protected $fillable = [
        'resource', 'level', 'threshold', 'severity', 'message_ar', 'message_en', 'is_active',
    ];

    protected $casts = [
        'threshold' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    /**
     * قواعد مستوى معين لمورد (مرتبة تنازلياً) — أو القيم الافتراضية.
     */
    public static function forResource(string $resource): array
    {
        return static::where('resource', $resource)
            ->where('is_active', true)
            ->orderByDesc('threshold')
            ->get()
            ->map(fn ($r) => [
                'level' => $r->level,
                'threshold' => (float) $r->threshold,
                'severity' => $r->severity,
                'message_ar' => $r->message_ar,
                'message_en' => $r->message_en,
            ])
            ->all();
    }
}
