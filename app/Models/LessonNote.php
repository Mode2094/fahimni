<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ملاحظة/علامة مرجعية خاصة بطالب واحد.
 *
 * `type = note` ملاحظة نصية، و`type = bookmark` علامة زمنية. `body` و
 * `position_seconds` كلاهما اختياري، فيمكن لعلامة مرجعية أن تحمل تعليقاً أو لا.
 */
class LessonNote extends Model
{
    use BelongsToTenant, HasFactory, HasUuid;

    public const TYPE_NOTE = 'note';

    public const TYPE_BOOKMARK = 'bookmark';

    /** أقصى طول للملاحظة — حدّ معقول يحمي من الإساءة. */
    public const MAX_BODY_LENGTH = 2000;

    protected $fillable = [
        'tenant_id', 'user_id', 'course_id', 'lesson_id', 'video_id',
        'type', 'body', 'position_seconds', 'duration_seconds',
    ];

    protected $casts = [
        'position_seconds' => 'integer',
        'duration_seconds' => 'integer',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function video(): BelongsTo
    {
        return $this->belongsTo(VideoContent::class, 'video_id');
    }
}
