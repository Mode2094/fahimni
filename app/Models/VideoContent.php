<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VideoContent extends Model
{
    use BelongsToTenant, HasFactory, HasUuid;

    protected $fillable = [
        'lesson_content_id', 'lesson_id', 'tenant_id', 'provider', 'provider_video_id',
        'title', 'description', 'thumbnail_url', 'duration',
        'status', 'file_size_bytes', 'config',
        'storage_path', 'original_name', 'mime_type', 'is_uploaded',
    ];

    protected $casts = [
        'duration' => 'integer',
        'file_size_bytes' => 'integer',
        'config' => 'array',
    ];

    public function lessonContent(): BelongsTo
    {
        return $this->belongsTo(LessonContent::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
