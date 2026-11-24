<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Mail\PlatformNotification;
use App\Models\PlatformSetting;
use App\Models\TeacherInvitation;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\TenantUser;
use App\Models\User;
use App\Plans\TeacherTrialProgram;
use App\Rules\ValidPhoneNumber;
use App\Services\NewTeacherNotifier;
use App\Services\StudentGamificationService;
use App\Services\TenantSlugService;
use App\Support\AuditLogger;
use App\Support\DevicePolicy;
use App\Support\MailDelivery;
use App\Support\MathCaptcha;
use App\Support\PhoneNumber;
use App\Support\TOTP;
use App\Support\WebSessionAuth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
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
            // Ignore soft-deleted rows: a deleted account keeps its address
            // reserved, and we answer that case explicitly below with a clear,
            // recoverable message instead of a bare "already taken".
            'email' => 'required|email|max:255|unique:users,email,NULL,id,deleted_at,NULL',
            'password' => 'required|string|min:8|confirmed',
            'locale' => 'sometimes|string|in:ar,en',
            'role' => 'sometimes|string|in:Student,Teacher',
            // Teachers must supply a phone; students may leave it empty.
            'phone' => ['required_if:role,Teacher', 'nullable', 'string', 'max:25', new ValidPhoneNumber],
        ]);

        // Normalize the email so accounts can never be registered with
        // case-variants of an existing address (impersonation vectors).
        $email = strtolower(trim($validated['email']));

        // One canonical phone format (E.164). Null for students who omit it.
        $phone = PhoneNumber::toE164($request->input('phone'));

        // A previously deleted account keeps its address reserved. Never
        // silently re-provision it — that would hand a stranger control of a
        // deleted account. Its owner recovers it by proving ownership (sign in,
        // Google, or a password reset).
        if (User::onlyTrashed()->where('email', $email)->exists()) {
            return response()->json([
                'message' => 'هذا البريد مرتبط بحساب محذوف — سجّل الدخول لاستعادة الحساب أو استخدم بريداً آخر.',
                'code' => 'email_previously_deleted',
            ], 422);
        }

        $user = User::create([
            'id' => UuidV7::generate(),
            'name' => $request->name,
            'email' => $email,
            'phone' => $phone,
            'password' => Hash::make($request->password),
            'locale' => $request->locale ?? 'ar',
            'is_active' => true,
        ]);

        $this->issueVerificationToken($user);

        $role = $validated['role'] ?? 'Student';

        // ربط الطالب بمنصة المعلم (tenant) فور تسجيله من الموقع العام
        $tenantId = null;
        $trialGranted = false;
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
            $tenant = app(TenantSlugService::class)->createTenant([
                'id' => UuidV7::generate(),
                'name' => $request->name.' '.__('Platform'),
                'type' => 'tutor',
                'email' => $request->email,
                'owner_id' => $user->id,
            ], $request->name);

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

            // منح التجربة المجانية عبر البرنامج المركزي فقط: يخضع للمفتاح
            // الرئيسي وعداد المقاعد المحدودة. عند الإيقاف أو نفاد المقاعد لا
            // تُنشأ أي اشتراكات، ويبقى الحساب في حالة «بانتظار الدفع» بلا
            // صلاحيات تشغيل حتى يتم التحقق من دفع حقيقي عبر Freemius.
            $trialGranted = app(TeacherTrialProgram::class)
                ->grantTrial($user, $tenant->id, 'register', true) !== null;
        }

        // بريد ترحيبي (منفصل عن بريد التفعيل) — يُرسَل عبر الطابور عبر Resend.
        try {
            $frontend = rtrim((string) (config('app.frontend_url') ?: config('app.url')), '/');
            $isTeacher = $role === 'Teacher';

            if ($isTeacher && $trialGranted) {
                $trialDays = app(TeacherTrialProgram::class)->trialDays();
                $title = 'مرحباً بك في منصة فهمني — منصتك التعليمية جاهزة';
                $body = "أهلاً {$user->name}،\n\nسعداء بانضمامك! أنشأنا لك منصة تعليمية خاصة تلقائياً، وبدأت تجربتك المجانية لمدة {$trialDays} أيام على الخطة النشطة.\n\nمن لوحة التحكم يمكنك إضافة كورساتك وأقسامك ودروسك، وضبط بطاقات التسجيل وعلامتك التجارية وطريقة تحصيل أرباحك.";
                $actionUrl = $frontend.'/teacher/dashboard';
                $actionText = 'افتح لوحة تحكم معلمك';
            } elseif ($isTeacher) {
                $title = 'مرحباً بك في منصة فهمني — منصتك بانتظار الاشتراك';
                $body = "أهلاً {$user->name}،\n\nسعداء بانضمامك! أنشأنا لك منصة تعليمية خاصة تلقائياً.\n\nلتفعيل منصتك والبدء بنشر كورساتك، اختر خطة الاشتراك المناسبة وأكمل الدفع. سيتم تفعيل منصتك تلقائياً بعد تأكيد الدفع.";
                $actionUrl = $frontend.'/pricing';
                $actionText = 'اختر خطة الاشتراك';
            } else {
                $title = 'مرحباً بك في منصة فهمني';
                $body = "أهلاً {$user->name}،\n\nسعداء بانضمامك إلى منصة فهمني!\n\nيمكنك الآن تصفح الكورسات، متابعة تعلّمك من قسم «تعليمي»، وحل الاختبارات.";
                $actionUrl = $frontend.'/my-learning';
                $actionText = 'ابدأ التعلّم';
            }

            Mail::to($user->email)->queue(
                new PlatformNotification(
                    title: $title,
                    body: $body,
                    data: [
                        'action_url' => $actionUrl,
                        'action_text' => $actionText,
                    ],
                )
            );
        } catch (\Throwable $e) {
            Log::warning('Welcome email failed to send', ['user_id' => $user->id, 'error' => $e->getMessage()]);
        }

        // إشعار Super Admin بانضمام معلّم جديد — عبر الطابور وبعد نجاح حفظ
        // التسجيل، مرة واحدة فقط لكل حساب معلّم.
        if ($role === 'Teacher') {
            app(NewTeacherNotifier::class)->announce($user, $tenantId, $trialGranted);
        }

        // Web (cookie session) registration: sign the user in server-side and
        // never hand a bearer token to the browser.
        if ($request->hasSession()) {
            WebSessionAuth::login($request, $user);
            $this->storeTenantContext($request, $tenantId);

            AuditLogger::log($request, 'auth.register', 'user', $user->id, ['email' => $user->email, 'mode' => 'session']);

            return response()->json([
                'user' => new UserResource($user->load('tenants')),
                'tenant_id' => $tenantId,
                'trial_started' => $trialGranted,
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
            'trial_started' => $trialGranted,
        ], 201);
    }

    /**
     * Complete a teacher account created through Google — or an older teacher
     * account that is still missing a phone number. Captures the mandatory
     * name + phone exactly once, then provisions the workspace and the free
     * trial only if they do not already exist.
     *
     * Idempotent by construction: a second call can never create a duplicate
     * account, tenant, membership, or trial. Students/admins are never
     * provisioned a teacher workspace here.
     */
    public function completeProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        $request->validate([
            'name' => 'required|string|max:255',
            'phone' => ['required', 'string', 'max:25', new ValidPhoneNumber],
        ]);

        $phone = PhoneNumber::toE164((string) $request->input('phone'));

        if ($phone === null) {
            return response()->json(['message' => 'رقم هاتف غير صالح.'], 422);
        }

        $name = trim((string) $request->input('name'));
        $user->update(['name' => $name, 'phone' => $phone]);

        $tenantId = $user->tenantPivots()->first()?->tenant_id;
        $trialStarted = false;

        // A teacher who never got a workspace (Google sign-up without a phone)
        // gets it now — exactly once. Existing teachers keep everything.
        if ($user->hasRole('Teacher') && ! $tenantId) {
            $tenant = DB::transaction(function () use ($user, $name) {
                $tenant = app(TenantSlugService::class)->createTenant([
                    'id' => UuidV7::generate(),
                    'name' => $name.' '.__('Platform'),
                    'type' => 'tutor',
                    'email' => $user->email,
                    'owner_id' => $user->id,
                ], $name);

                TenantSetting::create([
                    'id' => UuidV7::generate(),
                    'tenant_id' => $tenant->id,
                    'academy_name' => $name,
                ]);

                TenantUser::create([
                    'tenant_id' => $tenant->id,
                    'user_id' => $user->id,
                    'role' => 'teacher',
                    'status' => 'active',
                    'joined_at' => now(),
                ]);

                return $tenant;
            });

            $tenantId = $tenant->id;

            // The central trial program governs the free period: the master
            // switch and the seat counter are respected, exactly like
            // register/login. Only granted once — there is no tenant before.
            $trialStarted = app(TeacherTrialProgram::class)
                ->grantTrial($user, $tenant->id, 'google', true) !== null;

            // A teacher workspace is created here for the first time (Google
            // sign-up completed later): tell the Super Admin exactly once.
            app(NewTeacherNotifier::class)->announce($user, $tenantId, $trialStarted);
        }

        AuditLogger::log($request, 'auth.profile_completed', 'user', $user->id, [
            'tenant_id' => $tenantId,
            'trial_started' => $trialStarted,
        ]);

        return response()->json([
            'user' => new UserResource($user->fresh('tenants')),
            'tenant_id' => $tenantId,
            'trial_started' => $trialStarted,
        ]);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        if ((bool) PlatformSetting::get('captcha_enabled', false)) {
            if (! MathCaptcha::verify((string) $request->input('captcha_id', ''), (string) $request->input('captcha_answer', ''))) {
                return response()->json(['message' => 'Invalid captcha answer.'], 422);
            }
        }

        // Include soft-deleted rows: the email is still reserved by the unique
        // index, so a plain lookup would miss the account and any re-create
        // would collide.
        $user = User::findByEmail((string) $request->email);

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

        // A correct password proves ownership: bring a soft-deleted account
        // back instead of dead-ending on a reserved-but-hidden address.
        if ($user->trashed()) {
            $user->restoreWithWorkspaces();
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
                    'tenant_id' => $this->resolveStudentTenantId($request, $user),
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
            WebSessionAuth::login($request, $user);
            $this->storeTenantContext($request, $tenantId);

            AuditLogger::log($request, 'auth.login', 'user', $user->id, ['email' => $user->email, 'mode' => 'session']);

            return response()->json([
                'user' => new UserResource($user->load('tenants')),
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
            'user' => new UserResource($user->load('tenants')),
            'token' => $newToken->plainTextToken,
        ]);
    }

    /**
     * Shared post-authentication side effects (last login, device policy,
     * daily reward). Returns the resolved tenant id for students, or null.
     */
    private function applyLoginSideEffects(Request $request, User $user, ?string $preferredTenantId = null): ?string
    {
        $user->update(['last_login_at' => now(), 'last_login_ip' => $request->ip()]);

        $tenantId = $this->resolveStudentTenantId($request, $user, $preferredTenantId);

        if ($tenantId) {
            $error = DevicePolicy::register($request, $tenantId, $user);
            if ($error) {
                throw new \Symfony\Component\HttpKernel\Exception\HttpException($error['status'], $error['message']);
            }

            $this->recordLoginReward($user, $tenantId);
        }

        return $tenantId;
    }

    private function storeTenantContext(Request $request, ?string $tenantId): void
    {
        if (! $request->hasSession()) {
            return;
        }

        if ($tenantId) {
            $request->session()->put('current_tenant_id', $tenantId);
        } else {
            $request->session()->forget('current_tenant_id');
        }
    }

    /**
     * Resolve student login context only from an active membership. A tenant
     * slug from a teacher's registration/login link takes precedence, while a
     * missing selection is inferred only when the student has exactly one
     * active workspace. Client-supplied IDs are never trusted on their own.
     */
    private function resolveStudentTenantId(
        Request $request,
        User $user,
        ?string $preferredTenantId = null,
    ): ?string {
        if ($user->is_super_admin || $user->getRoleNames()->contains('Teacher')) {
            return null;
        }

        if ($preferredTenantId !== null) {
            return $this->hasActiveTenantMembership($user, $preferredTenantId)
                ? $preferredTenantId
                : null;
        }

        if ($request->filled('tenant_slug')) {
            $tenant = Tenant::where('slug', (string) $request->input('tenant_slug'))
                ->where('status', 'active')
                ->first();

            if (! $tenant) {
                return null;
            }

            return $this->hasActiveTenantMembership($user, (string) $tenant->id)
                ? (string) $tenant->id
                : null;
        }

        $requestedTenantId = $request->header('X-Tenant-Id')
            ?? $request->input('tenant_id');

        if ($requestedTenantId) {
            $requestedTenantId = (string) $requestedTenantId;

            return $this->hasActiveTenantMembership($user, $requestedTenantId)
                ? $requestedTenantId
                : null;
        }

        $tenants = $user->tenants()
            ->wherePivot('status', 'active')
            ->limit(2)
            ->get(['tenants.id']);

        return $tenants->count() === 1 ? (string) $tenants->first()->id : null;
    }

    private function hasActiveTenantMembership(User $user, string $tenantId): bool
    {
        return $user->tenantPivots()
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->exists();
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

        $preferredTenantId = is_array($sessionPending)
            ? ($sessionPending['tenant_id'] ?? null)
            : null;
        $tenantId = $this->applyLoginSideEffects($request, $user, is_string($preferredTenantId) ? $preferredTenantId : null);

        if (is_array($sessionPending)) {
            $request->session()->forget('auth.2fa_pending');
            WebSessionAuth::login($request, $user);
            $this->storeTenantContext($request, $tenantId);

            AuditLogger::log($request, 'auth.login_2fa', 'user', $user->id, ['mode' => 'session']);

            return response()->json([
                'user' => new UserResource($user->load('tenants')),
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
            'user' => new UserResource($user->load('tenants')),
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
            'view_as' => $this->viewAsState($request),
        ]);
    }

    /**
     * The admin view-as banner state, read from the server-side session so it
     * survives a reload and cannot be spoofed from the client.
     */
    private function viewAsState(Request $request): ?array
    {
        if (! $request->hasSession()) {
            return null;
        }

        $state = $request->session()->get('view_as');

        if (! is_array($state) || empty($state['user_id'])) {
            return null;
        }

        return [
            'active' => true,
            'read_only' => true,
            'actor_id' => $state['actor_id'] ?? null,
            'actor_name' => $state['actor_name'] ?? null,
            'user_id' => $state['user_id'],
            'started_at' => $state['started_at'] ?? null,
        ];
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
        // A reset link is delivered to the account's own inbox (proving
        // ownership), so a soft-deleted account is restored first — otherwise
        // the password broker cannot see its reserved-but-hidden email.
        User::findByEmail((string) $request->email)?->restoreWithWorkspaces();

        try {
            $status = Password::sendResetLink($request->only('email'));

            if ($status !== Password::RESET_LINK_SENT) {
                Log::warning('Password reset link not sent', ['status' => $status, 'email_hmac' => $this->redactEmail($request->email)]);
            }
        } catch (\Throwable $e) {
            Log::warning('Password reset link send failed', ['email_hmac' => $this->redactEmail($request->email), 'error' => $e->getMessage()]);
        }

        // `delivery` is derived ONLY from mailer configuration — never from
        // whether the account exists — so the UI can be honest about whether a
        // message could actually be delivered without enabling enumeration.
        return response()->json([
            'message' => __('passwords.sent'),
            'delivery' => MailDelivery::isConfigured() ? 'email' : 'unconfigured',
        ]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'token' => 'required',
            'password' => 'required|min:8|confirmed',
        ]);

        $loginPath = null;

        // The emailed token proves ownership of the address: restore a
        // soft-deleted account so the broker can complete the reset.
        User::findByEmail((string) $request->email)?->restoreWithWorkspaces();

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) use (&$loginPath) {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();

                // A reset means the credential may have been compromised:
                // revoke every bearer token and every web session so stolen
                // credentials die instantly (EnsureAccountActive compares the
                // session marker against the bumped version).
                $user->increment('auth_version');
                $user->tokens()->delete();

                $loginPath = $user->loginPath();
            }
        );

        return $status === Password::PASSWORD_RESET
            ? response()->json(['message' => __($status), 'login_path' => $loginPath])
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
