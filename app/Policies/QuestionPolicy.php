<?php

namespace App\Policies;

use App\Models\Question;
use App\Models\User;
use App\Policies\Concerns\ChecksTenantStaff;

class QuestionPolicy
{
    use ChecksTenantStaff;

    public function viewAny(User $user): bool
    {
        return $this->isTenantStaff($user);
    }

    public function view(User $user, Question $question): bool
    {
        if ($user->is_super_admin) {
            return true;
        }

        return $question->tenant_id === $this->tenantId()
            && $this->isTenantStaff($user, $question->tenant_id);
    }

    public function create(User $user): bool
    {
        return $this->isTenantStaff($user);
    }

    public function update(User $user, Question $question): bool
    {
        return $this->create($user) && $this->view($user, $question);
    }

    public function delete(User $user, Question $question): bool
    {
        return $this->update($user, $question);
    }
}
