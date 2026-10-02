<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FileContent extends Model
{
    use BelongsToTenant, HasFactory, HasUuid;

    protected $fillable = [
        'lesson_content_id', 'tenant_id', 'original_name', 'storage_path',
        'mime_type', 'size_bytes', 'extension', 'is_downloadable',
        'thumbnail_url', 'metadata',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
        'is_downloadable' => 'boolean',
        'metadata' => 'array',
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
