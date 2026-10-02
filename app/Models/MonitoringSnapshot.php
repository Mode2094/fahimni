<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MonitoringSnapshot extends Model
{
    protected $fillable = [
        'kind', 'payload', 'collected_at', 'duration_ms',
    ];

    protected $casts = [
        'payload' => 'array',
        'collected_at' => 'datetime',
        'duration_ms' => 'integer',
    ];

    public $timestamps = false;

    public const KIND_HOST = 'host';

    public const KIND_APP = 'app';

    public const KIND_DATABASE = 'database';

    public const KIND_REDIS = 'redis';

    public const KIND_QUEUE = 'queue';

    public const KIND_BACKUP = 'backup';

    public const KIND_STORAGE = 'storage';

    public const KIND_STREAMING = 'streaming';

    public const KIND_SECURITY = 'security';
}