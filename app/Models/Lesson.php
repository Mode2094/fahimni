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

class Lesson extends Model
{
    use BelongsToTenant, HasFactory, HasUuid, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'section_id', 'title', 'description',
        'duration_minutes', 'sort_order', 'is_published',
        'is_free_preview', 'lesson_type', 'metadata',
    ];

    protected $casts = [
        'duration_minutes' => 'integer',
        'is_published' => 'boolean',
        'is_free_preview' => 'boolean',
        'metadata' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function contents(): HasMany
    {
        return $this->hasMany(LessonContent::class)->orderBy('sort_order');
    }

    public function progress(): HasMany
    {
        return $this->hasMany(LessonProgress::class);
    }

    /**
     * Groups this lesson is restricted to. Empty = visible to everyone enrolled
     * in the course.
     */
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(CourseGroup::class, 'course_group_lesson', 'lesson_id', 'course_group_id')
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

    /**
     * 1-based position of this lesson across the whole course, in the order
     * sections appear (then lessons by sort_order). Used for drip-content
     * unlocking; sort_order alone is only unique within a section.
     */
    public function positionInCourse(): int
    {
        $courseId = $this->section?->course_id;

        if (! $courseId) {
            return $this->sort_order;
        }

        $position = 0;
        $sections = Section::where('course_id', $courseId)->orderBy('sort_order')->get(['id']);

        foreach ($sections as $section) {
            foreach (Lesson::where('section_id', $section->id)->orderBy('sort_order')->pluck('id') as $lessonId) {
                $position++;

                if ($lessonId === $this->id) {
                    return $position;
                }
            }
        }

        return max($position, (int) $this->sort_order);
    }
}
