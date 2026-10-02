<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class TenantUser extends Pivot
{
    use BelongsToTenant;

    protected $table = 'tenant_user';

    protected $fillable = [
        'tenant_id', 'user_id', 'role', 'status', 'joined_at', 'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'joined_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
