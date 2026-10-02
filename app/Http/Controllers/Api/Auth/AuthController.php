<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Mail\PlatformNotification;
use App\Models\PlatformSetting;
use App\Models\SubscriptionPlan;
use App\Models\TeacherInvitation;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\TenantSubscription;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\StudentGamificationService;
use App\Support\AuditLogger;
use App\Support\DevicePolicy;
use App\Support\MathCaptcha;
use App\Support\TOTP;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Symfony\Component\Uid\UuidV7;

class AuthController extends Controller
{
    private const DUMMY_HASH = '$2y$12$f/Z/lVNLNQPaIqjoadVohe.l8LHBqr0kG3SJ/sr0WKlPf3lXR9sGm';

    public function register(RegisterRequest $request): JsonResponse
    {
        if (! (bool) PlatformSetting::get('registration_enabled', true)) {
            return response()->json([
                'message' => 'Registration is currently disabled by the platform administrator.',
            ], 403);
        }

        if ((bool) PlatformSetting::get('captcha_enabled', false)) {
            if (! MathCaptcha::verify((string) $request->input('captcha_id', ''), (string) $request->input('captcha_answer', ''))) {
                return response()->json(['message' => 'Invalid captcha answer.'], 422);
            }
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
            'locale' => 'sometimes|string|in:ar,en',
            'role' => 'sometimes|string|in:Student,Teacher',
            'phone' => 'sometimes|nullable|string|max:20',
        ]);

        // Normalize the email so accounts can never be registered with
        // case-variants of an existing address (impersonation vectors).
        $email = strtolower(trim($validated['email']));

        $user = User::create([
            'id' => UuidV7::generate(),
            'name' => $request->name,
            'email' => $email,
            'phone' => $request->input('phone'),
            'password' => Hash::make($request->password),
            'locale' => $request->locale ?? 'ar',
            'is_active' => true,
        ]);

        $this->issueVerificationToken($user);

        $role = $validated['role'] ?? 'Student';

        // ربط الطالب بمنصة المعلم (tenant) فور تسجيله من الموقع العام
        $tenantId = null;
        if ($request->filled('tenant_slug')) {
            $tenant = Tenant::where('slug', $request->input('tenant_slug'))
                ->where('status', 'active')
                ->first();

            if (! $tenant) {
                return response()->json(['message' => 'Teacher platform not found.'], 404);
            }

            $existing = TenantUser::where('tenant_id', $tenant->id)
                ->where('user_id', $user->id)
                ->exists();

            if (! $existing) {
                TenantUser::create([
                    'tenant_id' => $tenant->id,
                    'user_id' => $user->id,
                    'role' => 'student',
                    'status' => 'active',
                    'joined_at' => now(),
                ]);
            }

            $tenantId = $tenant->id;
        }

        if ($role === 'Teacher') {
            $registrationEnabled = (bool) PlatformSetting::get('teacher_registration_enabled', false);
            $hasPendingInvitation = TeacherInvitation::where('email', strtolower($request->email))
                ->where('status', TeacherInvitation::STATUS_PENDING)
                ->where('expires_at', '>', now())
                ->exists();

            if (! $registrationEnabled && ! $hasPendingInvitation) {
                return response()->json([
                    'message' => 'Teacher registration requires an invitation. Please accept the invitation link sent to you, or contact the platform administrator.',
                ], 403);
            }

            $user->assignRole('Teacher');

            // إنشاء منصة المعلم الافتراضية (tenant) تلقائياً
            $tenant = Tenant::create([
                'id' => UuidV7::generate(),
                'name' => $request->name.' '.__('Platform'),
                'type' => 'tutor',
                'slug' => Str::slug($request->name).'-'.Str::random(4),
                'email' => $request->email,
                'owner_id' => $user->id,
            ]);

            TenantSetting::create([
                'id' => UuidV7::generate(),
                'tenant_id' => $tenant->id,
                'academy_name' => $request->name,
            ]);

            TenantUser::create([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'role' => 'teacher',
                'status' => 'active',
            ]);

            // The frontend needs the tenant id right after registration to
            // attach it to every API request.
            $tenantId = $tenant->id;

            // التسجيل = تجربة مجانية فورية (7 أيام) بأرخص خطة نشطة —
            // بدونها يُحظر حساب المعلم الجديد فورًا بواسطة بوابة الاشتراك.
            $trialPlan = SubscriptionPlan::where('is_active', true)
                ->orderBy('monthly_price')
                ->first();

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
        }

        // Web (cookie session) registration: sign the user in server-side and
        // never hand a bearer token to the browser.
        if ($request->hasSession()) {
            Auth::guard('web')->login($user);
            $request->session()->regenerate();

            AuditLogger::log($request, 'auth.register', 'user', $user->id, ['email' => $user->email, 'mode' => 'session']);

            return response()->json([
                'user' => new UserResource($user->load('tenants')),
                'tenant_id' => $tenantId,
            ], 201);
        }

        $deviceId = $request->header('X-Device-Id') ?? '';
        $newToken = $user->createToken('auth-token');
        $accessToken = $newToken->accessToken;
        $accessToken->device_id = $deviceId ?: null;
        $accessToken->save();

        AuditLogger::log($request, 'auth.register', 'user', $user->id, ['email' => $user->email]);

        return response()->json([
            'user' => new UserResource($user->load('tenants')),
            'token' => $newToken->plainTextToken,
            'tenant_id' => $tenantId,
        ], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        if ((bool) PlatformSetting::get('captcha_enabled', false)) {
            if (! MathCaptcha::verify((string) $request->input('captcha_id', ''), (string) $request->input('captcha_answer', ''))) {
                return response()->json(['message' => 'Invalid captcha answer.'], 422);
            }
        }

        $user = User::where('email', strtolower(trim($request->email)))->first();

        // Timing-safe login: unknown emails run a dummy hash comparison so
        // account existence cannot be inferred from response latency.
        if (! $user || ! Hash::check($request->password, $user->password)) {
            // Dummy bcrypt hash (generated once) so unknown-email attempts
            // take the same time as real ones without leaking existence.
            Hash::check($request->password, self::DUMMY_HASH);

            return response()->json([
                'message' => __('auth.failed'),
            ], 401);
        }

        if (! $user->is_active) {
            return response()->json([
                'message' => __('auth.failed'),
            ], 401);
        }

        $sessionMode = $request->hasSession();

        // 2FA challenge
        if ($user->two_factor_enabled) {
            if ($sessionMode) {
                // Web (cookie) sessions keep the challenge context server-side:
                // no pending credential ever reaches the browser.
                $request->session()->put('auth.2fa_pending', [
                    'user_id' => $user->id,
                    'expires_at' => now()->addMinutes(5)->getTimestamp(),
                ]);

                AuditLogger::log($request, 'auth.2fa_challenge', 'user', $user->id);

                return response()->json(['requires_2fa' => true]);
            }

            $pending = bin2hex(random_bytes(16));
            Cache::put('2fa:'.$pending, $user->id, now()->addMinutes(5));

            return response()->json([
                'requires_2fa' => true,
                'two_factor_token' => $pending,
            ], 200);
        }

        $tenantId = $this->applyLoginSideEffects($request, $user);

        if ($sessionMode) {
            Auth::guard('web')->login($user);
            $request->session()->regenerate();

            AuditLogger::log($request, 'auth.login', 'user', $user->id, ['email' => $user->email, 'mode' => 'session']);

            return response()->json([
                'user' => new UserResource($user),
                'tenant_id' => $tenantId,
            ]);
        }

        $deviceId = $request->header('X-Device-Id') ?? '';
        $newToken = $user->createToken('auth-token');
        $accessToken = $newToken->accessToken;
        $accessToken->device_id = $deviceId ?: null;
        $accessToken->save();

        AuditLogger::log($request, 'auth.login', 'user', $user->id, ['email' => $user->email]);

        return response()->json([
            'user' => new UserResource($user),
            'token' => $newToken->plainTextToken,
        ]);
    }

    /**
     * Shared post-authentication side effects (last login, device policy,
     * daily reward). Returns the resolved tenant id for students, or null.
     */
    private function applyLoginSideEffects(Request $request, User $user): ?string
    {
        $user->update(['last_login_at' => now(), 'last_login_ip' => $request->ip()]);

        $roles = $user->getRoleNames();
        $isStudent = ! $user->is_super_admin && ! $roles->contains('Teacher');

        $tenantId = null;

        if ($isStudent) {
            $tenantId = $request->header('X-Tenant-Id') ?? $request->input('tenant_id');
            if (! $tenantId) {
                $tenantId = $user->tenants()->wherePivot('status', 'active')->first()?->id;
            }
            if ($tenantId) {
                $error = DevicePolicy::register($request, $tenantId, $user);
                if ($error) {
                    throw new \Symfony\Component\HttpKernel\Exception\HttpException($error['status'], $error['message']);
                }

                $this->recordLoginReward($user, $tenantId);
            }
        }

        return $tenantId;
    }

    public function twoFactorConfirm(Request $request): JsonResponse
    {
        $sessionMode = $request->hasSession();
        $sessionPending = $sessionMode ? $request->session()->get('auth.2fa_pending') : null;

        if (is_array($sessionPending)) {
            // Web session flow: the pending user id lives in the session.
            $userId = $sessionPending['user_id'] ?? null;
            $expiresAt = (int) ($sessionPending['expires_at'] ?? 0);

            if (! $userId || $expiresAt < now()->getTimestamp()) {
                $request->session()->forget('auth.2fa_pending');

                return response()->json(['message' => 'Session expired, log in again.'], 422);
            }
        } else {
            $request->validate([
                'two_factor_token' => 'required|string',
                'code' => 'required|string',
            ]);

            $userId = Cache::pull('2fa:'.$request->two_factor_token);
            if (! $userId) {
                return response()->json(['message' => 'Session expired, log in again.'], 422);
            }
        }

        $request->validate(['code' => 'required|string']);

        // Per-account brute-force lockout: 5 failed codes per 15 minutes
        // regardless of how many login sessions the attacker rotates.
        $failKey = '2fa_failures:'.$userId;
        $failures = (int) Cache::get($failKey, 0);

        if ($failures >= 5) {
            return response()->json(['message' => 'Too many failed attempts. Try again later.'], 429);
        }

        $user = User::find($userId);
        if (! $user || ! $user->two_factor_enabled || ! $user->two_factor_secret) {
            return response()->json(['message' => 'Invalid state.'], 422);
        }

        try {
            $secret = Crypt::decryptString($user->two_factor_secret);
        } catch (\Throwable) {
            return response()->json(['message' => '2FA configuration error. Contact support.'], 422);
        }

        if (! TOTP::verify($secret, $request->code)) {
            Cache::put($failKey, $failures + 1, now()->addMinutes(15));
            AuditLogger::log($request, 'auth.2fa_failed', 'user', $user->id);

            return response()->json(['message' => 'Invalid 2FA code.'], 422);
        }

        Cache::forget($failKey);

        $tenantId = $this->applyLoginSideEffects($request, $user);

        if (is_array($sessionPending)) {
            $request->session()->forget('auth.2fa_pending');
            Auth::guard('web')->login($user);
            $request->session()->regenerate();

            AuditLogger::log($request, 'auth.login_2fa', 'user', $user->id, ['mode' => 'session']);

            return response()->json([
                'user' => new UserResource($user),
                'tenant_id' => $tenantId,
            ]);
        }

        $deviceId = $request->header('X-Device-Id') ?? '';
        $newToken = $user->createToken('auth-token');
        $accessToken = $newToken->accessToken;
        $accessToken->device_id = $deviceId ?: null;
        $accessToken->save();

        AuditLogger::log($request, 'auth.login_2fa', 'user', $user->id);

        return response()->json([
            'user' => new UserResource($user),
            'token' => $newToken->plainTextToken,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        AuditLogger::log($request, 'auth.logout', 'user', $request->user()?->id);

        // Web cookie session: destroy the server-side session entirely.
        if ($request->hasSession()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return response()->json(['message' => __('auth.logged_out')]);
        }

        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => __('auth.logged_out')]);
    }

    /**
     * Daily login reward: +5 points once per day per tenant, plus activity log.
     */
    private function recordLoginReward(User $user, string $tenantId): void
    {
        $metadata = $user->metadata ?? [];
        $metadata['login_count'] = (int) ($metadata['login_count'] ?? 0) + 1;
        $user->update(['metadata' => $metadata]);

        $today = now()->toDateString();

        if (($metadata['last_login_reward_date'] ?? null) === $today) {
            return;
        }

        $metadata['last_login_reward_date'] = $today;
        $user->update(['metadata' => $metadata]);

        app(StudentGamificationService::class)->award(
            $user,
            $tenantId,
            StudentGamificationService::POINTS_LOGIN,
            'daily_login',
            'login',
            $today,
            'تسجيل دخول يومي +'.StudentGamificationService::POINTS_LOGIN
        );
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load(['tenants']);

        return response()->json([
            'user' => new UserResource($user),
        ]);
    }

    /**
     * Verify the email with a signed token from the emailed link.
     * Token is single-use and expires after 24h.
     */
    public function verifyEmail(Request $request): JsonResponse
    {
        $request->validate(['token' => 'required|string|max:64']);

        $user = $request->user();

        if ($user->email_verified_at) {
            return response()->json(['message' => 'Email already verified.', 'verified' => true]);
        }

        $stored = $user->email_verify_token;
        $expiresAt = $user->email_verify_token_expires_at;

        if (! $stored || ! hash_equals($stored, $request->token)) {
            return response()->json(['message' => 'Invalid verification link.'], 422);
        }

        if ($expiresAt && now()->gt($expiresAt)) {
            return response()->json(['message' => 'Verification link expired. Request a new one.'], 422);
        }

        $user->forceFill([
            'email_verified_at' => now(),
            'email_verify_token' => null,
            'email_verify_token_expires_at' => null,
        ])->save();

        AuditLogger::log($request, 'auth.email_verified', 'user', $user->id);

        return response()->json(['message' => 'Email verified successfully.', 'verified' => true]);
    }

    /**
     * Re-send the verification email (rate-limited route).
     */
    public function resendVerification(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->email_verified_at) {
            return response()->json(['message' => 'Email already verified.'], 422);
        }

        $this->issueVerificationToken($user);

        return response()->json(['message' => 'Verification email sent.']);
    }

    private function issueVerificationToken(User $user): void
    {
        if ($user->email_verified_at) {
            return;
        }

        $token = Str::random(64);

        $user->forceFill([
            'email_verify_token' => hash('sha256', $token),
            'email_verify_token_expires_at' => now()->addHours(24),
        ])->save();

        try {
            $frontend = rtrim(config('app.frontend_url') ?: config('app.url'), '/');
            $verifyUrl = $frontend.'/verify-email/'.$token;

            Mail::to($user->email)->queue(
                new PlatformNotification(
                    title: 'تفعيل البريد الإلكتروني',
                    body: "أهلاً {$user->name}، فعّل بريدك الإلكتروني بالضغط على الرابط التالي:",
                    data: ['action_url' => $verifyUrl, 'action_text' => 'تفعيل البريد'],
                )
            );
        } catch (\Throwable $e) {
            Log::warning('Verification email failed to send', ['user_id' => $user->id, 'error' => $e->getMessage()]);
        }
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => 'required|email']);

        // Uniform response: never reveal whether an account exists — a mailer
        // failure must not surface as a distinct status (500 for existing email
        // vs 200 for unknown would leak account existence).
        try {
            $status = Password::sendResetLink($request->only('email'));

            if ($status !== Password::RESET_LINK_SENT) {
                Log::warning('Password reset link not sent', ['status' => $status, 'email_hmac' => $this->redactEmail($request->email)]);
            }
        } catch (\Throwable $e) {
            Log::warning('Password reset link send failed', ['email_hmac' => $this->redactEmail($request->email), 'error' => $e->getMessage()]);
        }

        return response()->json(['message' => __('passwords.sent')]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'token' => 'required',
            'password' => 'required|min:8|confirmed',
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();

                // A reset means the credential may have been compromised:
                // revoke every bearer token so stolen sessions die instantly.
                $user->tokens()->delete();
            }
        );

        return $status === Password::PASSWORD_RESET
            ? response()->json(['message' => __($status)])
            : response()->json(['message' => __($status)], 400);
    }

    /**
     * Never log a plaintext email — only a hash for correlation.
     */
    private function redactEmail(?string $email): string
    {
        return hash('sha256', strtolower((string) $email));
    }
}
