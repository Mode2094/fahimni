<?php

namespace App\Support;

/**
 * RFC 6238 TOTP (no external dependencies).
 */
class TOTP
{
    private const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateSecret(int $length = 32): string
    {
        $secret = '';
        $max = strlen(self::BASE32) - 1;
        for ($i = 0; $i < $length; $i++) {
            $secret .= self::BASE32[random_int(0, $max)];
        }

        return $secret;
    }

    public static function code(string $secret, int $timestamp = 0): string
    {
        $counter = floor(($timestamp ?: time()) / 30);
        $key = self::base32Decode($secret);
        $packed = pack('N2', ($counter >> 32) & 0xFFFFFFFF, $counter & 0xFFFFFFFF);
        $hash = hash_hmac('sha1', $packed, $key, true);
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $value = unpack('N', substr($hash, $offset, 4))[1] & 0x7FFFFFFF;

        return str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT);
    }

    public static function verify(string $secret, string $code, int $window = 1): bool
    {
        $code = trim($code);
        if (! preg_match('/^\d{6}$/', $code)) {
            return false;
        }
        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals(self::code($secret, time() + $i * 30), $code)) {
                return true;
            }
        }

        return false;
    }

    public static function otpauthUrl(string $secret, string $account, string $issuer = 'Tadreesly'): string
    {
        return 'otpauth://totp/'
            .rawurlencode($issuer.':'.$account)
            .'?secret='.$secret
            .'&issuer='.rawurlencode($issuer)
            .'&algorithm=SHA1&digits=6&period=30';
    }

    private static function base32Decode(string $secret): string
    {
        $secret = strtoupper(str_replace(' ', '', $secret));
        $bits = '';
        foreach (str_split($secret) as $char) {
            $pos = strpos(self::BASE32, $char);
            if ($pos === false) {
                continue;
            }
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $bytes = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $bytes .= chr(bindec($chunk));
            }
        }

        return $bytes;
    }
}
