<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class MediaAsset extends Model
{
    use BelongsToTenant, HasFactory, HasUuid, SoftDeletes;

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_READY = 'ready';

    public const STATUS_ERROR = 'error';

    public const STATUS_DELETED = 'deleted';

    public const STATUS_ORPHAN = 'orphan';

    protected $fillable = [
        'tenant_id', 'user_id', 'course_id', 'lesson_content_id', 'provider',
        'provider_asset_id', 'kind', 'status', 'title', 'original_filename',
        'mime_type', 'size_bytes', 'storage_bytes', 'duration_seconds',
        'playback_id', 'thumbnail_url', 'metadata', 'idempotency_key',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
        'storage_bytes' => 'integer',
        'duration_seconds' => 'integer',
        'metadata' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function lessonContent(): BelongsTo
    {
        return $this->belongsTo(LessonContent::class);
    }

    public function scopeReady(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_READY);
    }

    public function scopeOfTenant(Builder $query, string $tenantId): Builder
    {
        return $query->withoutTenant()->where('tenant_id', $tenantId);
    }
}
