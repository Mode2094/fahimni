<?php

namespace App\Http\Middleware;

use App\Models\ActivationCard;
use App\Models\Tenant;
use App\Models\User;
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

        // A card URL is a tenant-scoped, one-use capability. Resolve its tenant
        // from the stored card rather than trusting a stale browser selection.
        if ($this->isCardRedemption($request)) {
            $tenant = $this->tenantForCard($request);

            if ($tenant instanceof Response) {
                return $tenant;
            }

            $this->setContext($request, $context, $tenant);

            return $next($request);
        }

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

        $requestedTenantId = $request->header('X-Tenant-Id')
            ?? $request->input('tenant_id');
        $tenantId = $requestedTenantId;
        $tenant = null;

        if ($requestedTenantId) {
            $tenant = $this->activeMembership($user, (string) $requestedTenantId);
        } else {
            $sessionTenantId = $request->hasSession()
                ? $request->session()->get('current_tenant_id')
                : null;

            if (is_string($sessionTenantId) && $sessionTenantId !== '') {
                $tenantId = $sessionTenantId;
                $tenant = $this->activeMembership($user, $sessionTenantId);

                // The account may have been removed from the previously active
                // workspace while this session remained open. Clear that stale
                // choice and fall back only if the new context is unambiguous.
                if (! $tenant) {
                    $request->session()->forget('current_tenant_id');
                    $tenantId = null;
                }
            }

            if (! $tenant) {
                $tenant = $this->singleActiveMembership($user);
            }
        }

        if (! $tenant) {
            if (! $tenantId) {
                return response()->json([
                    'message' => 'Tenant context is ambiguous or unavailable. Open your teacher link or select a workspace.',
                    'code' => 'tenant_context_required',
                ], 400);
            }

            return response()->json([
                'message' => 'Tenant not found or access denied.',
            ], 403);
        }

        $this->setContext($request, $context, $tenant);
        if ($request->hasSession()) {
            $request->session()->put('current_tenant_id', $tenant->id);
        }

        return $next($request);
    }

    private function isCardRedemption(Request $request): bool
    {
        return $request->isMethod('POST') && $request->is('api/v1/cards/redeem');
    }

    private function tenantForCard(Request $request): Tenant|Response
    {
        $code = strtoupper(trim((string) $request->input('code', '')));

        if ($code === '') {
            return response()->json(['message' => 'Card code is required.'], 422);
        }

        $card = ActivationCard::withoutTenant()->where('code', $code)->first();

        if (! $card) {
            return response()->json(['message' => 'Invalid card code.'], 404);
        }

        $tenant = Tenant::whereKey($card->tenant_id)->where('status', 'active')->first();

        if (! $tenant) {
            return response()->json(['message' => 'Card is unavailable.'], 404);
        }

        return $tenant;
    }

    private function activeMembership(User $user, string $tenantId): ?Tenant
    {
        return $user->tenants()
            ->where('tenant_id', $tenantId)
            ->wherePivot('status', 'active')
            ->first();
    }

    private function singleActiveMembership(User $user): ?Tenant
    {
        $tenants = $user->tenants()
            ->wherePivot('status', 'active')
            ->limit(2)
            ->get();

        return $tenants->count() === 1 ? $tenants->first() : null;
    }

    private function setContext(Request $request, TenantContext $context, Tenant $tenant): void
    {
        $request->merge(['current_tenant' => $tenant]);
        $request->attributes->set('current_tenant', $tenant);
        $request->headers->set('X-Tenant-Id', $tenant->id);
        $context->set($tenant->id);
    }
}
