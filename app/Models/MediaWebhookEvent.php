<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MediaWebhookEvent extends Model
{
    use HasFactory, HasUuid;

    public const CREATED_AT = null;

    public const UPDATED_AT = null;

    protected $fillable = [
        'provider', 'event_type', 'provider_event_id', 'event_hash', 'payload',
        'status', 'error', 'received_at', 'processed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'received_at' => 'datetime',
        'processed_at' => 'datetime',
    ];
}
