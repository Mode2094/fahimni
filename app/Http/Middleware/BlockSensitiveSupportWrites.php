<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\AdminSupportSession;
use App\Support\AuditLogger;
use App\Support\WebSessionAuth;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guard for the Super-Admin support session (Admin Impersonation).
 *
 * While a support session is active the admin works inside ONE teacher's
 * workspace. Unlike the read-only `view_as` preview, normal content work is
 * allowed (courses, lessons, students, settings, domain) — but the elevated,
 * teacher-scoped privilege is NEVER used for sensitive operations. Those are
 * refused here, server-side, and must be performed from the admin panel with an
 * explicit admin action:
 *
 *  - every DELETE (data destruction);
 *  - money & billing (payments, transactions, invoices, subscriptions,
 *    checkout, refunds, plan changes);
 *  - credentials & secrets (passwords, 2FA, security settings, API keys,
 *    access tokens);
 *  - identity & ownership (roles, permissions, ownership transfer, members,
 *    invites, webhooks).
 *
 * Every allowed write is audited with the REAL admin identity (not the
 * impersonated teacher's), so the trail always names who actually acted.
 *
 * The session is short-lived: once `expires_at` passes the session is closed
 * and the original admin is restored (or signed out if that admin is gone).
 */
class BlockSensitiveSupportWrites
{
    /** Writes that must stay reachable while a session is active. */
    private const CONTROL_ROUTES = [
        'admin.support.stop',
        'admin.support.show',
    ];

    /**
     * Path fragments that must never run under an impersonated teacher. Matched
     * case-insensitively against the request path; reads (GET/HEAD/OPTIONS) are
     * always allowed so the admin can still inspect these areas.
     */
    private const SENSITIVE = [
        // Money / billing / subscription.
        'payment', 'transaction', 'invoice', 'billing', 'subscription', 'freemius',
        'checkout', 'refund', 'wallet', 'manual-subscription', 'plans',
        // Credentials / secrets.
        'password', '2fa', 'security', 'api-key', 'api_key', 'apikey', 'token',
        'personal-access',
        // Identity / ownership / roles.
        'owner', 'transfer', 'role', 'permission', 'member', 'invite', 'webhook',
        // Account lifecycle.
        'tenants', 'account',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $state = AdminSupportSession::get($request);

        if (! $state) {
            return $next($request);
        }

        // Expired: close the session safely before doing anything else.
        if (AdminSupportSession::isExpired($state)) {
            $this->endExpired($request, $state);

            return response()->json([
                'message' => 'انتهت جلسة الدعم الإداري وتمت العودة إلى حساب الإدارة.',
                'code' => 'SUPPORT_EXPIRED',
            ], 401);
        }

        $method = strtoupper($request->method());

        // Reads are always allowed.
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }

        if ($request->routeIs(...self::CONTROL_ROUTES)) {
            return $next($request);
        }

        if ($method === 'DELETE' || $this->isSensitive(strtolower($request->path()))) {
            return response()->json([
                'message' => 'هذه عملية حساسة (مالية/أمنية) — أنهِ وضع الدعم ونفّذها من لوحة الإدارة.',
                'code' => 'SUPPORT_SENSITIVE_BLOCKED',
            ], 403);
        }

        // A normal content write is allowed — record it under the REAL admin.
        AuditLogger::log($request, 'admin.support.write', 'route', $request->route()?->getName(), [
            'actor_id' => $state['actor_id'] ?? null,
            'actor_name' => $state['actor_name'] ?? null,
            'impersonated_user_id' => $state['user_id'] ?? null,
            'method' => $method,
            'path' => $request->path(),
        ], $state['tenant_id'] ?? null, $state['actor_id'] ?? null);

        return $next($request);
    }

    private function isSensitive(string $path): bool
    {
        foreach (self::SENSITIVE as $needle) {
            if (str_contains($path, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function endExpired(Request $request, array $state): void
    {
        if (! $request->hasSession()) {
            return;
        }

        $actor = User::find($state['actor_id'] ?? null);
        AdminSupportSession::forget($request);

        if ($actor && $actor->is_super_admin) {
            // Guaranteed, safe return to the original admin session.
            WebSessionAuth::login($request, $actor);

            AuditLogger::log($request, 'admin.support.expired', 'user', $state['user_id'] ?? null, [
                'actor_id' => $actor->id,
                'returned_to' => $actor->id,
            ], $state['tenant_id'] ?? null, $actor->id);

            return;
        }

        // No valid admin to return to: never leave the impersonated session
        // authenticated.
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
