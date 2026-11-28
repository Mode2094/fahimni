<?php

namespace App\Support;

/**
 * Phone normalization to E.164 without an external library.
 *
 * We deliberately validate by shape only (correct, international, 8–15 digits)
 * — proving ownership through an SMS/OTP is NOT required (the platform has no
 * active SMS verification). The goal is one canonical stored format so the
 * admin panel, WhatsApp links and notifications behave predictably.
 */
class PhoneNumber
{
    /**
     * Normalize a human-entered number to E.164, or null when it cannot be
     * interpreted as a valid international number.
     */
    public static function toE164(?string $raw, ?string $defaultDial = null): ?string
    {
        if ($raw === null) {
            return null;
        }

        // Drop every character that is not a digit or a leading «+» (spaces,
        // dashes, parentheses, Arabic-Indic digits are converted first).
        $value = self::normalizeDigits($raw);

        if ($value === '') {
            return null;
        }

        $dial = $defaultDial ?: (string) config('phone.default_dial', '+962');
        $dial = '+'.ltrim(preg_replace('/\D/', '', $dial) ?? '', '+');

        if (str_starts_with($value, '00')) {
            // International access prefix: 00962… → +962…
            $value = '+'.substr($value, 2);
        } elseif (! str_starts_with($value, '+')) {
            if (str_starts_with($value, '0')) {
                // National format: 0791234567 → +962791234567
                $value = $dial.substr($value, 1);
            } elseif (str_starts_with($value, ltrim($dial, '+'))) {
                // Country code without a plus: 962791234567 → +962791234567
                $value = '+'.$value;
            } else {
                // Bare national subscriber number.
                $value = $dial.$value;
            }
        }

        return preg_match('/^\+\d{8,15}$/', $value) === 1 ? $value : null;
    }

    public static function isValid(?string $raw, ?string $defaultDial = null): bool
    {
        return self::toE164($raw, $defaultDial) !== null;
    }

    /** Map Arabic-Indic and Persian digits to ASCII and strip separators. */
    private static function normalizeDigits(string $raw): string
    {
        $map = [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ];

        $raw = strtr(trim($raw), $map);

        // Keep a leading plus, then digits only.
        $plus = str_starts_with($raw, '+');

        return ($plus ? '+' : '').preg_replace('/\D/', '', $raw);
    }
}
