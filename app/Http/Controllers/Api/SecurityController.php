<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\PlatformSetting;
use App\Support\AuditLogger;
use App\Support\MathCaptcha;
use App\Support\TOTP;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;

class SecurityController extends Controller
{
    // ─── CAPTCHA ─────────────────────────────────
    public function captcha(): JsonResponse
    {
        $enabled = (bool) PlatformSetting::get('captcha_enabled', false);
        if (! $enabled) {
            return response()->json(['enabled' => false]);
        }

        return response()->json(['enabled' => true, ...MathCaptcha::create()]);
    }

    // ─── 2FA ─────────────────────────────────────
    public function twoFactorSetup(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user->two_factor_enabled) {
            return response()->json(['message' => '2FA is already enabled.'], 422);
        }

        // Re-authentication: changing 2FA with only a (possibly stolen)
        // bearer token would let an attacker lock the real owner out.
        $request->validate([
            'password' => 'required|string',
            'code' => 'sometimes|required|string',
        ]);

        if (! Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Password is incorrect.'], 422);
        }

        $secret = TOTP::generateSecret();
        $user->forceFill([
            'two_factor_secret' => Crypt::encryptString($secret),
            'two_factor_enabled' => false,
        ])->save();

        return response()->json([
            'secret' => $secret,
            'otpauth_url' => TOTP::otpauthUrl($secret, $user->email),
        ]);
    }

    public function twoFactorEnable(Request $request): JsonResponse
    {
        $user = $request->user();

        // Per-account brute-force guard: 5 attempts per 15 minutes.
        $failKey = '2fa_enable_failures:'.$user->id;
        $failures = (int) Cache::get($failKey, 0);

        if ($failures >= 5) {
            return response()->json(['message' => 'Too many failed attempts. Try again later.'], 429);
        }

        try {
            $secret = $user->two_factor_secret ? Crypt::decryptString($user->two_factor_secret) : null;
        } catch (\Throwable) {
            return response()->json(['message' => '2FA configuration error. Contact support.'], 422);
        }

        if (! $secret) {
            return response()->json(['message' => 'Run setup first.'], 422);
        }

        $request->validate(['code' => 'required|string']);

        if (! TOTP::verify($secret, $request->code)) {
            Cache::put($failKey, $failures + 1, now()->addMinutes(15));
            AuditLogger::log($request, '2fa.enable_failed', 'user', $user->id);

            return response()->json(['message' => 'Invalid verification code.'], 422);
        }

        Cache::forget($failKey);

        $user->forceFill([
            'two_factor_enabled' => true,
            'two_factor_confirmed_at' => now(),
        ])->save();

        AuditLogger::log($request, '2fa.enabled', 'user', $user->id);

        return response()->json(['message' => '2FA enabled successfully.']);
    }

    public function twoFactorDisable(Request $request): JsonResponse
    {
        $user = $request->user();

        // Re-authentication is mandatory to disable 2FA.
        $request->validate([
            'password' => 'required|string',
            'code' => 'sometimes|required|string',
        ]);

        if (! Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Password is incorrect.'], 422);
        }

        try {
            $secret = $user->two_factor_secret ? Crypt::decryptString($user->two_factor_secret) : null;
        } catch (\Throwable) {
            return response()->json(['message' => '2FA configuration error. Contact support.'], 422);
        }

        if (! $secret) {
            return response()->json(['message' => '2FA is not configured.'], 422);
        }

        if (! TOTP::verify($secret, $request->code)) {
            return response()->json(['message' => 'Invalid verification code.'], 422);
        }

        $user->forceFill([
            'two_factor_enabled' => false,
            'two_factor_secret' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        AuditLogger::log($request, '2fa.disabled', 'user', $user->id);

        return response()->json(['message' => '2FA disabled.']);
    }

    // ─── Audit logs ─────────────────────────────
    public function auditLogs(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = AuditLog::withoutTenant()->with('user')->orderByDesc('created_at');

        if (! $user->is_super_admin) {
            $pivot = $user->tenantPivots()
                ->where('tenant_id', $request->current_tenant->id)
                ->where('status', 'active')
                ->first();

            if (! $pivot || ! in_array($pivot->role, ['teacher', 'owner', 'admin'], true)) {
                return response()->json(['message' => 'Forbidden. Staff access required.'], 403);
            }

            $query->where('tenant_id', $request->current_tenant->id);
        }

        if ($request->filled('action')) {
            $query->where('action', $request->action);
        }
        if ($request->filled('search')) {
            $query->whereHas('user', fn ($q) => $q->where('name', 'ilike', '%'.$request->search.'%')->orWhere('email', 'ilike', '%'.$request->search.'%'));
        }

        $logs = $query->limit(200)->get();

        return response()->json([
            'logs' => $logs->map(fn ($l) => [
                'id' => $l->id,
                'user_name' => $l->user?->name,
                'user_email' => $l->user?->email,
                'action' => $l->action,
                'entity_type' => $l->entity_type,
                'entity_id' => $l->entity_id,
                'ip_address' => $l->ip_address,
                'details' => $l->details,
                'created_at' => $l->created_at?->toIso8601String(),
            ]),
        ]);
    }
}
