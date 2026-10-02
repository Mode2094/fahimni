<?php

namespace App\Policies;

use App\Models\Enrollment;
use App\Models\Section;
use App\Models\User;
use App\Policies\Concerns\ChecksTenantStaff;

class SectionPolicy
{
    use ChecksTenantStaff;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Section $section): bool
    {
        if ($user->is_super_admin) {
            return true;
        }

        $tenantId = $this->tenantId();

        if ($section->tenant_id !== $tenantId) {
            return false;
        }

        if ($this->isTenantStaff($user, $tenantId)) {
            return true;
        }

        return Enrollment::where('course_id', $section->course_id)
            ->where('user_id', $user->id)
            ->whereIn('status', ['active', 'completed'])
            ->exists();
    }

    public function create(User $user): bool
    {
        return $this->isTenantStaff($user);
    }

    public function update(User $user, Section $section): bool
    {
        return $this->create($user) && $this->view($user, $section);
    }

    public function delete(User $user, Section $section): bool
    {
        return $this->update($user, $section);
    }
}
