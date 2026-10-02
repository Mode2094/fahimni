<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MediaUsageLedger extends Model
{
    protected $table = 'media_usage_ledger';

    use BelongsToTenant, HasFactory, HasUuid;

    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id', 'user_id', 'resource', 'delta', 'bytes_delta', 'action',
        'reference_id', 'reference_type', 'idempotency_key', 'metadata', 'created_at',
    ];

    protected $casts = [
        'delta' => 'integer',
        'bytes_delta' => 'integer',
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];
}
