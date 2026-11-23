<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A teacher-defined package of courses. Content is never duplicated — a
 * bundle only references existing courses, so every lesson/file/exam keeps a
 * single owner.
 */
class Bundle extends Model
{
    use BelongsToTenant, HasFactory, HasUuid, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'title', 'slug', 'description', 'short_description',
        'thumbnail_url', 'price', 'currency', 'is_published', 'is_featured',
        'sort_order', 'metadata',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'is_published' => 'boolean',
        'is_featured' => 'boolean',
        'sort_order' => 'integer',
        'metadata' => 'array',
    ];

    public function courses(): BelongsToMany
    {
        return $this->belongsToMany(Course::class, 'bundle_courses')
            ->withPivot('sort_order')
            ->withTimestamps()
            ->orderBy('bundle_courses.sort_order');
    }
}
