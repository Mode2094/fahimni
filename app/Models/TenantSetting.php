<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantSetting extends Model
{
    use BelongsToTenant, HasFactory, HasUuid;

    protected $fillable = [
        'tenant_id', 'academy_name', 'logo_url', 'favicon_url',
        'primary_color', 'secondary_color', 'accent_color', 'custom_domain',
        'custom_css', 'custom_js', 'email_from_name', 'email_logo_url',
        'terms_url', 'privacy_url', 'file_downloads_enabled', 'metadata',
    ];

    protected $casts = [
        'file_downloads_enabled' => 'boolean',
        'metadata' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
