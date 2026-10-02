<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;

class Course extends Model
{
    use BelongsToTenant, HasFactory, HasUuid, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'teacher_id', 'title', 'slug', 'description', 'short_description',
        'thumbnail_url', 'cover_url', 'level', 'category', 'tags',
        'duration_hours', 'is_published', 'is_featured', 'sort_order',
        'price', 'max_students', 'completion_criteria', 'certificate_enabled',
        'certificate_template', 'certificate_accent_color',
        'certificate_signature_name', 'certificate_signature_title',
        'prerequisites', 'drip_enabled', 'drip_interval_days', 'metadata',
    ];

    protected $casts = [
        'duration_hours' => 'decimal:1',
        'is_published' => 'boolean',
        'drip_enabled' => 'boolean',
        'drip_interval_days' => 'integer',
        'is_featured' => 'boolean',
        'price' => 'decimal:2',
        'max_students' => 'integer',
        'certificate_enabled' => 'boolean',
        'metadata' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function certificates(): HasMany
    {
        return $this->hasMany(StudentCertificate::class);
    }

    public function sections(): HasMany
    {
        return $this->hasMany(Section::class);
    }

    public function lessons(): HasManyThrough
    {
        return $this->hasManyThrough(Lesson::class, Section::class);
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    public function questions(): HasMany
    {
        return $this->hasMany(Question::class);
    }

    public function exams(): HasMany
    {
        return $this->hasMany(Exam::class);
    }

    public function activationCards(): HasMany
    {
        return $this->hasMany(ActivationCard::class);
    }
}
