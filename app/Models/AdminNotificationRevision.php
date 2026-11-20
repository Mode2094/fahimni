<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable admin audit trail for a platform notification. The
 * `admin_notification_id` is a plain uuid (no FK) so the history survives a
 * hard delete of the notification itself.
 */
class AdminNotificationRevision extends Model
{
    use HasUuid;

    protected $fillable = [
        'admin_notification_id', 'action', 'changes', 'performed_by',
    ];

    protected $casts = [
        'changes' => 'array',
    ];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
