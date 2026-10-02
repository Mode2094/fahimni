<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\PlatformSetting;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\StudentAppService;
use App\Support\AuditLogger;
use App\Support\DevicePolicy;
use App\Support\TOTP;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\Uid\UuidV7;

/**
 * Mobile-scoped student login.
 *
 * Unlike the generic /auth/login, this endpoint REQUIRES a valid teacher
 * code and then verifies — server-side — that the authenticating user is
 * actually an active student member of that teacher's tenant. The student
 * can never pick a teacher/tenant id themselves; only the validated code
 * defines the Teacher Context.
 */
class MobileAuthController extends Controller
{
    private const DUMMY_HASH = '$2y$12$f/Z/lVNLNQPaIqjoadVohe.l8LHBqr0kG3SJ/sr0WKlPf3lXR9sGm';

    public function __construct(
        private readonly StudentAppService $app,
    ) {}

    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'teacher_code' => 'required|string|max:20',
            'email' => 'required|email|max:255',
            'password' => 'required|string|max:255',
        ]);

        // 1. The code defines the context — nothing else does.
        $validation = $this->app->validateTeacherCode((string) $request->input('teacher_code'));

        if (! $validation['ok'] || ! $validation['tenant']) {
            return response()->json([
                'message' => $this->codeErrorMessage($validation['reason']),
                'reason' => $validation['reason'],
            ], $validation['reason'] === 'feature_disabled' ? 503 : 422);
        }

        $tenant = $validation['tenant'];

        // 2. Credential check (timing-safe, mirrors web login).
        $user = User::where('email', strtolower(trim((string) $request->input('email'))))->first();

        if (! $user || ! Hash::check((string) $request->input('password'), $user->password)) {
            Hash::check((string) $request->input('password'), self::DUMMY_HASH);

            return response()->json([
                'message' => 'Invalid credentials.',
                'reason' => 'invalid_credentials',
            ], 401);
        }

        if (! $user->is_active) {
            return response()->json([
                'message' => 'Invalid credentials.',
                'reason' => 'invalid_credentials',
            ], 401);
        }

        // Only students may log in through the mobile app. Staff are
        // identified by their pivot role inside the validated teacher's
        // tenant (the same signal the role: middleware uses) — never by a
        // role that might not exist in this environment.
        $pivot = TenantUser::where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->first();

        if ($pivot && in_array($pivot->role, ['teacher', 'owner', 'admin'], true)) {
            return response()->json([
                'message' => 'This app is for students only.',
                'reason' => 'not_a_student',
            ], 403);
        }

        // 3. 2FA challenge (identical flow to the web login).
        if ($user->two_factor_enabled) {
            $pending = bin2hex(random_bytes(16));
            Cache::put('2fa:'.$pending, $user->id, now()->addMinutes(5));

            return response()->json([
                'requires_2fa' => true,
                'two_factor_token' => $pending,
            ], 200);
        }

        return $this->completeLogin($request, $user, $tenant);
    }

    public function twoFactorConfirm(Request $request): JsonResponse
    {
        $request->validate([
            'two_factor_token' => 'required|string',
            'code' => 'required|string',
        ]);

        $userId = Cache::pull('2fa:'.$request->two_factor_token);
        if (! $userId) {
            return response()->json(['message' => 'Session expired, log in again.'], 422);
        }

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

        // 2FA confirmed — the tenant context must be re-validated (never
        // trusted from the client).
        $validation = $this->app->validateTeacherCode((string) $request->input('teacher_code', ''));

        if (! $validation['ok'] || ! $validation['tenant']) {
            return response()->json([
                'message' => $this->codeErrorMessage($validation['reason']),
                'reason' => $validation['reason'],
            ], $validation['reason'] === 'feature_disabled' ? 503 : 422);
        }

        $user->update(['last_login_at' => now(), 'last_login_ip' => $request->ip()]);

        return $this->completeLogin($request, $user, $validation['tenant']);
    }

    /**
     * Student self-registration inside the teacher context — the same
     * pattern as web student signup (name / email / phone / password), but
     * the tenant comes from the validated teacher code, never the client.
     * The new account is attached to the teacher as an active student.
     */
    public function register(Request $request): JsonResponse
    {
        $request->validate([
            'teacher_code' => 'required|string|max:20',
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'phone' => 'nullable|string|max:20',
            'password' => 'required|string|min:8|confirmed',
        ]);

        if (! (bool) PlatformSetting::get('registration_enabled', true)) {
            return response()->json([
                'message' => 'Registration is currently disabled by the platform administrator.',
                'reason' => 'registration_disabled',
            ], 403);
        }

        $validation = $this->app->validateTeacherCode((string) $request->input('teacher_code'));

        if (! $validation['ok'] || ! $validation['tenant']) {
            return response()->json([
                'message' => $this->codeErrorMessage($validation['reason']),
                'reason' => $validation['reason'],
            ], $validation['reason'] === 'feature_disabled' ? 503 : 422);
        }

        $tenant = $validation['tenant'];
        $email = strtolower(trim((string) $request->input('email')));

        // Same account as the web platform: if the email already exists the
        // student logs in — never silently duplicates.
        $existing = User::where('email', $email)->first();
        if ($existing) {
            return response()->json([
                'message' => 'This email is already registered. Please log in instead.',
                'reason' => 'account_exists',
            ], 422);
        }

        $user = User::create([
            'id' => UuidV7::generate(),
            'name' => (string) $request->input('name'),
            'email' => $email,
            'phone' => $request->input('phone'),
            'password' => Hash::make((string) $request->input('password')),
            'is_active' => true,
            'locale' => 'ar',
        ]);

        TenantUser::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'role' => 'student',
            'status' => 'active',
            'joined_at' => now(),
            'metadata' => [],
        ]);

        AuditLogger::log($request, 'auth.mobile_register', 'user', $user->id, [
            'tenant_id' => $tenant->id,
            'email' => $email,
        ]);

        return $this->completeLogin($request, $user, $tenant);
    }

    /**
     * Google (Gmail) sign-in inside the teacher context — the mobile
     * counterpart of the web OAuth flow. The app sends a Google ID token
     * (native Sign-In), the backend verifies it server-side against Google,
     * then finds-or-creates the account and attaches it to the teacher as a
     * student. The teacher context still comes only from the validated code.
     */
    public function google(Request $request): JsonResponse
    {
        $request->validate([
            'teacher_code' => 'required|string|max:20',
            'id_token' => 'required|string',
            'name' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:20',
        ]);

        $validation = $this->app->validateTeacherCode((string) $request->input('teacher_code'));

        if (! $validation['ok'] || ! $validation['tenant']) {
            return response()->json([
                'message' => $this->codeErrorMessage($validation['reason']),
                'reason' => $validation['reason'],
            ], $validation['reason'] === 'feature_disabled' ? 503 : 422);
        }

        $tenant = $validation['tenant'];

        // Server-side verification of the Google ID token. Never trust the
        // token or any user data from the client.
        try {
            $resp = Http::timeout(10)
                ->get('https://oauth2.googleapis.com/tokeninfo', ['id_token' => (string) $request->input('id_token')]);
        } catch (\Throwable) {
            return response()->json([
                'message' => 'Could not verify Google sign-in. Try again.',
                'reason' => 'google_verify_failed',
            ], 502);
        }

        if (! $resp->ok()) {
            return response()->json([
                'message' => 'Invalid Google sign-in token.',
                'reason' => 'google_verify_failed',
            ], 422);
        }

        $info = $resp->json() ?? [];
        $email = strtolower(trim((string) ($info['email'] ?? '')));

        if ($email === '' || ! ($info['email_verified'] ?? false)) {
            return response()->json([
                'message' => 'This Google account has no verified email.',
                'reason' => 'google_no_email',
            ], 422);
        }

        $user = User::where('email', $email)->first();

        if (! $user) {
            $user = User::create([
                'id' => UuidV7::generate(),
                'name' => $request->input('name') ?: ($info['name'] ?? 'User'),
                'email' => $email,
                'phone' => $request->input('phone'),
                'password' => Hash::make(Str::random(32)),
                'is_active' => true,
                'email_verified_at' => now(),
                'locale' => 'ar',
            ]);
        }

        if (! $user->is_active) {
            return response()->json([
                'message' => 'This account is inactive.',
                'reason' => 'inactive',
            ], 403);
        }

        // Attach (or verify) membership as an active STUDENT of the teacher.
        $pivot = TenantUser::where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->first();

        if ($pivot && in_array($pivot->role, ['teacher', 'owner', 'admin'], true)) {
            return response()->json([
                'message' => 'This app is for students only.',
                'reason' => 'not_a_student',
            ], 403);
        }

        if (! $pivot) {
            TenantUser::create([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'role' => 'student',
                'status' => 'active',
                'joined_at' => now(),
                'metadata' => [],
            ]);
        }

        // Google sign-in must never bypass 2FA: hand back the same challenge
        // as the password login flow.
        if ($user->two_factor_enabled) {
            $pending = bin2hex(random_bytes(16));
            Cache::put('2fa:'.$pending, $user->id, now()->addMinutes(5));

            return response()->json([
                'requires_2fa' => true,
                'two_factor_token' => $pending,
            ], 200);
        }

        AuditLogger::log($request, 'auth.mobile_google', 'user', $user->id, [
            'tenant_id' => $tenant->id,
            'email' => $email,
        ]);

        return $this->completeLogin($request, $user, $tenant);
    }

    /**
     * Server-authoritative "who am I in this teacher context" endpoint.
     * The app always re-validates membership here instead of trusting any
     * locally cached teacher_id.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        $tenantId = $request->header('X-Tenant-Id') ?? $request->input('tenant_id');

        if (! $tenantId) {
            return response()->json(['message' => 'Teacher context missing.'], 400);
        }

        $pivot = TenantUser::where('tenant_id', $tenantId)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->first();

        if (! $pivot || $pivot->role !== 'student') {
            return response()->json([
                'message' => 'You are not enrolled with this teacher.',
                'reason' => 'not_member',
            ], 403);
        }

        $tenant = $pivot->tenant;

        return response()->json([
            'user' => new UserResource($user),
            'teacher' => [
                'id' => $tenant->id,
                'slug' => $tenant->slug,
                'name' => $tenant->name,
            ],
        ]);
    }

    private function completeLogin(Request $request, User $user, $tenant): JsonResponse
    {
        $tenantId = (string) $tenant->id;

        // Membership gate: the student MUST be an active student of this
        // teacher. Same account on web == same account on mobile.
        $pivot = TenantUser::where('tenant_id', $tenantId)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->first();

        if (! $pivot || $pivot->role !== 'student') {
            return response()->json([
                'message' => 'This account is not registered with this teacher. Contact your teacher to join.',
                'reason' => 'not_member',
            ], 403);
        }

        $error = DevicePolicy::register($request, $tenantId, $user);
        if ($error) {
            return response()->json(['message' => $error['message']], $error['status']);
        }

        $user->update(['last_login_at' => now(), 'last_login_ip' => $request->ip()]);

        $metadata = $user->metadata ?? [];
        $metadata['login_count'] = (int) ($metadata['login_count'] ?? 0) + 1;
        $user->update(['metadata' => $metadata]);

        $deviceId = $request->header('X-Device-Id') ?? '';
        $newToken = $user->createToken('mobile-auth-token');
        $accessToken = $newToken->accessToken;
        $accessToken->device_id = $deviceId ?: null;
        $accessToken->save();

        AuditLogger::log($request, 'auth.mobile_login', 'user', $user->id, [
            'tenant_id' => $tenantId,
            'email' => $user->email,
        ]);

        return response()->json([
            'user' => new UserResource($user),
            'teacher' => [
                'id' => $tenantId,
                'slug' => $tenant->slug,
                'name' => $tenant->name,
            ],
            'token' => $newToken->plainTextToken,
        ]);
    }

    private function codeErrorMessage(?string $reason): string
    {
        return match ($reason) {
            'code_not_found' => 'Teacher code not found. Check the code and try again.',
            'teacher_inactive' => 'This teacher account is not active.',
            'feature_disabled' => 'The student app is currently under development.',
            'subscription_inactive' => 'This teacher subscription is inactive.',
            'plan_denied' => 'The student app is not included in this teacher plan.',
            default => 'Teacher code is invalid.',
        };
    }
}
