<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-recipient delivery + read receipt. One row per teacher regardless of
 * how many institutions they belong to, so nobody is notified twice.
 */
class AdminNotificationReceipt extends Model
{
    use HasUuid;

    protected $fillable = [
        'admin_notification_id', 'user_id',
        'delivered_at', 'read_at', 'notified_at',
    ];

    protected $casts = [
        'delivered_at' => 'datetime',
        'read_at' => 'datetime',
        'notified_at' => 'datetime',
    ];

    public function notification(): BelongsTo
    {
        return $this->belongsTo(AdminNotification::class, 'admin_notification_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
