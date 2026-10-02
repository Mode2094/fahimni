<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ScheduledLesson extends Model
{
    use BelongsToTenant, HasFactory, HasUuid, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'course_id', 'title', 'description', 'lesson_date',
        'start_time', 'end_time', 'is_recurring', 'day_of_week',
        'repeat_until', 'provider', 'meeting_url', 'status',
    ];

    protected $casts = [
        'lesson_date' => 'date',
        'start_time' => 'string',
        'end_time' => 'string',
        'is_recurring' => 'boolean',
        'day_of_week' => 'integer',
        'repeat_until' => 'date',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }
}
