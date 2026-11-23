<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

class TrialGrant extends Model
{
    use HasUuid;

    protected $fillable = [
        'user_id', 'tenant_id', 'source', 'counted', 'granted_at',
    ];

    protected $casts = [
        'counted' => 'boolean',
        'granted_at' => 'datetime',
    ];
}
