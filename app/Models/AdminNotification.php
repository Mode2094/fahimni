<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Platform-level notification from the Super Admin to teachers.
 *
 * NOT tenant-scoped on purpose: it is the central source of truth across all
 * institutions. See the migration for the separation from teacher
 * announcements.
 */
class AdminNotification extends Model
{
    use HasFactory, HasUuid;

    public const CATEGORIES = ['news', 'update', 'alert', 'maintenance'];
    public const STATUSES = ['draft', 'sent', 'retracted'];

    protected $fillable = [
        'category', 'title', 'body', 'audience', 'status',
        'channels', 'created_by', 'published_at', 'retracted_at',
    ];

    protected $casts = [
        'channels' => 'array',
        'published_at' => 'datetime',
        'retracted_at' => 'datetime',
    ];

    public function targets(): HasMany
    {
        return $this->hasMany(AdminNotificationTarget::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(AdminNotificationReceipt::class);
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(AdminNotificationRevision::class)->orderBy('created_at');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
