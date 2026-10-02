<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Support\TenantContext;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TenantMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $context = app(TenantContext::class);
        $context->clear();

        if ($user->is_super_admin) {
            $tenantId = $request->header('X-Tenant-Id')
                ?? $request->input('tenant_id');

            if ($tenantId) {
                try {
                    $tenant = Tenant::find($tenantId);
                    if ($tenant) {
                        $request->merge(['current_tenant' => $tenant]);
                        $request->attributes->set('current_tenant', $tenant);
                        $request->headers->set('X-Tenant-Id', $tenant->id);
                        $context->set($tenant->id);
                    }
                } catch (QueryException) {
                }
            }

            return $next($request);
        }

        $tenantId = $request->header('X-Tenant-Id')
            ?? $request->input('tenant_id');

        if (! $tenantId) {
            return response()->json([
                'message' => 'Tenant ID is required. Provide X-Tenant-Id header or tenant_id parameter.',
            ], 400);
        }

        $tenant = $user->tenants()
            ->where('tenant_id', $tenantId)
            ->wherePivot('status', 'active')
            ->first();

        if (! $tenant) {
            return response()->json([
                'message' => 'Tenant not found or access denied.',
            ], 403);
        }

        $request->merge(['current_tenant' => $tenant]);
        $request->attributes->set('current_tenant', $tenant);
        $request->headers->set('X-Tenant-Id', $tenant->id);
        $context->set($tenant->id);

        return $next($request);
    }
}
