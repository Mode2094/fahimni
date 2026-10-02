<?php

namespace App\Policies;

use App\Models\Enrollment;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\User;
use App\Policies\Concerns\ChecksTenantStaff;

class ExamPolicy
{
    use ChecksTenantStaff;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Exam $exam): bool
    {
        if ($user->is_super_admin) {
            return true;
        }

        if ($exam->tenant_id !== $this->tenantId()) {
            return false;
        }

        // Staff can inspect drafts; students only see published exams.
        if (! $this->isTenantStaff($user, $exam->tenant_id) && ! $exam->is_published) {
            return false;
        }

        return true;
    }

    public function viewQuestions(User $user, Exam $exam): bool
    {
        if ($user->is_super_admin) {
            return true;
        }

        return $exam->tenant_id === $this->tenantId()
            && $this->isTenantStaff($user, $exam->tenant_id);
    }

    public function create(User $user): bool
    {
        return $this->isTenantStaff($user);
    }

    public function update(User $user, Exam $exam): bool
    {
        return $this->create($user) && $this->view($user, $exam);
    }

    public function delete(User $user, Exam $exam): bool
    {
        return $this->update($user, $exam);
    }

    public function attempt(User $user, Exam $exam): bool
    {
        if (! $exam->is_published) {
            return false;
        }

        if ($exam->starts_at && now()->lt($exam->starts_at)) {
            return false;
        }

        if ($exam->ends_at && now()->gt($exam->ends_at)) {
            return false;
        }

        $tenantId = $this->tenantId();

        if ($exam->tenant_id !== $tenantId) {
            return false;
        }

        $enrolled = Enrollment::where('course_id', $exam->course_id)
            ->where('user_id', $user->id)
            ->whereIn('status', ['active', 'completed'])
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->exists();

        if (! $enrolled) {
            return false;
        }

        if ($exam->max_attempts > 0) {
            // Only finalized attempts count against the cap; expired
            // in-progress attempts are auto-submitted and never burn slots.
            $attempts = ExamAttempt::where('exam_id', $exam->id)
                ->where('user_id', $user->id)
                ->where('status', 'submitted')
                ->count();

            return $attempts < $exam->max_attempts;
        }

        return true;
    }
}
