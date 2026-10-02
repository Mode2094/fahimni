<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class IntegrationEvent extends Model
{
    use BelongsToTenant, HasFactory, HasUuid;

    protected $fillable = [
        'tenant_id', 'integration_id', 'provider', 'direction',
        'event', 'payload', 'status', 'error',
    ];

    protected $casts = [
        'payload' => 'array',
    ];
}
