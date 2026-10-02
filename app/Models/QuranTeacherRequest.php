<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class QuranTeacherRequest extends Model
{
    use HasFactory, HasUuid;

    public $incrementing = false;
    protected $keyType = 'string';

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'name',
        'email',
        'phone',
        'city',
        'country',
        'memorized_juz',
        'tajweed_level',
        'message',
        'plan_id',
        'status',
        'admin_notes',
        'approved_by',
        'reviewed_at',
    ];

    public function plan()
    {
        return $this->belongsTo(\App\Models\SubscriptionPlan::class, 'plan_id');
    }

    protected $casts = [
        'reviewed_at' => 'datetime',
    ];
}
