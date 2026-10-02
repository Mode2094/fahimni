<?php

namespace App\Traits;

use Illuminate\Http\Request;

trait AuthorizesTenantAccess
{
    public const TENANT_STAFF_ROLES = ['teacher', 'owner', 'admin'];

    protected function tenantId(Request $request): ?string
    {
        $tenant = $request->attributes->get('current_tenant');

        if ($tenant && method_exists($tenant, 'getKey')) {
            return (string) $tenant->getKey();
        }

        return $request->header('X-Tenant-Id');
    }

    protected function isTenantStaff(Request $request, ?string $tenantId = null): bool
    {
        $user = $request->user();

        if (! $user || $user->is_super_admin) {
            return (bool) $user && $user->is_super_admin;
        }

        $tenantId ??= $this->tenantId($request);

        if (! $tenantId) {
            return false;
        }

        return $user->tenantPivots()
            ->where('tenant_id', $tenantId)
            ->whereIn('role', self::TENANT_STAFF_ROLES)
            ->where('status', 'active')
            ->exists();
    }

    protected function isTenantMember(Request $request, ?string $tenantId = null): bool
    {
        $user = $request->user();

        if (! $user) {
            return false;
        }

        if ($user->is_super_admin) {
            return true;
        }

        $tenantId ??= $this->tenantId($request);

        if (! $tenantId) {
            return false;
        }

        return $user->tenants()
            ->where('tenant_id', $tenantId)
            ->wherePivot('status', 'active')
            ->exists();
    }

    protected function requireTenantStaff(Request $request, ?string $tenantId = null): void
    {
        abort_unless($this->isTenantStaff($request, $tenantId), 403, 'Teacher access required.');
    }

    protected function requireTenantMember(Request $request, ?string $tenantId = null): void
    {
        abort_unless($this->isTenantMember($request, $tenantId), 403, 'Access denied.');
    }
}
