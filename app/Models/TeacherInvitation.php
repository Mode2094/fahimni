<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeacherInvitation extends Model
{
    use BelongsToTenant, HasFactory, HasUuid;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Allowed staff permissions. Empty/null permission lists keep full
     * teacher access (backwards compatible); a non-empty list acts as an
     * allowlist that must be enforced by action guards.
     */
    public const PERMISSION_CATALOG = [
        'courses.manage',
        'students.manage',
        'cards.manage',
        'exams.manage',
        'questions.manage',
        'announcements.manage',
        'live.manage',
        'schedule.manage',
        'blog.manage',
        'community.manage',
        'settings.manage',
        'analytics.view',
    ];

    protected $fillable = [
        'tenant_id', 'email', 'role', 'permissions', 'token',
        'status', 'invited_by', 'expires_at', 'accepted_at',
    ];

    protected $casts = [
        'permissions' => 'array',
        'expires_at' => 'datetime',
        'accepted_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }
}
