<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MediaCost extends Model
{
    use BelongsToTenant, HasFactory, HasUuid;

    public const CREATED_AT = null;

    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id', 'year_month', 'provider', 'resource', 'amount', 'currency', 'metadata', 'calculated_at',
    ];

    protected $casts = [
        'amount' => 'decimal:6',
        'metadata' => 'array',
        'calculated_at' => 'datetime',
    ];
}
