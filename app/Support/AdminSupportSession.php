<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Server-side marker for a Super-Admin "support session" (Admin Impersonation).
 *
 * This is deliberately SEPARATE from the read-only `view_as` marker so the
 * existing preview contract (and its tests) are untouched. A support session
 * lets a platform admin actually work inside ONE teacher's workspace — manage
 * content/settings/domain — while:
 *
 *  - the acting admin's identity is stored here (`actor_id`) and the session is
 *    restored to them on exit or expiry, so the original admin session is never
 *    lost;
 *  - the session is short-lived (`expires_at`) and re-checked on every request;
 *  - only the ONE target tenant is affected — the marker carries `tenant_id`.
 *
 * Writes that pass while a session is active are audited with the REAL admin
 * identity by {@see \App\Http\Middleware\BlockSensitiveSupportWrites}.
 */
class AdminSupportSession
{
    public const KEY = 'admin_support';

    /** How long a support session may stay open. */
    public const TTL_MINUTES = 30;

    /**
     * @return array{actor_id: string, actor_name?: ?string, user_id: string, user_name?: ?string, tenant_id?: ?string, mode: string, started_at?: ?string, expires_at?: ?string, reason?: ?string}|null
     */
    public static function get(Request $request): ?array
    {
        if (! $request->hasSession()) {
            return null;
        }

        $state = $request->session()->get(self::KEY);

        if (! is_array($state) || empty($state['user_id']) || empty($state['actor_id'])) {
            return null;
        }

        return $state;
    }

    public static function put(Request $request, User $actor, User $target, ?string $tenantId, ?string $reason): void
    {
        $request->session()->put(self::KEY, [
            'actor_id' => $actor->id,
            'actor_name' => $actor->name,
            'user_id' => $target->id,
            'user_name' => $target->name,
            'tenant_id' => $tenantId,
            'mode' => 'support',
            'started_at' => now()->toIso8601String(),
            'expires_at' => now()->addMinutes(self::TTL_MINUTES)->toIso8601String(),
            'reason' => $reason,
        ]);
    }

    public static function forget(Request $request): void
    {
        if ($request->hasSession()) {
            $request->session()->forget(self::KEY);
        }
    }

    public static function isExpired(array $state): bool
    {
        $expiresAt = $state['expires_at'] ?? null;

        if (! is_string($expiresAt) || $expiresAt === '') {
            return false;
        }

        try {
            return now()->greaterThanOrEqualTo(Carbon::parse($expiresAt));
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * The banner/state payload the frontend reads from `/auth/me`. Never exposes
     * any credential — only who is being supported and by whom.
     *
     * @return array<string, mixed>|null
     */
    public static function payload(Request $request): ?array
    {
        $state = self::get($request);

        if (! $state) {
            return null;
        }

        return [
            'active' => true,
            'mode' => 'support',
            'actor_id' => $state['actor_id'] ?? null,
            'actor_name' => $state['actor_name'] ?? null,
            'user_id' => $state['user_id'],
            'user_name' => $state['user_name'] ?? null,
            'tenant_id' => $state['tenant_id'] ?? null,
            'started_at' => $state['started_at'] ?? null,
            'expires_at' => $state['expires_at'] ?? null,
        ];
    }
}
