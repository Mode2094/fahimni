<?php

namespace App\Monitoring;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * MetricsRegistry — Redis-backed counters for request-level and security
 * metrics. Deliberately tiny: a single increment()/read()/flush() surface
 * keeps every caller cheap and side-effect free.
 *
 * Safety rules:
 *  - never stores secrets, tokens, headers or bodies (only integer counters
 *    and short status/path labels),
 *  - degrades to an in-memory array when Redis is unreachable so the app
 *    never breaks because monitoring is down.
 */
class MetricsRegistry
{
    /** In-memory fallback used when Redis is unavailable (per request). */
    private static array $fallback = [];

    private static bool $fallbackMode = false;

    private static function prefix(): string
    {
        return (string) config('monitoring.redis_prefix', 'monitoring:');
    }

    private static function available(): bool
    {
        try {
            Redis::connection()->ping();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private static function useFallback(): void
    {
        if (! self::$fallbackMode) {
            self::$fallbackMode = true;
            Log::debug('monitoring.redis_unavailable', ['event' => 'metrics_fallback_memory']);
        }
    }

    public static function increment(string $key, int $by = 1): void
    {
        if (! config('monitoring.enabled', true)) {
            return;
        }

        $full = self::prefix().$key;

        if (self::available()) {
            try {
                Redis::connection()->incrby($full, $by);

                return;
            } catch (\Throwable) {
                self::useFallback();
            }
        } else {
            self::useFallback();
        }

        self::$fallback[$full] = (self::$fallback[$full] ?? 0) + $by;
    }

    public static function counter(string $key): int
    {
        $full = self::prefix().$key;

        if (self::available()) {
            try {
                return (int) (Redis::connection()->get($full) ?? 0);
            } catch (\Throwable) {
                self::useFallback();
            }
        } else {
            self::useFallback();
        }

        return (int) (self::$fallback[$full] ?? 0);
    }

    /**
     * Read-and-reset all counters under a prefix atomically. Used by the
     * snapshot collector to convert counters into durable rows.
     *
     * @return array<string, int>
     */
    public static function flush(string $prefix): array
    {
        $fullPrefix = self::prefix().$prefix;

        if (self::available()) {
            try {
                return Redis::connection()->transaction(function ($tx) use ($fullPrefix) {
                    $keys = $tx->keys($fullPrefix.'*') ?: [];

                    $result = [];
                    foreach ($keys as $key) {
                        $result[$key] = (int) ($tx->get($key) ?? 0);
                    }

                    if ($keys !== []) {
                        $tx->del($keys);
                    }

                    return $result;
                }) ?: [];
            } catch (\Throwable) {
                self::useFallback();
            }
        } else {
            self::useFallback();
        }

        $result = [];
        foreach (self::$fallback as $key => $value) {
            if (str_starts_with($key, $fullPrefix)) {
                $result[$key] = (int) $value;
            }
        }
        self::$fallback = [];

        return $result;
    }

    /** Set a plain value (used for scheduler heartbeat timestamps). */
    public static function set(string $key, string $value, int $ttlSeconds = 300): void
    {
        $full = self::prefix().$key;

        if (self::available()) {
            try {
                Redis::connection()->setex($full, $ttlSeconds, $value);

                return;
            } catch (\Throwable) {
                self::useFallback();
            }
        } else {
            self::useFallback();
        }

        self::$fallback[$full] = $value;
    }

    public static function get(string $key): ?string
    {
        $full = self::prefix().$key;

        if (self::available()) {
            try {
                $value = Redis::connection()->get($full);

                return $value === false ? null : (string) $value;
            } catch (\Throwable) {
                self::useFallback();
            }
        } else {
            self::useFallback();
        }

        return isset(self::$fallback[$full]) ? (string) self::$fallback[$full] : null;
    }
}