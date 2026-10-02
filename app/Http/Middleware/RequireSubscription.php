<?php

namespace App\Http\Middleware;

use App\Services\FeatureService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tenant staff accounts are suspended the moment their subscription (or
 * trial) lapses: every management action returns 402 until the tenant
 * subscribes and pays. Students are never blocked, and the checkout /
 * account routes stay reachable so the staff can reactivate.
 *
 * Must run after the 'tenant' middleware.
 */
class RequireSubscription
{
    /**
     * Route prefixes (relative to api/v1) that must work without an active
     * subscription: plan browsing, checkout, payment status, the dashboard
     * (shows the "subscribe" state), branding and communication.
     */
    private const ALLOWED_PREFIXES = [
        'plans',
        'subscriptions',
        'payments',
        'teacher/plan',
        'dashboard',
        'settings',
        'teacher/domain',
        'teacher/site-design',
        'notifications',
        'preferences',
        'push-tokens',
        'conversations',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $user->is_super_admin) {
            return $next($request);
        }

        // TenantMiddleware stores the validated tenant via $request->merge()
        // (input bag) and the X-Tenant-Id header — read both, like the rest
        // of the codebase does.
        $tenant = $request->attributes->get('current_tenant')
            ?? $request->get('current_tenant');

        if (! $tenant) {
            return $next($request);
        }

        $tenantId = method_exists($tenant, 'getKey') ? $tenant->getKey() : $tenant;

        $pivot = $user->tenantPivots()
            ->where('tenant_id', $tenantId)
            ->whereIn('role', ['teacher', 'owner', 'admin'])
            ->where('status', 'active')
            ->first();

        // Students and non-staff members are never blocked.
        if (! $pivot) {
            return $next($request);
        }

        $relative = $request->path();

        if (str_starts_with($relative, 'api/v1/')) {
            $relative = substr($relative, 7);
        }

        foreach (self::ALLOWED_PREFIXES as $prefix) {
            if ($relative === $prefix || str_starts_with($relative, $prefix.'/')) {
                return $next($request);
            }
        }

        if (app(FeatureService::class)->activeSubscription()) {
            return $next($request);
        }

        return response()->json([
            'message' => 'Your subscription has ended. Subscribe to a plan to reactivate your account.',
            'requires_subscription' => true,
        ], 402);
    }
}
