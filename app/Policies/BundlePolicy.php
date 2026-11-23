<?php

namespace App\Policies;

use App\Models\Bundle;
use App\Models\User;
use App\Policies\Concerns\ChecksTenantStaff;

class BundlePolicy
{
    use ChecksTenantStaff;

    public function viewAny(User $user): bool
    {
        return $this->isTenantMember($user);
    }

    public function view(User $user, Bundle $bundle): bool
    {
        if ($user->is_super_admin) {
            return true;
        }

        $tenantId = $this->tenantId();

        if ($bundle->tenant_id !== $tenantId) {
            return false;
        }

        if ($this->isTenantStaff($user, $tenantId)) {
            return true;
        }

        // Students only see published bundles of their own tenant.
        return $bundle->is_published && $this->isTenantMember($user, $tenantId);
    }

    public function create(User $user): bool
    {
        return $this->isTenantStaff($user);
    }

    public function update(User $user, Bundle $bundle): bool
    {
        if ($user->is_super_admin) {
            return true;
        }

        return $bundle->tenant_id === $this->tenantId()
            && $this->isTenantStaff($user, $bundle->tenant_id);
    }

    public function delete(User $user, Bundle $bundle): bool
    {
        return $this->update($user, $bundle);
    }
}
