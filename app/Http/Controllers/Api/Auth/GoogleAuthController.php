<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\PlatformSetting;
use App\Models\TeacherInvitation;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\TenantUser;
use App\Models\User;
use App\Plans\TeacherTrialProgram;
use App\Services\NewTeacherNotifier;
use App\Services\TenantDomainResolver;
use App\Services\TenantSlugService;
use App\Support\GoogleHandoff;
use App\Support\PhoneNumber;
use App\Support\WebSessionAuth;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        $rawTenantSlug = $request->query('tenant_slug');
        $tenantSlug = $role === 'student' && is_string($rawTenantSlug)
            ? Str::limit(trim($rawTenantSlug), 255, '')
            : null;
        $rawRedirectTo = $request->query('redirect_to');
        $redirectTo = $role === 'student' && is_string($rawRedirectTo)
            ? $this->safeInternalRedirect($rawRedirectTo)
            : null;

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

        // A custom-domain sign-in must hand the session back to the teacher's
        // OWN host. The destination host comes from the request Origin (the BFF
        // calls us server-to-server on behalf of that host) and is only trusted
        // after the domain resolver confirms it is a live tenant address; a
        // platform host keeps the existing single-host flow.
        $handoffHost = $this->handoffHost($request);
        $bindingHash = null;

        if ($handoffHost !== null) {
            $rawBinding = $request->query('binding');
            $bindingHash = is_string($rawBinding) && strlen($rawBinding) >= 16 && strlen($rawBinding) <= 128
                ? GoogleHandoff::bindingHash($rawBinding)
                : null;

            // Without a browser binding the ticket could be fixed onto another
            // session (login CSRF): refuse rather than downgrade.
            if ($bindingHash === null) {
                return response()->json(['message' => 'Invalid OAuth handoff request.'], 422);
            }
        }

        // Signed, expiring OAuth state: the callback refuses any state it did
        // not issue, so `role`/registration hints cannot be tampered with.
        $state = $this->signState([
            'role' => $role,
            'name' => $name !== '' ? Str::limit($name, 120, '') : null,
            'phone' => $phone !== '' ? Str::limit($phone, 40, '') : null,
            'tenant_slug' => $tenantSlug,
            'redirect_to' => $redirectTo,
            'handoff_host' => $handoffHost,
            'bh' => $bindingHash,
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
            'tenant_slug' => is_string($state['tenant_slug'] ?? null) ? $state['tenant_slug'] : null,
            'redirect_to' => is_string($state['redirect_to'] ?? null)
                ? $this->safeInternalRedirect($state['redirect_to'])
                : null,
        ];

        // Staff (teacher/admin) identities own a workspace tenant.
        $wantsStaffTenant = in_array($role, ['teacher', 'admin'], true);

        // Cross-host handoff target (a teacher's own custom domain), if any.
        // Present only when `redirect()` verified a live tenant host and a
        // browser binding; a platform host leaves both null and keeps the
        // existing single-host session flow untouched.
        $handoffHost = is_string($state['handoff_host'] ?? null) ? $state['handoff_host'] : null;
        $bindingHash = is_string($state['bh'] ?? null) ? $state['bh'] : null;

        // On a teacher's own host the tenant is resolved from the host — the
        // internal slug never has to travel in a URL. Fall back to it only when
        // the signup form did not already supply a slug.
        if ($handoffHost !== null && empty($regData['tenant_slug'])) {
            $handoffTenant = app(TenantDomainResolver::class)->tenantForHost($handoffHost);

            if ($handoffTenant) {
                $regData['tenant_slug'] = $handoffTenant->slug;
            }
        }

        try {
            $google = Socialite::driver('google')->stateless()->user();
        } catch (\Throwable $e) {
            Log::error('google.callback_failed', ['error' => $e->getMessage()]);

            return $this->redirectToFrontend($frontend, $role, ['error' => 'google_failed']);
        }

        $email = strtolower(trim((string) $google->getEmail()));
        $name = $regData['name'] ?? $google->getName() ?? $google->getNickname() ?? 'User';

        // One canonical phone form (E.164). A teacher account will NOT get a
        // workspace or a trial until a phone is on file — see the transaction.
        $phone = PhoneNumber::toE164($regData['phone'] ?? null);

        if ($email === '') {
            return $this->redirectToFrontend($frontend, $role, ['error' => 'no_email']);
        }

        // Include soft-deleted rows: a deleted account keeps its address in the
        // unique index, so a plain lookup would miss it and the later INSERT
        // would collide (the `error=retry` dead-end).
        $user = User::findByEmail($email);
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

        // The verified Google email proves ownership of a soft-deleted account:
        // reactivate it (non-destructively, with its workspaces) so login never
        // dead-ends on the reserved address.
        if ($user && $user->trashed()) {
            $user->restoreWithWorkspaces();
        }

        if ($user && ! $user->is_active) {
            return $this->redirectToFrontend($frontend, $role, ['error' => 'inactive']);
        }

        // Google login must never bypass an enabled second factor.
        if ($user && $user->two_factor_enabled) {
            // On a teacher's own host the platform's session-bound 2FA confirm
            // step is unreachable, and 2FA must never be skipped: send the user
            // to that host's own login (email/password + 2FA) instead.
            if ($handoffHost !== null) {
                return redirect()->away('https://'.$handoffHost.'/auth/google/complete?error=two_factor_required');
            }

            if ($request->hasSession()) {
                // Web session flow: the pending challenge stays server-side.
                $request->session()->put('auth.2fa_pending', [
                    'user_id' => $user->id,
                    'tenant_id' => $this->studentTenantId($user, $regData['tenant_slug']),
                    'expires_at' => now()->addMinutes(5)->getTimestamp(),
                ]);

                return $this->redirectToFrontend($frontend, $role, [
                    'step' => '2fa',
                    'redirect_to' => $regData['redirect_to'],
                ]);
            }

            $pending = bin2hex(random_bytes(16));
            Cache::put('2fa:'.$pending, $user->id, now()->addMinutes(5));

            return redirect()->away($frontend.'/auth/google/callback#requires_2fa=1&two_factor_token='.$pending);
        }

        // ── Create the account and its workspace atomically. If anything
        //    fails the whole thing rolls back: no orphan user without a
        //    tenant, no tenant without an owner membership. ────────────────
        try {
            $trialStarted = false;
            $createdWorkspace = false;
            [$user, $tenant] = DB::transaction(function () use ($email, $name, $phone, $regData, $role, $wantsStaffTenant, &$trialStarted, &$createdWorkspace) {
                // Re-read inside the transaction and take a row lock so
                // concurrent callbacks converge on one row.
                $user = User::withTrashed()->where('email', $email)->lockForUpdate()->first();

                if ($user && $user->trashed()) {
                    // A concurrent path may have soft-deleted mid-flight; the
                    // verified email still owns it, so reactivate.
                    $user->restoreWithWorkspaces();
                }

                if (! $user) {
                    $user = User::create([
                        'id' => UuidV7::generate(),
                        'name' => $name,
                        'email' => $email,
                        'phone' => $phone,
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
                    if ($phone !== null) {
                        $updates['phone'] = $phone;
                    }
                    if ($updates) {
                        $user->update($updates);
                    }
                }

                // A teacher MUST have a phone before a workspace or trial is
                // created. Give the account the Teacher role (so the UI and the
                // server gate see a pending profile), but provision nothing:
                // the tenant, membership and free trial are created exactly once
                // by /auth/complete-profile.
                if ($role === 'teacher' && ! filled($user->phone)) {
                    if (! $user->hasRole('Teacher')) {
                        $user->assignRole(Role::findOrCreate('Teacher', 'web'));
                    }

                    return [$user, null];
                }

                return [$user, $this->ensureWorkspaceTenant($user, $name, $email, $wantsStaffTenant, $trialStarted, $createdWorkspace)];
            });
        } catch (QueryException $e) {
            // A concurrent callback won the race: the unique index rejected the
            // duplicate and the transaction rolled back cleanly. Re-read the
            // winner's rows and continue signing this user in.
            if (! $this->isDuplicateKey($e)) {
                throw $e;
            }

            // This request's provisioning rolled back — it created nothing, so
            // it must never claim credit for the new teacher. The winning
            // callback owns the alert.
            $createdWorkspace = false;

            $user = User::findByEmail($email);

            if (! $user) {
                Log::warning('google.callback_unique_collision');

                return $this->redirectToFrontend($frontend, $role, ['error' => 'retry']);
            }

            $tenant = $wantsStaffTenant ? Tenant::where('owner_id', $user->id)->first() : null;
        }

        // A brand-new teacher workspace was provisioned for the first time:
        // alert the Super Admin once, now that the transaction has committed.
        if ($createdWorkspace && $tenant) {
            app(NewTeacherNotifier::class)->announce($user, $tenant->id, $trialStarted);
        }

        // A teacher still lacking a phone has no workspace/trial yet: send the
        // browser to the «أكمل بيانات حسابك» step instead of the dashboard.
        $needsProfile = $role === 'teacher' && ! filled($user->phone);
        $studentTenantId = $this->studentTenantId($user, $regData['tenant_slug']);

        // Custom teacher domain: never sign the PLATFORM session. Mint a
        // single-use ticket bound to {user, tenant, host, initiating browser}
        // and send the browser to the teacher host, whose BFF redeems it for a
        // host-only session. The ticket id lives in the URL FRAGMENT, so it is
        // never sent to a server and cannot leak into access logs or Referer.
        if ($handoffHost !== null && $bindingHash !== null && $this->isLiveHandoffHost($handoffHost)) {
            $ticket = GoogleHandoff::issue([
                'user_id' => $user->id,
                'tenant_id' => $studentTenantId ?? $tenant?->id,
                'role' => $role,
                'redirect_to' => $regData['redirect_to'],
                'needs_profile' => $needsProfile,
                'host' => $handoffHost,
                'bh' => $bindingHash,
            ]);

            return redirect()->away('https://'.$handoffHost.'/auth/google/complete#ticket='.$ticket);
        }

        // Web browser flow: sign in with an HttpOnly session cookie — the
        // Sanctum token never leaves the server. Mobile keeps using the
        // bearer-token flow of /api/v1/mobile/auth/google.
        if ($request->hasSession()) {
            WebSessionAuth::login($request, $user);
            if ($studentTenantId) {
                $request->session()->put('current_tenant_id', $studentTenantId);
            } else {
                $request->session()->forget('current_tenant_id');
            }

            return $this->redirectToFrontend($frontend, $role, $needsProfile
                ? ['step' => 'profile_required']
                : [
                    'step' => 'done',
                    'trial_started' => $trialStarted ? '1' : '0',
                    'redirect_to' => $regData['redirect_to'],
                ]);
        }

        $token = $user->createToken('auth-token')->plainTextToken;

        // Sensitive values go in the URL *fragment*: fragments are never sent
        // to servers, so tokens cannot leak into access logs or Referer.
        $fragment = http_build_query([
            'token' => $token,
            'tenant_id' => $studentTenantId ?? $tenant?->id ?? '',
            'role' => $role,
            'trial_started' => $trialStarted ? '1' : '0',
            'needs_profile_completion' => $needsProfile ? '1' : '0',
            'user' => json_encode([
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone ?? null,
                'roles' => $user->roles->pluck('name')->toArray(),
            ], JSON_UNESCAPED_UNICODE),
        ]);

        return redirect()->away($frontend.'/auth/google/callback#'.$fragment);
    }

    /**
     * Redeem a cross-host handoff ticket on the teacher's OWN domain.
     *
     * Called by that host's BFF (server-to-server, stateful session) with the
     * opaque ticket and the browser's HttpOnly binding secret. On success it
     * starts a session for THIS host and returns the Set-Cookie the BFF forwards
     * to the browser — no token is ever placed in a URL.
     */
    public function redeem(Request $request): JsonResponse
    {
        $host = $this->handoffHost($request);
        $ticket = (string) $request->input('ticket', '');
        $binding = (string) $request->input('binding', '');

        if ($host === null || $ticket === '' || $binding === '') {
            return response()->json(['message' => 'Invalid sign-in handoff.'], 422);
        }

        // Single-use: a replay (or an expired ticket) finds nothing.
        $claims = GoogleHandoff::consume($ticket);

        if ($claims === null) {
            return response()->json(['message' => 'This sign-in link expired or was already used. Please try again.'], 410);
        }

        // The ticket is usable only by the host it was minted for AND the browser
        // that started the flow (defeats cross-host replay, login CSRF and
        // session-fixation onto another user).
        if (! hash_equals((string) ($claims['host'] ?? ''), $host)
            || ! hash_equals((string) ($claims['bh'] ?? ''), GoogleHandoff::bindingHash($binding))) {
            return response()->json(['message' => 'This sign-in link is not valid for this site.'], 403);
        }

        $user = User::withTrashed()->find($claims['user_id'] ?? null);

        if (! $user || $user->trashed() || ! $user->is_active) {
            return response()->json(['message' => 'Account unavailable.'], 403);
        }

        WebSessionAuth::login($request, $user);

        $tenantId = is_string($claims['tenant_id'] ?? null) ? $claims['tenant_id'] : null;

        if ($tenantId) {
            $request->session()->put('current_tenant_id', $tenantId);
        } else {
            $request->session()->forget('current_tenant_id');
        }

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone ?? null,
                'roles' => $user->roles->pluck('name')->toArray(),
            ],
            'tenant_id' => $tenantId,
            'needs_profile_completion' => (bool) ($claims['needs_profile'] ?? false),
            'redirect_to' => $claims['redirect_to'] ?? null,
        ]);
    }

    /**
     * The verified, non-platform destination host for a cross-host handoff, or
     * null when this request is not for a live teacher domain (platform hosts
     * keep the single-host session flow).
     */
    private function handoffHost(Request $request): ?string
    {
        $host = $this->requestOriginHost($request);

        if ($host === null || ! $this->isLiveHandoffHost($host)) {
            return null;
        }

        return $host;
    }

    /** Hostname from the request Origin (preferred) or Referer, lowercased. */
    private function requestOriginHost(Request $request): ?string
    {
        $value = $request->headers->get('origin') ?: $request->headers->get('referer');

        if (! is_string($value) || $value === '') {
            return null;
        }

        $host = parse_url($value, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? strtolower($host) : null;
    }

    /** A live host of an ACTIVE tenant — never one of the platform's own hosts. */
    private function isLiveHandoffHost(string $host): bool
    {
        $resolver = app(TenantDomainResolver::class);

        return ! $resolver->isPlatformHost($host) && $resolver->belongsToActiveTenant($host);
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
    private function ensureWorkspaceTenant(User $user, string $name, string $email, bool $wantsStaffTenant, bool &$trialStarted = false, bool &$created = false): ?Tenant
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

        $created = true;

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

        // منح التجربة المجانية عبر البرنامج المركزي فقط (نفس قواعد التسجيل
        // بالبريد): يخضع للمفتاح الرئيسي وعداد المقاعد. عند الإيقاف أو نفاد
        // المقاعد لا يُنشأ اشتراك، ويبقى الحساب «بانتظار الدفع». Mirror of
        // AuthController::register().
        $trialStarted = app(TeacherTrialProgram::class)
            ->grantTrial($user, $tenant->id, 'google', true) !== null;

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

    private function studentTenantId(User $user, ?string $tenantSlug): ?string
    {
        if ($user->is_super_admin || $user->hasRole('Teacher')) {
            return null;
        }

        if ($tenantSlug !== null && $tenantSlug !== '') {
            $tenant = Tenant::where('slug', $tenantSlug)->where('status', 'active')->first();
            if (! $tenant) {
                return null;
            }

            return TenantUser::where('tenant_id', $tenant->id)
                ->where('user_id', $user->id)
                ->where('role', 'student')
                ->where('status', 'active')
                ->exists()
                    ? (string) $tenant->id
                    : null;
        }

        $tenantIds = TenantUser::where('user_id', $user->id)
            ->where('role', 'student')
            ->where('status', 'active')
            ->limit(2)
            ->pluck('tenant_id');

        return $tenantIds->count() === 1 ? (string) $tenantIds->first() : null;
    }

    private function safeInternalRedirect(string $candidate): ?string
    {
        $candidate = trim($candidate);

        if ($candidate === ''
            || strlen($candidate) > 512
            || ! str_starts_with($candidate, '/')
            || str_starts_with($candidate, '//')
            || str_starts_with($candidate, '/\\')
            || preg_match('/[\r\n]/', $candidate)) {
            return null;
        }

        $parts = parse_url($candidate);
        if ($parts === false || isset($parts['scheme']) || isset($parts['host'])) {
            return null;
        }

        return $candidate;
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
