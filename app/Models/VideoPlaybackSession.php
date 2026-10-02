<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class VideoPlaybackSession extends Model
{
    use HasUuids;

    protected $fillable = [
        'tenant_id', 'user_id', 'course_id', 'lesson_id', 'video_id',
        'device_id', 'device_hash', 'watermark_enabled', 'watermark_text',
        'started_at', 'last_seen_at', 'expires_at',
    ];

    protected $casts = [
        'watermark_enabled' => 'boolean',
        'started_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function video(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(VideoContent::class);
    }
}
