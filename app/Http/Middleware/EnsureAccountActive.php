<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Server-side revocation for first-party web sessions.
 *
 * Laravel's session store is keyed by session id, so a password change,
 * account deactivation or device reset cannot enumerate and delete "all
 * sessions of user X" on its own. Every web session records the account's
 * `auth_version`; bumping users.auth_version (see WebSessionAuth) makes every
 * session that still carries the previous value fail on its next request.
 *
 * Runs after StartSession (the `api` group is stateful-aware) and before
 * `auth:sanctum`, so a revoked session is answered with 401 — never a
 * controller — while public auth endpoints still get to run (a user with a
 * stale cookie must be able to sign in again).
 */
class EnsureAccountActive
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession() || ! Auth::guard('web')->check()) {
            return $next($request);
        }

        $user = Auth::guard('web')->user();
        $invalid = ! $user || ! $user->is_active || $user->trashed();

        if (! $invalid) {
            $sessionVersion = $request->session()->get('auth.version');
            $currentVersion = (int) $user->auth_version;

            if ($sessionVersion === null) {
                // Sessions created before auth_version existed adopt the
                // current value instead of being logged out by the deploy.
                $request->session()->put('auth.version', $currentVersion);
            } elseif ((int) $sessionVersion !== $currentVersion) {
                $invalid = true;
            }
        }

        if (! $invalid) {
            return $next($request);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // Sign-in must still be reachable with the now-dead cookie: the login
        // controller replaces the invalidated session with a fresh one.
        if ($this->isPublicAuthEndpoint($request)) {
            return $next($request);
        }

        return response()->json([
            'message' => 'Your session has ended. Please sign in again.',
            'code' => 'session_expired',
        ], 401);
    }

    private function isPublicAuthEndpoint(Request $request): bool
    {
        return $request->is(
            'api/v1/auth/login',
            'api/v1/auth/register',
            'api/v1/auth/2fa/confirm',
            'api/v1/auth/forgot-password',
            'api/v1/auth/reset-password',
            'api/v1/auth/captcha',
            'api/v1/auth/google/*',
            'api/v1/mobile/*',
        );
    }
}
