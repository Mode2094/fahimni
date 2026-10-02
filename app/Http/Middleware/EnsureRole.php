<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRole
{
    /**
     * Verifies the authenticated user holds one of the given tenant roles
     * (e.g. teacher, owner, admin) within the current tenant, and optionally
     * a staff permission allowlist entry via "permission:<name>".
     *
     * Super admins always pass.
     * Must run after the 'tenant' middleware so $request->current_tenant exists.
     *
     * Usage: ->middleware('role:teacher,owner,admin')
     *        ->middleware('role:teacher,owner,admin,permission:students.manage')
     */
    public function handle(Request $request, Closure $next, string ...$args): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if ($user->is_super_admin) {
            return $next($request);
        }

        $roles = [];
        $permission = null;

        foreach ($args as $arg) {
            if (str_starts_with($arg, 'permission:')) {
                $permission = substr($arg, strlen('permission:'));
            } else {
                $roles[] = $arg;
            }
        }

        $tenant = $request->current_tenant ?? null;
        if (! $tenant) {
            return response()->json([
                'message' => 'Tenant context is required. Provide X-Tenant-Id header or tenant_id parameter.',
            ], 400);
        }

        $pivot = $user->tenantPivots()
            ->where('tenant_id', $tenant->id)
            ->where('status', 'active')
            ->first();

        if (! $pivot || ! in_array($pivot->role, $roles, true)) {
            return response()->json(['message' => 'Forbidden. Insufficient role.'], 403);
        }

        if ($permission !== null && $pivot->role === 'teacher' && ! $user->canInTenant($tenant->id, $permission)) {
            return response()->json(['message' => 'Forbidden. Missing staff permission: '.$permission], 403);
        }

        return $next($request);
    }
}
