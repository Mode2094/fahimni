<?php

namespace App\Policies;

use App\Models\Enrollment;
use App\Models\User;
use App\Policies\Concerns\ChecksTenantStaff;

class EnrollmentPolicy
{
    use ChecksTenantStaff;

    public function viewAny(User $user): bool
    {
        return $this->isTenantStaff($user);
    }

    public function view(User $user, Enrollment $enrollment): bool
    {
        $tenantId = $this->tenantId();

        if ($enrollment->tenant_id !== $tenantId) {
            return false;
        }

        if ($user->is_super_admin) {
            return true;
        }

        if ($this->isTenantStaff($user, $tenantId)) {
            return true;
        }

        return $enrollment->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $this->isTenantStaff($user);
    }

    public function update(User $user, Enrollment $enrollment): bool
    {
        return $this->create($user) && $this->view($user, $enrollment);
    }

    public function delete(User $user, Enrollment $enrollment): bool
    {
        return $this->create($user) && $this->view($user, $enrollment);
    }
}
