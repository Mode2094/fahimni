<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A student's question attached to one lesson, and the teacher's answer.
 *
 * Distinct from exam `Question` (which owns options/answer keys); this is a
 * free-text discussion entry with no grading impact.
 */
class LessonQuestion extends Model
{
    use BelongsToTenant, HasFactory, HasUuid;

    protected $fillable = [
        'tenant_id', 'user_id', 'lesson_id', 'course_id',
        'body', 'answer', 'answered_by', 'answered_at', 'is_public',
    ];

    protected $casts = [
        'answered_at' => 'datetime',
        'is_public' => 'boolean',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function answerer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'answered_by');
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function isAnswered(): bool
    {
        return $this->answered_at !== null;
    }
}
