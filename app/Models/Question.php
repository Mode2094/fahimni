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

class Question extends Model
{
    use BelongsToTenant, HasFactory, HasUuid, SoftDeletes;

    /**
     * The interactive HTML question type. Its display payload (html/css/js)
     * and its hidden grading rules both live in `metadata`, under the
     * `interactive` and `grading` keys respectively, so the answer key is
     * never part of the code sent to the student.
     */
    public const TYPE_INTERACTIVE_HTML = 'interactive_html';

    /** Every supported question type. Kept in one place so the requests and
     *  the grader cannot drift apart. */
    public const TYPES = [
        'multiple_choice', 'true_false', 'drag_drop', 'matching',
        'ordering', 'writing', 'fill_blank', 'file_upload',
        'interactive_html',
    ];

    protected $fillable = [
        'tenant_id', 'course_id', 'section_id', 'question_type',
        'question_text', 'difficulty', 'category', 'points',
        'explanation', 'is_active', 'metadata',
    ];

    protected $casts = [
        'points' => 'decimal:2',
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

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(QuestionOption::class)->orderBy('sort_order');
    }

    public function exams(): BelongsToMany
    {
        return $this->belongsToMany(Exam::class, 'exam_questions')
            ->withPivot(['sort_order', 'points_override']);
    }
}
