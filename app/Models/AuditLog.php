<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    use BelongsToTenant, HasUuid;

    protected $fillable = [
        'user_id', 'tenant_id', 'action', 'entity_type', 'entity_id',
        'ip_address', 'user_agent', 'details',
    ];

    protected $casts = [
        'details' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
