<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExamAnswer extends Model
{
    use BelongsToTenant, HasFactory, HasUuid;

    protected $fillable = [
        'tenant_id', 'exam_attempt_id', 'exam_question_id', 'question_id',
        'given_answer', 'is_correct', 'points_earned', 'points_max',
        'time_spent_seconds', 'metadata',
    ];

    protected $casts = [
        'given_answer' => 'array',
        'is_correct' => 'boolean',
        'points_earned' => 'decimal:2',
        'points_max' => 'decimal:2',
        'time_spent_seconds' => 'integer',
        'metadata' => 'array',
    ];

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(ExamAttempt::class, 'exam_attempt_id');
    }

    public function examQuestion(): BelongsTo
    {
        return $this->belongsTo(ExamQuestion::class);
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
