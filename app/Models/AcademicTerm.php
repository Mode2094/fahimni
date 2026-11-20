<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A time-boxed academic term (فصل دراسي) owned by a teacher tenant.
 *
 * Status is explicit and teacher-driven — terms are never auto-archived, so
 * no student ever loses access without the teacher pressing «إنهاء الفصل».
 */
class AcademicTerm extends Model
{
    use BelongsToTenant, HasFactory, HasUuid, SoftDeletes;

    public const STATUS_UPCOMING = 'upcoming';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_ARCHIVED = 'archived';

    public const STATUSES = [
        self::STATUS_UPCOMING,
        self::STATUS_ACTIVE,
        self::STATUS_COMPLETED,
        self::STATUS_ARCHIVED,
    ];

    protected $fillable = [
        'tenant_id', 'name', 'starts_at', 'ends_at', 'status',
        'created_by', 'archived_at', 'metadata',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'archived_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeArchived(Builder $query): void
    {
        $query->where('status', self::STATUS_ARCHIVED);
    }

    public function isArchived(): bool
    {
        return $this->status === self::STATUS_ARCHIVED;
    }

    /**
     * Human-facing state derived from dates + explicit status, so a term
     * whose window has passed reads as «منتهي» even before it is archived.
     */
    public function derivedStatus(): string
    {
        if ($this->status === self::STATUS_ARCHIVED) {
            return self::STATUS_ARCHIVED;
        }

        if ($this->ends_at && $this->ends_at->isPast()) {
            return self::STATUS_COMPLETED;
        }

        if ($this->starts_at && $this->starts_at->isFuture()) {
            return self::STATUS_UPCOMING;
        }

        return self::STATUS_ACTIVE;
    }
}
