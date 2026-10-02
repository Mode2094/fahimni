<?php

namespace App\Policies;

use App\Models\Tenant;
use App\Models\User;
use App\Policies\Concerns\ChecksTenantStaff;

class TenantPolicy
{
    use ChecksTenantStaff;

    public function view(User $user, Tenant $tenant): bool
    {
        return $this->isTenantMember($user, $tenant->id);
    }

    public function manage(User $user, Tenant $tenant): bool
    {
        return $this->isTenantStaff($user, $tenant->id);
    }

    public function delete(User $user, Tenant $tenant): bool
    {
        return $user->is_super_admin;
    }
}
