<?php

namespace App\Policies;

use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\User;
use App\Policies\Concerns\ChecksTenantStaff;

class LessonPolicy
{
    use ChecksTenantStaff;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Lesson $lesson): bool
    {
        if ($user->is_super_admin) {
            return true;
        }

        if ($lesson->is_free_preview) {
            return $lesson->tenant_id === $this->tenantId();
        }

        $tenantId = $this->tenantId();

        if ($lesson->tenant_id !== $tenantId) {
            return false;
        }

        if ($this->isTenantStaff($user, $tenantId)) {
            return true;
        }

        $courseId = $lesson->section?->course_id;

        if (! $courseId) {
            return false;
        }

        return Enrollment::where('course_id', $courseId)
            ->where('user_id', $user->id)
            ->whereIn('status', ['active', 'completed'])
            ->exists();
    }

    public function create(User $user): bool
    {
        return $this->isTenantStaff($user);
    }

    public function update(User $user, Lesson $lesson): bool
    {
        return $this->create($user) && $this->view($user, $lesson);
    }

    public function delete(User $user, Lesson $lesson): bool
    {
        return $this->update($user, $lesson);
    }
}
