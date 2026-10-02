<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Assignment extends Model
{
    use BelongsToTenant, HasFactory, HasUuid, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'course_id', 'section_id', 'title', 'description',
        'points', 'due_at', 'allow_late', 'is_published', 'sort_order',
        'file_url', 'file_name', 'file_size_bytes', 'metadata',
    ];

    protected $casts = [
        'points' => 'decimal:2',
        'due_at' => 'datetime',
        'allow_late' => 'boolean',
        'is_published' => 'boolean',
        'sort_order' => 'integer',
        'file_size_bytes' => 'integer',
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

    public function submissions(): HasMany
    {
        return $this->hasMany(AssignmentSubmission::class);
    }

    public function isLate(?AssignmentSubmission $submission = null): bool
    {
        if (! $this->due_at || ! $this->allow_late) {
            return false;
        }

        $submittedAt = $submission?->submitted_at;

        return $submittedAt !== null && $this->due_at->lt($submittedAt);
    }
}
