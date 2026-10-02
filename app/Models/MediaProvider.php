<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class MediaProvider extends Model
{
    use HasFactory, HasUuid;

    protected $fillable = [
        'provider', 'label', 'enabled', 'credentials', 'webhook_secret',
        'config', 'environment', 'connection_status', 'last_health_check_at',
        'last_synced_at', 'last_error', 'metadata',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'config' => 'array',
        'metadata' => 'array',
        'last_health_check_at' => 'datetime',
        'last_synced_at' => 'datetime',
    ];

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

    public function setWebhookSecretAttribute(?string $value): void
    {
        $this->attributes['webhook_secret'] = $value === null ? null : Crypt::encryptString($value);
    }

    public function getWebhookSecretAttribute(?string $value): ?string
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
        return json_decode((string) $this->credentials, true) ?? [];
    }

    public function fillCredentials(array $credentials): void
    {
        $merged = array_merge($this->credentialArray(), $credentials);
        $this->credentials = json_encode($merged);
    }

    public function isConfigured(): bool
    {
        $creds = $this->credentialArray();

        return $this->enabled && $creds !== [];
    }
}
