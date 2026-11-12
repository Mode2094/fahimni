<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

class PlatformSetting extends Model
{
    private const CACHE_TTL = 60;

    protected $fillable = ['key', 'value'];

    protected $casts = [
        'value' => 'json',
    ];


    /** Marker for values encrypted with Crypt (format-versioned). */
    public const SECRET_PREFIX = 'enc:v1:';

    /**
     * Keys that must never be stored as plaintext and must never be returned
     * by the settings API (the `*_set` booleans tell the UI they exist).
     *
     * @return list<string>
     */
    public static function secretKeys(): array
    {
        return [
            // Booking gateways (student ↔ teacher one-off payments).
            'paytabs_server_key',
            'paypal_secret',
        ];
    }

    /**
     * Read a secret, transparently supporting the migration transition:
     *  - `enc:v1:<ciphertext>`  → decrypted value
     *  - legacy plaintext        → returned as-is (until the migration runs)
     */
    public static function getSecret(string $key, mixed $default = null): mixed
    {
        $value = self::get($key, $default);

        if (! is_string($value) || ! str_starts_with($value, self::SECRET_PREFIX)) {
            return $value;
        }

        try {
            return Crypt::decryptString(substr($value, strlen(self::SECRET_PREFIX)));
        } catch (\Throwable) {
            return $default;
        }
    }

    /** Store a secret encrypted at rest. Empty values stay empty. */
    public static function setSecret(string $key, mixed $value): void
    {
        if ($value === null || trim((string) $value) === '') {
            self::set($key, $value);

            return;
        }

        self::set($key, self::SECRET_PREFIX.Crypt::encryptString((string) $value));
    }

    /** True when the raw stored value is already encrypted. */
    public static function isEncryptedSecret(mixed $value): bool
    {
        return is_string($value) && str_starts_with($value, self::SECRET_PREFIX);
    }

    public static function allFlattened(): array
    {
        return Cache::remember('platform_settings.all', self::CACHE_TTL, function () {
            return static::pluck('value', 'key')->all();
        });
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return Cache::remember('platform_settings.'.$key, self::CACHE_TTL, function () use ($key) {
            return static::where('key', $key)->value('value');
        }) ?? $default;
    }

    /**
     * Boolean read that tolerates raw string values ('false', '0', '')
     * left behind by manual DB edits — (bool) 'false' would be true.
     */
    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key, $default);

        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }

        return (bool) $value;
    }

    public static function set(string $key, mixed $value): void
    {
        static::updateOrCreate(
            ['key' => $key],
            ['value' => $value]
        );

        self::forgetCache($key);
    }

    public static function forgetCache(?string $key = null): void
    {
        if ($key !== null) {
            Cache::forget('platform_settings.'.$key);
        }

        Cache::forget('platform_settings.all');
    }

    public static function convertAmount(float|int|string $amount, string $currency): array
    {
        // Platform-wide single currency: JOD only.
        return [(float) $amount, 'JOD'];
    }
}
