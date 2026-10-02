<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class LessonContent extends Model
{
    use BelongsToTenant, HasFactory, HasUuid, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'lesson_id', 'content_type', 'sort_order',
        'is_required', 'title', 'metadata',
    ];

    protected $casts = [
        'is_required' => 'boolean',
        'metadata' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function content(): MorphTo
    {
        return $this->morphTo('contentable', 'content_type', 'id');
    }

    public function video()
    {
        return $this->hasOne(VideoContent::class);
    }

    public function text()
    {
        return $this->hasOne(TextContent::class);
    }

    public function file()
    {
        return $this->hasOne(FileContent::class);
    }

    public function liveSession()
    {
        return $this->hasOne(LiveSession::class);
    }
}
