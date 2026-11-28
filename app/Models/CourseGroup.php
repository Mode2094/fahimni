<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A named student group inside one course ("Grade 10 — A").
 *
 * Kept deliberately separate from `CommunityGroup` (the forum) and from
 * `AcademicTerm` (enrolment cycles). Belonging to a group is NOT an enrolment,
 * so a student in several groups still uses a single seat.
 */
class CourseGroup extends Model
{
    use BelongsToTenant, HasFactory, HasUuid, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'course_id', 'name', 'description',
        'sort_order', 'is_active', 'metadata',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
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

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'course_group_members', 'course_group_id', 'user_id')
            ->withTimestamps();
    }

    public function lessons(): BelongsToMany
    {
        return $this->belongsToMany(Lesson::class, 'course_group_lesson', 'course_group_id', 'lesson_id')
            ->withTimestamps();
    }

    public function exams(): BelongsToMany
    {
        return $this->belongsToMany(Exam::class, 'course_group_exam', 'course_group_id', 'exam_id')
            ->withTimestamps();
    }
}
