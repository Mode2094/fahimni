<?php

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\ChecksTenantStaff;

class TenantSettingPolicy
{
    use ChecksTenantStaff;

    public function view(User $user): bool
    {
        return $this->isTenantStaff($user);
    }

    public function update(User $user): bool
    {
        return $this->isTenantStaff($user);
    }
}
