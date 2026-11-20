<?php

namespace App\Policies;

use App\Models\AcademicTerm;
use App\Models\User;
use App\Policies\Concerns\ChecksTenantStaff;

class AcademicTermPolicy
{
    use ChecksTenantStaff;

    public function viewAny(User $user): bool
    {
        return $this->isTenantStaff($user);
    }

    public function view(User $user, AcademicTerm $term): bool
    {
        $tenantId = $this->tenantId();

        if ($term->tenant_id !== $tenantId) {
            return false;
        }

        return $user->is_super_admin || $this->isTenantStaff($user, $tenantId);
    }

    public function create(User $user): bool
    {
        return $this->isTenantStaff($user);
    }

    public function update(User $user, AcademicTerm $term): bool
    {
        return $this->create($user) && $this->view($user, $term);
    }

    public function delete(User $user, AcademicTerm $term): bool
    {
        return $this->create($user) && $this->view($user, $term);
    }
}
