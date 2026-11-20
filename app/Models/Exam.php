<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Exam extends Model
{
    use BelongsToTenant, HasFactory, HasUuid, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'course_id', 'section_id', 'title', 'description',
        'duration_minutes', 'pass_mark_percent', 'max_attempts',
        'random_questions_count', 'anti_cheat_enabled',
        'shuffle_questions', 'shuffle_options',
        'show_result_immediately', 'show_correct_answers',
        'is_published', 'starts_at', 'ends_at', 'sort_order', 'metadata',
    ];

    protected $casts = [
        'duration_minutes' => 'integer',
        'pass_mark_percent' => 'decimal:2',
        'max_attempts' => 'integer',
        'random_questions_count' => 'integer',
        'anti_cheat_enabled' => 'boolean',
        'shuffle_questions' => 'boolean',
        'shuffle_options' => 'boolean',
        'show_result_immediately' => 'boolean',
        'show_correct_answers' => 'boolean',
        'is_published' => 'boolean',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function sections(): HasMany
    {
        return $this->hasMany(ExamSection::class)->orderBy('sort_order');
    }

    public function questions()
    {
        return $this->belongsToMany(Question::class, 'exam_questions')
            ->withPivot('sort_order', 'points_override')
            // The teacher-defined order lives on the pivot; keep it stable both
            // for the builder and for a student's attempt payload.
            ->orderBy('exam_questions.sort_order');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(ExamAttempt::class);
    }

    /**
     * Groups this exam is restricted to. Empty = visible to everyone enrolled
     * in the course.
     */
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(CourseGroup::class, 'course_group_exam', 'exam_id', 'course_group_id')
            ->withTimestamps();
    }

    /**
     * Visible to a student: either unrestricted (no group rows) or the student
     * is a member of at least one of the restricting groups.
     */
    public function scopeVisibleToStudent($query, string $userId)
    {
        return $query->where(function ($q) use ($userId) {
            $q->whereDoesntHave('groups')
                ->orWhereHas('groups.members', fn ($m) => $m->where('users.id', $userId));
        });
    }
}
