<?php

namespace App\Policies;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;
use App\Policies\Concerns\ChecksTenantStaff;

class CoursePolicy
{
    use ChecksTenantStaff;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Course $course): bool
    {
        if ($user->is_super_admin) {
            return true;
        }

        $tenantId = $this->tenantId();

        if ($course->tenant_id !== $tenantId) {
            return false;
        }

        if ($this->isTenantStaff($user, $tenantId)) {
            return true;
        }

        return Enrollment::where('course_id', $course->id)
            ->where('user_id', $user->id)
            ->whereIn('status', ['active', 'completed'])
            ->exists();
    }

    public function create(User $user): bool
    {
        return $this->isTenantStaff($user);
    }

    public function update(User $user, Course $course): bool
    {
        return $this->create($user) && $this->view($user, $course);
    }

    public function delete(User $user, Course $course): bool
    {
        return $this->update($user, $course);
    }
}
