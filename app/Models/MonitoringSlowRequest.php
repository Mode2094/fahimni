<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MonitoringSlowRequest extends Model
{
    protected $fillable = [
        'method', 'path', 'status', 'duration_ms', 'route', 'source', 'occurred_at',
    ];

    protected $casts = [
        'status' => 'integer',
        'duration_ms' => 'integer',
        'occurred_at' => 'datetime',
    ];

    public $timestamps = false;
}