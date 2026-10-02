<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\Uid\UuidV7;

/**
 * تسجيل الدخول عبر Google (Gmail) — للمعلمين والطلاب والأدمن.
 * يعيد توجيه المتصفح إلى Google ثم يستقبل النتيجة ويصدر توكن Sanctum
 * ويعيد التوجيه إلى الواجهة مع التوكن.
 */
class GoogleAuthController extends Controller
{
    public function redirect(Request $request): JsonResponse
    {
        // Role is a UI hint only — the callback decides what may actually be
        // granted. `admin` can never self-provision (see callback()).
        $role = in_array($request->query('role', 'teacher'), ['teacher', 'student', 'admin'], true)
            ? (string) $request->query('role')
            : 'teacher';

        // Registration hints from the signup form. Passwords are NEVER
        // accepted here: they would end up in URLs, logs and cache.
        $name = trim((string) $request->query('name', ''));
        $phone = trim((string) $request->query('phone', ''));

        // Signed, expiring OAuth state: the callback refuses any state it did
        // not issue, so `role`/registration hints cannot be tampered with.
        $state = $this->signState([
            'role' => $role,
            'name' => $name !== '' ? Str::limit($name, 120, '') : null,
            'phone' => $phone !== '' ? Str::limit($phone, 40, '') : null,
            'exp' => time() + 600,
            'n' => bin2hex(random_bytes(8)),
        ]);

        try {
            $url = Socialite::driver('google')
                ->with(['state' => $state])
                ->stateless()
                ->redirect()
                ->getTargetUrl();

            return response()->json(['url' => $url]);
        } catch (\Throwable $e) {
            Log::error('google.redirect_failed', ['error' => $e->getMessage()]);

            return response()->json(['message' => 'Google OAuth is not configured (GOOGLE_CLIENT_ID/SECRET).'], 503);
        }
    }

    public function callback(Request $request)
    {
        // إعادة التوجيه للواجهة: البيئة المحلية (localhost) تُعامل محلياً
        // حتى لو كان FRONTEND_URL يشير لنطاق الإنتاج.
        $frontend = config('app.frontend_url');

        if (in_array($request->getHost(), ['localhost', '127.0.0.1'], true)) {
            $frontend = 'http://localhost:3000';
        }

        // The OAuth state must carry our HMAC signature — unsigned/tampered
        // state (e.g. a forged `role=admin`) is rejected outright.
        $state = $this->verifyState((string) $request->query('state', ''));

        if ($state === null) {
            return redirect()->away($frontend.'/auth/google/callback?error=invalid_state');
        }

        $role = in_array($state['role'] ?? '', ['teacher', 'student', 'admin'], true)
            ? (string) $state['role']
            : 'teacher';
        $regData = [
            'name' => $state['name'] ?? null,
            'phone' => $state['phone'] ?? null,
        ];

        try {
            $google = Socialite::driver('google')->stateless()->user();
        } catch (\Throwable $e) {
            Log::error('google.callback_failed', ['error' => $e->getMessage()]);

            return redirect()->away($frontend.'/auth/google/callback?error=google_failed');
        }

        $email = strtolower(trim((string) $google->getEmail()));
        $name = $regData['name'] ?? $google->getName() ?? $google->getNickname() ?? 'User';

        if ($email === '') {
            return redirect()->away($frontend.'/auth/google/callback?error=no_email');
        }

        $user = User::where('email', $email)->first();

        if (! $user) {
            // Admin accounts are never self-provisioned through OAuth: only
            // the configured platform super-admin email may be promoted.
            if ($role === 'admin' && ! $this->isConfiguredSuperAdminEmail($email)) {
                return redirect()->away($frontend.'/auth/google/callback?error=not_authorized');
            }

            $user = User::create([
                'id' => UuidV7::generate(),
                'name' => $name,
                'email' => $email,
                'phone' => $regData['phone'] ?? null,
                // Random unusable password — OAuth users set one if needed.
                'password' => Hash::make(Str::random(64)),
                'is_active' => true,
                'email_verified_at' => now(),
            ]);

            if ($role === 'admin') {
                $user->forceFill(['is_super_admin' => true])->save();
            }
        } else {
            // Never mutate credentials from OAuth profile hints — only the
            // name/phone submitted from the signup form.
            $updates = [];
            if (! empty($regData['name'])) {
                $updates['name'] = $regData['name'];
            }
            if (! empty($regData['phone'])) {
                $updates['phone'] = $regData['phone'];
            }
            if ($updates) {
                $user->update($updates);
            }

            if (! $user->is_active) {
                return redirect()->away($frontend.'/auth/google/callback?error=inactive');
            }

            // Google login must never bypass an enabled second factor.
            if ($user->two_factor_enabled) {
                if ($request->hasSession()) {
                    // Web session flow: the pending challenge stays server-side.
                    $request->session()->put('auth.2fa_pending', [
                        'user_id' => $user->id,
                        'expires_at' => now()->addMinutes(5)->getTimestamp(),
                    ]);

                    return redirect()->away($frontend.'/auth/google/callback?step=2fa');
                }

                $pending = bin2hex(random_bytes(16));
                Cache::put('2fa:'.$pending, $user->id, now()->addMinutes(5));

                return redirect()->away($frontend.'/auth/google/callback#requires_2fa=1&two_factor_token='.$pending);
            }
        }

        // إنشاء Tenant خاص للمستخدم الجديد (منصة) — نفس منطق التسجيل العادي.
        // Students never get an owner tenant from an OAuth login.
        $tenant = null;
        $membership = TenantUser::where('user_id', $user->id)->first();

        if (! $membership && in_array($role, ['teacher', 'admin'], true)) {
            $tenant = Tenant::create([
                'id' => UuidV7::generate(),
                'owner_user_id' => $user->id,
                'name' => $name.'\'s Platform',
                'slug' => 'platform-'.strtolower(Str::random(6)),
                'is_active' => true,
            ]);

            TenantUser::create([
                'id' => UuidV7::generate(),
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'role' => 'owner',
                'status' => 'active',
                'joined_at' => now(),
            ]);
        } else {
            $tenant = Tenant::find($membership->tenant_id);
        }

        // Web browser flow: sign in with an HttpOnly session cookie — the
        // Sanctum token never leaves the server. Mobile keeps using the
        // bearer-token flow of /api/v1/mobile/auth/google.
        if ($request->hasSession()) {
            Auth::guard('web')->login($user);
            $request->session()->regenerate();

            return redirect()->away($frontend.'/auth/google/callback?step=done');
        }

        $token = $user->createToken('auth-token')->plainTextToken;

        // Sensitive values go in the URL *fragment*: fragments are never sent
        // to servers, so tokens cannot leak into access logs or Referer.
        $fragment = http_build_query([
            'token' => $token,
            'tenant_id' => $tenant?->id ?? '',
            'role' => $role,
            'user' => json_encode([
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,
                'phone' => $user->phone ?? null,
                'roles' => $user->roles->pluck('name')->toArray(),
            ], JSON_UNESCAPED_UNICODE),
        ]);

        return redirect()->away($frontend.'/auth/google/callback#'.$fragment);
    }

    /**
     * HMAC-sign the OAuth state so the callback can prove it issued it.
     */
    private function signState(array $payload): string
    {
        $encoded = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');

        return $encoded.'.'.hash_hmac('sha256', $encoded, (string) config('app.key'));
    }

    /**
     * @return array<string, mixed>|null null when the state is invalid/expired.
     */
    private function verifyState(string $state): ?array
    {
        $parts = explode('.', $state);

        if (count($parts) !== 2) {
            return null;
        }

        [$encoded, $signature] = $parts;

        if (! hash_equals(hash_hmac('sha256', $encoded, (string) config('app.key')), $signature)) {
            return null;
        }

        $decoded = json_decode((string) base64_decode(strtr($encoded, '-_', '+/'), true) ?: '', true);

        if (! is_array($decoded)) {
            return null;
        }

        if ((int) ($decoded['exp'] ?? 0) < time()) {
            return null;
        }

        return $decoded;
    }

    /**
     * Only the platform-configured super-admin address may be auto-promoted.
     */
    private function isConfiguredSuperAdminEmail(string $email): bool
    {
        $configured = strtolower(trim((string) config('app.super_admin_email', '')));

        return $configured !== '' && hash_equals($configured, strtolower(trim($email)));
    }
}
