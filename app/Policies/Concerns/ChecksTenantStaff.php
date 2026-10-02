<?php

namespace App\Policies\Concerns;

use App\Models\User;

trait ChecksTenantStaff
{
    protected function tenantId(): ?string
    {
        $tenant = request()->attributes->get('current_tenant');

        if ($tenant && method_exists($tenant, 'getKey')) {
            return (string) $tenant->getKey();
        }

        return request()->header('X-Tenant-Id');
    }

    protected function isTenantStaff(User $user, ?string $tenantId = null): bool
    {
        if ($user->is_super_admin) {
            return true;
        }

        $tenantId ??= $this->tenantId();

        if (! $tenantId) {
            return false;
        }

        return $user->tenantPivots()
            ->where('tenant_id', $tenantId)
            ->whereIn('role', ['teacher', 'owner', 'admin'])
            ->where('status', 'active')
            ->exists();
    }

    protected function isTenantMember(User $user, ?string $tenantId = null): bool
    {
        if ($user->is_super_admin) {
            return true;
        }

        $tenantId ??= $this->tenantId();

        if (! $tenantId) {
            return false;
        }

        return $user->tenants()
            ->where('tenant_id', $tenantId)
            ->wherePivot('status', 'active')
            ->exists();
    }
}
