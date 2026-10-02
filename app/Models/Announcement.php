<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Announcement extends Model
{
    use BelongsToTenant, HasFactory, HasUuid;

    public const AUDIENCES = ['all', 'students', 'teachers', 'admins', 'course'];

    public const CHANNELS = ['database', 'mail', 'push', 'sms', 'whatsapp'];

    public const STATUSES = ['draft', 'scheduled', 'sent'];

    protected $fillable = [
        'tenant_id', 'sender_id', 'title', 'body', 'audience',
        'course_id', 'channels', 'status', 'scheduled_at', 'published_at',
    ];

    protected $casts = [
        'channels' => 'array',
        'scheduled_at' => 'datetime',
        'published_at' => 'datetime',
    ];

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }
}
