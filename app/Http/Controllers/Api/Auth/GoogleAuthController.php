<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\PlatformSetting;
use App\Models\SubscriptionPlan;
use App\Models\TeacherInvitation;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\TenantSubscription;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\TenantSlugService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Spatie\Permission\Models\Role;
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

        // Fail fast (and honestly) when OAuth is not configured: Socialite
        // happily builds a URL with an empty client_id, and Google then shows
        // a raw "invalid_request / missing client_id" page. A clear message
        // lets the UI keep the button hidden instead of dead-ending users.
        if (trim((string) config('services.google.client_id')) === ''
            || trim((string) config('services.google.client_secret')) === '') {
            Log::warning('google.redirect_not_configured');

            return response()->json(['message' => 'Google sign-in is not available yet.'], 503);
        }

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
            return $this->redirectToFrontend($frontend, null, ['error' => 'invalid_state']);
        }

        $role = in_array($state['role'] ?? '', ['teacher', 'student', 'admin'], true)
            ? (string) $state['role']
            : 'teacher';
        $regData = [
            'name' => $state['name'] ?? null,
            'phone' => $state['phone'] ?? null,
        ];

        // Staff (teacher/admin) identities own a workspace tenant.
        $wantsStaffTenant = in_array($role, ['teacher', 'admin'], true);

        try {
            $google = Socialite::driver('google')->stateless()->user();
        } catch (\Throwable $e) {
            Log::error('google.callback_failed', ['error' => $e->getMessage()]);

            return $this->redirectToFrontend($frontend, $role, ['error' => 'google_failed']);
        }

        $email = strtolower(trim((string) $google->getEmail()));
        $name = $regData['name'] ?? $google->getName() ?? $google->getNickname() ?? 'User';

        if ($email === '') {
            return $this->redirectToFrontend($frontend, $role, ['error' => 'no_email']);
        }

        $user = User::where('email', $email)->first();
        $existingTenant = $user ? $user->tenantPivots()->first() : null;

        // ── Gates. Decided BEFORE any write so a rejected attempt never
        //    leaves a half-created account behind. ──────────────────────────
        //
        // Admin accounts are never self-provisioned through OAuth: only the
        // configured platform super-admin email may be promoted.
        if (! $user && $role === 'admin' && ! $this->isConfiguredSuperAdminEmail($email)) {
            return $this->redirectToFrontend($frontend, $role, ['error' => 'not_authorized']);
        }

        // Provisioning a *new* teacher workspace (new identity, or an existing
        // non-teacher account) is subject to the same teacher-registration
        // control as email/password signup — Google must not be a bypass.
        if ($wantsStaffTenant
            && ! $existingTenant
            && $role === 'teacher'
            && ! ($user && $user->hasRole('Teacher'))
            && ! $this->teacherRegistrationAllowed($email)) {
            return $this->redirectToFrontend($frontend, $role, ['error' => 'teacher_registration_disabled']);
        }

        if ($user && ! $user->is_active) {
            return $this->redirectToFrontend($frontend, $role, ['error' => 'inactive']);
        }

        // Google login must never bypass an enabled second factor.
        if ($user && $user->two_factor_enabled) {
            if ($request->hasSession()) {
                // Web session flow: the pending challenge stays server-side.
                $request->session()->put('auth.2fa_pending', [
                    'user_id' => $user->id,
                    'expires_at' => now()->addMinutes(5)->getTimestamp(),
                ]);

                return $this->redirectToFrontend($frontend, $role, ['step' => '2fa']);
            }

            $pending = bin2hex(random_bytes(16));
            Cache::put('2fa:'.$pending, $user->id, now()->addMinutes(5));

            return redirect()->away($frontend.'/auth/google/callback#requires_2fa=1&two_factor_token='.$pending);
        }

        // ── Create the account and its workspace atomically. If anything
        //    fails the whole thing rolls back: no orphan user without a
        //    tenant, no tenant without an owner membership. ────────────────
        try {
            [$user, $tenant] = DB::transaction(function () use ($email, $name, $regData, $role, $wantsStaffTenant) {
                // Re-read inside the transaction and take a row lock so
                // concurrent callbacks converge on one row.
                $user = User::where('email', $email)->lockForUpdate()->first();

                if (! $user) {
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
                    // Never mutate credentials from OAuth profile hints — only
                    // the name/phone submitted from the signup form.
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
                }

                return [$user, $this->ensureWorkspaceTenant($user, $name, $email, $wantsStaffTenant)];
            });
        } catch (QueryException $e) {
            // A concurrent callback won the race: the unique index rejected the
            // duplicate and the transaction rolled back cleanly. Re-read the
            // winner's rows and continue signing this user in.
            if (! $this->isDuplicateKey($e)) {
                throw $e;
            }

            $user = User::where('email', $email)->first();

            if (! $user) {
                Log::warning('google.callback_unique_collision');

                return $this->redirectToFrontend($frontend, $role, ['error' => 'retry']);
            }

            $tenant = $wantsStaffTenant ? Tenant::where('owner_id', $user->id)->first() : null;
        }

        // Web browser flow: sign in with an HttpOnly session cookie — the
        // Sanctum token never leaves the server. Mobile keeps using the
        // bearer-token flow of /api/v1/mobile/auth/google.
        if ($request->hasSession()) {
            Auth::guard('web')->login($user);
            $request->session()->regenerate();

            return $this->redirectToFrontend($frontend, $role, ['step' => 'done']);
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
     * Resolve the tenant this user should land on, provisioning the teacher
     * workspace on first use. MUST be called inside the caller's transaction:
     * Tenant + TenantSetting + TenantUser + trial all commit together, so a
     * failure can never leave a tenant without its owner membership.
     *
     * Column names follow the real schema (tenants.owner_id / tenants.email /
     * tenants.status) — NOT owner_user_id / is_active, which do not exist.
     */
    private function ensureWorkspaceTenant(User $user, string $name, string $email, bool $wantsStaffTenant): ?Tenant
    {
        // Already a member of some tenant → that is the user's workspace.
        $membership = $user->tenantPivots()->first();

        if ($membership) {
            return Tenant::find($membership->tenant_id);
        }

        // Students never get an owner tenant from an OAuth login.
        if (! $wantsStaffTenant) {
            return null;
        }

        $tenant = app(TenantSlugService::class)->createTenant([
            'id' => UuidV7::generate(),
            'name' => $name.' '.__('Platform'),
            'type' => 'tutor',
            'email' => $email,
            'owner_id' => $user->id,
            'status' => 'active',
        ], $name);

        TenantSetting::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenant->id,
            'academy_name' => $name,
        ]);

        TenantUser::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        // The dashboard/API authorize staff by the Spatie role, and the web UI
        // routes by `roles.includes('Teacher')`. Without this the teacher
        // lands on the student area instead of /teacher/dashboard.
        if (! $user->hasRole('Teacher')) {
            $user->assignRole(Role::findOrCreate('Teacher', 'web'));
        }

        // Registration = immediate 7-day trial on the cheapest active plan,
        // so the new teacher is not instantly blocked by the subscription
        // gate. Mirrors AuthController::register().
        $trialPlan = SubscriptionPlan::where('is_active', true)->orderBy('monthly_price')->first();

        if ($trialPlan) {
            TenantSubscription::create([
                'id' => UuidV7::generate(),
                'tenant_id' => $tenant->id,
                'plan_id' => $trialPlan->id,
                'status' => 'trialing',
                'billing_cycle' => 'monthly',
                'starts_at' => now(),
                'trial_ends_at' => now()->addDays(max(1, (int) $trialPlan->trial_days)),
                'auto_renew' => true,
            ]);
        }

        return $tenant;
    }

    /**
     * Teacher self-registration mirrors the email/password path: open when the
     * platform enables it, or when the address holds a pending invitation.
     */
    private function teacherRegistrationAllowed(string $email): bool
    {
        if ((bool) PlatformSetting::get('teacher_registration_enabled', false)) {
            return true;
        }

        return TeacherInvitation::where('email', strtolower(trim($email)))
            ->where('status', TeacherInvitation::STATUS_PENDING)
            ->where('expires_at', '>', now())
            ->exists();
    }

    /** Postgres unique_violation (23505) or SQLite's UNIQUE constraint error. */
    private function isDuplicateKey(QueryException $e): bool
    {
        return ($e->errorInfo[0] ?? null) === '23505'
            || str_contains(strtolower($e->getMessage()), 'unique');
    }

    /**
     * Redirect the browser to the frontend OAuth callback with non-sensitive
     * step markers only. `role` is a UI hint (which login page to return to),
     * never a grant — the session the callback just established decides access.
     */
    private function redirectToFrontend(string $frontend, ?string $role, array $params): RedirectResponse
    {
        $query = array_filter(
            array_merge(['role' => $role], $params),
            static fn ($value) => $value !== null && $value !== '',
        );

        return redirect()->away($frontend.'/auth/google/callback?'.http_build_query($query));
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
