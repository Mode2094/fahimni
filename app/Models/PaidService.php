<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PaidService extends Model
{
    use BelongsToTenant, HasFactory, HasUuid, SoftDeletes;

    public const TYPE_CONSULTATION = 'consultation';

    public const TYPE_SESSION = 'session';

    public const TYPES = [self::TYPE_CONSULTATION, self::TYPE_SESSION];

    protected $fillable = [
        'tenant_id', 'user_id', 'type', 'title', 'description',
        'price', 'currency', 'duration_minutes', 'is_active', 'metadata',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'duration_minutes' => 'integer',
        'is_active' => 'boolean',
        'metadata' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class, 'service_id');
    }
}
