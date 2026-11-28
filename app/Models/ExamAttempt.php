<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExamAttempt extends Model
{
    use BelongsToTenant, HasFactory, HasUuid;

    protected $fillable = [
        'tenant_id', 'exam_id', 'user_id', 'academic_term_id', 'attempt_number', 'status',
        'assessment_mode', 'score', 'total_points', 'percentage', 'passed',
        'needs_review', 'reviewed_at', 'reviewed_by',
        'started_at', 'submitted_at', 'completed_at', 'time_spent_seconds', 'metadata',
    ];

    protected $casts = [
        'score' => 'decimal:2',
        'total_points' => 'decimal:2',
        'percentage' => 'decimal:2',
        'passed' => 'boolean',
        'needs_review' => 'boolean',
        'reviewed_at' => 'datetime',
        'started_at' => 'datetime',
        'submitted_at' => 'datetime',
        'completed_at' => 'datetime',
        'time_spent_seconds' => 'integer',
        'metadata' => 'array',
    ];

    /** True for an ungraded interactive skill-practice attempt. */
    public function isPractice(): bool
    {
        return $this->assessment_mode === Exam::MODE_PRACTICE;
    }

    /**
     * Exclude ungraded skill-practice attempts from a graded aggregate
     * (averages, pass rates, rankings). Legacy rows with a NULL mode are
     * graded — never silently dropped.
     */
    public function scopeExcludePractice($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('assessment_mode')->orWhere('assessment_mode', '!=', Exam::MODE_PRACTICE);
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function academicTerm(): BelongsTo
    {
        return $this->belongsTo(AcademicTerm::class);
    }

    public function answers(): HasMany
    {
        return $this->hasMany(ExamAnswer::class);
    }
}
