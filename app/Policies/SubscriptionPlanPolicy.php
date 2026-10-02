<?php

namespace App\Policies;

use App\Models\User;

class SubscriptionPlanPolicy
{
    public function viewAny(User $user): bool
    {
        // Every authenticated user must be able to browse plans (checkout,
        // pricing pages). CRUD remains admin-only via create/update/delete.
        return true;
    }

    public function view(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->is_super_admin || $user->can('plans.manage');
    }

    public function update(User $user): bool
    {
        return $user->is_super_admin || $user->can('plans.manage');
    }

    public function delete(User $user): bool
    {
        return $user->is_super_admin || $user->can('plans.manage');
    }
}
