<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

class Integration extends Model
{
    use BelongsToTenant, HasFactory, HasUuid;

    protected $fillable = [
        'tenant_id', 'provider', 'label', 'enabled', 'credentials',
        'config', 'metadata', 'connected_at', 'last_tested_at', 'last_test_result',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'config' => 'array',
        'metadata' => 'array',
        'last_test_result' => 'array',
        'connected_at' => 'datetime',
        'last_tested_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(IntegrationEvent::class);
    }

    public function scopePlatform($query)
    {
        return $query->whereNull('tenant_id');
    }

    public function scopeForTenant($query, ?string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function setCredentialsAttribute(?string $value): void
    {
        $this->attributes['credentials'] = $value === null ? null : Crypt::encryptString($value);
    }

    public function getCredentialsAttribute(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (\Throwable) {
            return null;
        }
    }

    public function credentialArray(): array
    {
        $decoded = json_decode((string) $this->credentials, true);

        return is_array($decoded) ? $decoded : [];
    }

    public function fillCredentials(array $credentials): void
    {
        $this->credentials = json_encode(array_merge($this->credentialArray(), $credentials));
    }

    public function forgetCredential(string $key): void
    {
        $credentials = $this->credentialArray();
        unset($credentials[$key]);
        $this->credentials = json_encode($credentials);
    }

    public function isConfigured(): bool
    {
        return $this->credentials !== null && $this->credentials !== '[]' && $this->credentials !== '';
    }
}
