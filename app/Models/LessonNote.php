<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A private note a student keeps on a lesson, optionally pinned to a moment
 * in the video (`timestamp_seconds`). Only its author (and, if shared, the
 * tenant's staff) can read it.
 */
class LessonNote extends Model
{
    use BelongsToTenant, HasFactory, HasUuid;

    protected $fillable = [
        'tenant_id', 'user_id', 'lesson_id', 'course_id',
        'timestamp_seconds', 'body', 'is_shared',
    ];

    protected $casts = [
        'timestamp_seconds' => 'integer',
        'is_shared' => 'boolean',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }
}
