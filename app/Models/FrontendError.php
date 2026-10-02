<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FrontendError extends Model
{
    protected $fillable = [
        'tenant_id', 'app', 'url', 'method', 'status', 'type',
        'message', 'stack_hash', 'user_agent', 'locale', 'metadata', 'created_at',
    ];

    protected $casts = [
        'status' => 'integer',
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    public $timestamps = false;
}