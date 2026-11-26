<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Tenant;
use App\Models\User;
use App\Support\AdminSupportSession;
use App\Support\AuditLogger;
use App\Support\WebSessionAuth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Super-Admin "support session" — enter ONE teacher's workspace to actually
 * help them (manage content, settings, domain), not just look.
 *
 * Safety model (see also {@see \App\Support\AdminSupportSession} and
 * {@see \App\Http\Middleware\BlockSensitiveSupportWrites}):
 *
 *  - only a super admin may begin/activate; re-checked server-side, never from
 *    a client flag;
 *  - the admin's browser session is swapped server-side (Sanctum/BFF cookie),
 *    and the admin identity is preserved in the session so exiting — or the
 *    session expiring — restores the admin;
 *  - opening the workspace needs a one-time token (short TTL) so a leaked or
 *    replayed URL can never establish a support session;
 *  - only the target tenant is affected; nesting is refused, so support
 *    sessions can never chain between teachers;
 *  - every start/stop is audited with the REAL admin identity.
 */
class AdminSupportController extends Controller
{
    /** Seconds a one-time enter token stays redeemable. */
    private const TOKEN_TTL = 120;

    public function begin(Request $request, string $teacher): JsonResponse
    {
        $actor = $request->user();

        if (! $actor || ! $actor->is_super_admin) {
            return response()->json(['message' => 'Only a platform admin can enter a teacher workspace.'], 403);
        }

        if ($request->hasSession() && ($request->session()->has('view_as') || AdminSupportSession::get($request))) {
            return response()->json(['message' => 'An impersonation session is already active — exit it first.'], 409);
        }

        $target = $this->teacherOrFail($teacher);
        $tenant = $this->tenantOf($target);

        if (! $tenant) {
            return response()->json(['message' => 'This teacher has no workspace yet.'], 422);
        }

        $reason = trim((string) $request->input('reason', ''));
        $reason = $reason !== '' ? $reason : null;

        // One-time, short-lived token bound to the acting admin + target.
        $token = Str::random(64);
        Cache::put(self::cacheKey($token), [
            'actor_id' => $actor->id,
            'user_id' => $target->id,
            'tenant_id' => $tenant->id,
            'reason' => $reason,
        ], now()->addSeconds(self::TOKEN_TTL));

        AuditLogger::log($request, 'admin.support.ticket', 'user', $target->id, [
            'actor_id' => $actor->id,
            'actor_name' => $actor->name,
            'tenant_id' => $tenant->id,
            'reason' => $reason,
        ], $tenant->id, $actor->id);

        return response()->json([
            'token' => $token,
            'expires_in' => self::TOKEN_TTL,
            'enter_path' => '/admin/support/enter?token='.urlencode($token),
        ]);
    }

    public function activate(Request $request): JsonResponse
    {
        $actor = $request->user();

        if (! $actor || ! $actor->is_super_admin) {
            return response()->json(['message' => 'Only a platform admin can enter a teacher workspace.'], 403);
        }

        if (! $request->hasSession()) {
            return response()->json(['message' => 'A support session requires a browser session.'], 400);
        }

        if ($request->session()->has('view_as') || AdminSupportSession::get($request)) {
            return response()->json(['message' => 'An impersonation session is already active — exit it first.'], 409);
        }

        $token = (string) $request->input('token', '');

        if ($token === '') {
            return response()->json(['message' => 'A one-time entry token is required.'], 422);
        }

        // Single-use: the token is consumed whether or not it is valid.
        $payload = Cache::pull(self::cacheKey($token));

        if (! is_array($payload)) {
            return response()->json([
                'message' => 'This entry link has expired or was already used. Start again from the admin panel.',
                'code' => 'TOKEN_INVALID',
            ], 422);
        }

        // The token is bound to the admin that created it.
        if (($payload['actor_id'] ?? null) !== $actor->id) {
            return response()->json(['message' => 'This entry link belongs to another administrator.'], 403);
        }

        $target = $this->teacherOrFail((string) ($payload['user_id'] ?? ''));
        $tenant = $this->tenantOf($target);
        $tenantId = $payload['tenant_id'] ?? $tenant?->id;

        // Swap THIS browser session to the teacher; the admin identity is kept
        // in the marker so it can be restored on exit/expiry.
        WebSessionAuth::login($request, $target);
        AdminSupportSession::put($request, $actor, $target, $tenantId, $payload['reason'] ?? null);

        AuditLogger::log($request, 'admin.support.start', 'user', $target->id, [
            'actor_id' => $actor->id,
            'actor_name' => $actor->name,
            'tenant_id' => $tenantId,
            'reason' => $payload['reason'] ?? null,
        ], $tenantId, $actor->id);

        return response()->json([
            'redirect' => '/teacher/dashboard',
            'user' => new UserResource($target->load('tenants')),
            'admin_support' => AdminSupportSession::payload($request),
        ]);
    }

    public function stop(Request $request): JsonResponse
    {
        $state = AdminSupportSession::get($request);

        if (! $state) {
            return response()->json(['message' => 'No support session is active.'], 422);
        }

        $actor = User::find($state['actor_id']);

        if (! $actor || ! $actor->is_super_admin) {
            // The acting admin vanished or lost admin rights while working:
            // never leave the impersonated session authenticated.
            if ($request->hasSession()) {
                AdminSupportSession::forget($request);
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            return response()->json(['message' => 'Support session ended. Please sign in again.'], 401);
        }

        $targetUserId = $state['user_id'];
        $tenantId = $state['tenant_id'] ?? null;

        // Restore the original admin session (same cookie, admin identity back).
        WebSessionAuth::login($request, $actor);
        AdminSupportSession::forget($request);

        AuditLogger::log($request, 'admin.support.stop', 'user', $targetUserId, [
            'actor_id' => $actor->id,
            'actor_name' => $actor->name,
            'tenant_id' => $tenantId,
        ], $tenantId, $actor->id);

        return response()->json([
            'user' => new UserResource($actor->load('tenants')),
            'admin_support' => null,
        ]);
    }

    public function show(Request $request): JsonResponse
    {
        return response()->json(['admin_support' => AdminSupportSession::payload($request)]);
    }

    private function teacherOrFail(string $id): User
    {
        $target = User::role('Teacher')->with([
            'tenantPivots' => fn ($pivots) => $pivots->withoutTenant()->with('tenant'),
        ])->find($id);

        // A missing/!teacher account, or an admin, can never be supported —
        // admin power must never be borrowed through this path.
        abort_if(! $target || $target->is_super_admin, 404, 'Teacher account not found.');

        return $target;
    }

    private function tenantOf(User $teacher): ?Tenant
    {
        return $teacher->tenantPivots->first()?->tenant;
    }

    private static function cacheKey(string $token): string
    {
        return 'admin_support_token:'.$token;
    }
}
