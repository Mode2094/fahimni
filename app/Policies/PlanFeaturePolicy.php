<?php

namespace App\Policies;

use App\Models\User;

class PlanFeaturePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_super_admin || $user->can('features.manage');
    }

    public function create(User $user): bool
    {
        return $user->is_super_admin || $user->can('features.manage');
    }

    public function update(User $user): bool
    {
        return $user->is_super_admin || $user->can('features.manage');
    }

    public function delete(User $user): bool
    {
        return $user->is_super_admin || $user->can('features.manage');
    }
}
