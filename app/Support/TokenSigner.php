<?php

namespace App\Support;

final class TokenSigner
{
    public static function primaryKey(): string
    {
        return self::decodeKey((string) config('stream.signing_key'));
    }

    public static function sign(string $encoded): string
    {
        return self::base64UrlEncode(hash_hmac('sha256', $encoded, self::primaryKey(), true));
    }

    public static function matches(string $encoded, string $signature): bool
    {
        foreach (self::keys() as $key) {
            $computed = self::base64UrlEncode(hash_hmac('sha256', $encoded, $key, true));

            if (hash_equals($computed, $signature)) {
                return true;
            }
        }

        return false;
    }

    public static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    public static function base64UrlDecode(string $value): string
    {
        return base64_decode(strtr($value, '-_', '+/'));
    }

    private static function keys(): array
    {
        $keys = [self::primaryKey()];
        $previous = config('stream.previous_signing_key');

        if (is_string($previous) && $previous !== '') {
            $keys[] = self::decodeKey($previous);
        }

        return $keys;
    }

    private static function decodeKey(string $raw): string
    {
        return str_starts_with($raw, 'base64:')
            ? base64_decode(substr($raw, 7))
            : $raw;
    }
}
