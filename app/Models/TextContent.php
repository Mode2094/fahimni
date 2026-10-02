<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TextContent extends Model
{
    use BelongsToTenant, HasFactory, HasUuid;

    protected $fillable = [
        'lesson_content_id', 'tenant_id', 'body', 'format',
        'word_count', 'reading_time_minutes',
    ];

    protected $casts = [
        'word_count' => 'integer',
        'reading_time_minutes' => 'integer',
    ];

    public function lessonContent(): BelongsTo
    {
        return $this->belongsTo(LessonContent::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
