<?php

namespace App\Support;

/**
 * Canonical platform identity: منصة فهمني (short: فهمني, English: Fahimni).
 *
 * The platform name lives in the user-editable `platform_settings` row and in
 * environment config. Deploys never rewrite tenant data, so reads of a legacy
 * brand value are normalised here instead of being rewritten in the database:
 * any value that still carries the previous brand is presented as the official
 * one, while a genuinely custom name chosen by an admin is preserved.
 */
final class PlatformBrand
{
    public const NAME = 'منصة فهمني';

    public const NAME_SHORT = 'فهمني';

    public const NAME_EN = 'Fahimni';

    public const SUPPORT_EMAIL = 'support@fahimni.me';

    /** Legacy values that must never reach a user-visible surface again. */
    private const LEGACY_PATTERN = '/tadreesly|تدريسلي|تدريسلى/iu';

    private const LEGACY_EMAILS = [
        'support@tadreesly.com',
        'info@tadreesly.com',
        'hello@tadreesly.com',
    ];

    /** Map a legacy brand name to the official one; keep anything custom. */
    public static function normalize(?string $value): string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return self::NAME;
        }

        return preg_match(self::LEGACY_PATTERN, $value) === 1 ? self::NAME : $value;
    }

    /** Normalise a contact address, mapping legacy support mailboxes to the new one. */
    public static function email(?string $value): string
    {
        $value = strtolower(trim((string) $value));

        if ($value === '' || in_array($value, self::LEGACY_EMAILS, true)) {
            return self::SUPPORT_EMAIL;
        }

        return $value;
    }
}
