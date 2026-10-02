<?php

namespace App\Policies;

use App\Models\TenantSubscription;
use App\Models\User;
use App\Support\TenantContext;

class TenantSubscriptionPolicy
{
    private function inCurrentTenant(User $user, TenantSubscription $subscription): bool
    {
        if ($user->is_super_admin) {
            return true;
        }

        $tenantId = app(TenantContext::class)->current();

        return $tenantId !== null && $subscription->tenant_id === $tenantId;
    }

    public function viewAny(User $user): bool
    {
        if ($user->is_super_admin) {
            return true;
        }

        $tenantId = app(TenantContext::class)->current();

        return $tenantId !== null
            && $user->tenantPivots()
                ->where('tenant_id', $tenantId)
                ->where('status', 'active')
                ->exists();
    }

    public function view(User $user, TenantSubscription $subscription): bool
    {
        return $this->inCurrentTenant($user, $subscription);
    }

    public function create(User $user): bool
    {
        return $user->is_super_admin || $user->can('subscriptions.manage') || $this->viewAny($user);
    }

    public function update(User $user, TenantSubscription $subscription): bool
    {
        return $user->is_super_admin
            || $user->can('subscriptions.manage')
            || $this->inCurrentTenant($user, $subscription);
    }

    public function cancel(User $user, TenantSubscription $subscription): bool
    {
        return $this->update($user, $subscription);
    }
}
