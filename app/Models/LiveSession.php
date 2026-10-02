<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LiveSession extends Model
{
    use BelongsToTenant, HasFactory, HasUuid;

    protected $fillable = [
        'tenant_id', 'user_id', 'lesson_content_id', 'course_id', 'title', 'description',
        'provider', 'room_name', 'room_sid',
        'scheduled_at', 'started_at', 'ended_at', 'status',
        'max_participants', 'record_enabled', 'record_url',
        'config', 'metadata',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'max_participants' => 'integer',
        'record_enabled' => 'boolean',
        'config' => 'array',
        'metadata' => 'array',
    ];

    public function lessonContent(): BelongsTo
    {
        return $this->belongsTo(LessonContent::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
