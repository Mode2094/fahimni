<?php

namespace App\Support;

use App\Models\TenantSetting;

/**
 * Leaderboard privacy policy — the single authority for whether and how a
 * tenant's student ranking is shown to students.
 *
 * Student-facing responses must never leak another student's identity. The
 * teacher keeps full, identifiable access; students get one of three modes:
 *
 *   disabled  → no ranking at all (the student still sees their own position).
 *   anonymous → ranks and points are visible but every other identity is
 *               masked (no name, no avatar, no internal id).
 *   named     → the classic behaviour (names shown). Opt-in by the teacher.
 *
 * The default is `anonymous`: privacy-preserving, and it still motivates with
 * a leaderboard. A teacher can switch to `named` deliberately, or turn it off.
 */
class LeaderboardPolicy
{
    public const MODE_DISABLED = 'disabled';

    public const MODE_ANONYMOUS = 'anonymous';

    public const MODE_NAMED = 'named';

    /** Placeholder shown for other students when the board is anonymous. */
    public const ANON_LABEL = 'طالب';

    public const MODES = [self::MODE_DISABLED, self::MODE_ANONYMOUS, self::MODE_NAMED];

    public static function mode(string $tenantId): string
    {
        $metadata = TenantSetting::where('tenant_id', $tenantId)->first()?->metadata ?? [];
        $mode = (string) ($metadata['leaderboard_mode'] ?? self::MODE_ANONYMOUS);

        return in_array($mode, self::MODES, true) ? $mode : self::MODE_ANONYMOUS;
    }

    public static function enabled(string $tenantId): bool
    {
        return self::mode($tenantId) !== self::MODE_DISABLED;
    }

    public static function anonymous(string $tenantId): bool
    {
        return self::mode($tenantId) === self::MODE_ANONYMOUS;
    }
}
