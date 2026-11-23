<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforce the mandatory teacher profile (name + phone) on the server, not just
 * in the UI. A teacher account whose phone is still missing may not use the
 * teacher workspace until it is completed — a client cannot bypass the
 * completion screen by calling the API directly.
 *
 * Scope is deliberately narrow:
 *  - super admins always pass;
 *  - accounts without the Teacher role pass (students are never affected);
 *  - a dual-role account operating inside a NON-staff tenant passes, so a
 *    student who also teaches keeps their student experience.
 *
 * The completion endpoint itself (/auth/complete-profile) lives OUTSIDE this
 * middleware, so a blocked teacher can always finish their profile.
 */
class EnsureTeacherProfileComplete
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $user->is_super_admin || ! $user->hasRole('Teacher')) {
            return $next($request);
        }

        // A phone on file means the profile is complete.
        if (filled($user->phone)) {
            return $next($request);
        }

        // If the request runs in a student-tenant context, do not block it —
        // only teacher/staff workspace usage requires the phone.
        $tenant = $request->attributes->get('current_tenant');
        $tenantId = $tenant?->id ?? $request->header('X-Tenant-Id');

        if ($tenantId) {
            $pivotRole = $user->tenantPivots()
                ->where('tenant_id', $tenantId)
                ->where('status', 'active')
                ->value('role');

            if ($pivotRole !== null && ! in_array($pivotRole, ['teacher', 'owner', 'admin'], true)) {
                return $next($request);
            }
        }

        return response()->json([
            'message' => 'أكمل بيانات حسابك (الاسم ورقم الهاتف) أولًا.',
            'code' => 'profile_incomplete',
        ], 403);
    }
}
