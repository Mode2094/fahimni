<?php

namespace App\Policies;

use App\Models\Enrollment;
use App\Models\LessonContent;
use App\Models\User;
use App\Policies\Concerns\ChecksTenantStaff;
use App\Services\CourseAccessService;

class LessonContentPolicy
{
    use ChecksTenantStaff;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, LessonContent $content): bool
    {
        if ($user->is_super_admin) {
            return true;
        }

        $tenantId = $this->tenantId();

        if ($content->tenant_id !== $tenantId) {
            return false;
        }

        if ($this->isTenantStaff($user, $tenantId)) {
            return true;
        }

        $lesson = $content->lesson;

        if (! $lesson) {
            return false;
        }

        if ($lesson->is_free_preview) {
            return true;
        }

        $courseId = $lesson->section?->course_id;

        if (! $courseId) {
            return false;
        }

        $enrollment = Enrollment::where('course_id', $courseId)
            ->where('user_id', $user->id)
            ->whereIn('status', ['active', 'completed'])
            ->first();

        if (! $enrollment) {
            return false;
        }

        // Drip / expiry / publish-state enforcement shared with the video,
        // file and progress paths.
        return app(CourseAccessService::class)->lessonUnlocked($enrollment, $lesson);
    }

    public function create(User $user): bool
    {
        return $this->isTenantStaff($user);
    }

    public function update(User $user, LessonContent $content): bool
    {
        return $this->create($user) && $this->view($user, $content);
    }

    public function delete(User $user, LessonContent $content): bool
    {
        return $this->update($user, $content);
    }
}
