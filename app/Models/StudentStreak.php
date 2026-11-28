<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Consecutive-day activity streak for one student within one tenant.
 */
class StudentStreak extends Model
{
    use BelongsToTenant, HasFactory, HasUuid;

    protected $fillable = [
        'tenant_id', 'user_id', 'current_streak', 'longest_streak', 'last_activity_date',
    ];

    protected $casts = [
        'current_streak' => 'integer',
        'longest_streak' => 'integer',
        'last_activity_date' => 'date',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
