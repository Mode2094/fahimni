<?php

namespace App\Policies;

use App\Models\TenantSubscription;
use App\Models\User;
use App\Policies\Concerns\ChecksTenantStaff;

class TenantSubscriptionPolicy
{
    use ChecksTenantStaff;

    public function viewAny(User $user): bool
    {
        return $this->isTenantStaff($user) || $user->can('subscriptions.manage');
    }

    public function view(User $user, TenantSubscription $subscription): bool
    {
        // Billing details are staff-only. Any active tenant member (a student)
        // must never read the workspace's plan, cycle or dates.
        return $this->isTenantStaff($user, $subscription->tenant_id)
            || $user->can('subscriptions.manage');
    }

    public function create(User $user): bool
    {
        return $this->isTenantStaff($user) || $user->can('subscriptions.manage');
    }

    public function update(User $user, TenantSubscription $subscription): bool
    {
        return $this->isTenantStaff($user, $subscription->tenant_id)
            || $user->can('subscriptions.manage');
    }

    public function cancel(User $user, TenantSubscription $subscription): bool
    {
        return $this->update($user, $subscription);
    }
}
