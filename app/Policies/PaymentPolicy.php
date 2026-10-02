<?php

namespace App\Policies;

use App\Models\Payment;
use App\Models\User;
use App\Policies\Concerns\ChecksTenantStaff;

class PaymentPolicy
{
    use ChecksTenantStaff;

    public function viewAny(User $user): bool
    {
        return $this->isTenantStaff($user);
    }

    public function view(User $user, Payment $payment): bool
    {
        if ($user->is_super_admin) {
            return true;
        }

        return $this->isTenantStaff($user, $payment->tenant_id);
    }

    public function create(User $user): bool
    {
        // Creating payments through the fake gateway is restricted to super
        // admins (dev/testing); tenants pay through the gateway-initiate flow.
        return $user->is_super_admin;
    }

    public function updateStatus(User $user, Payment $payment): bool
    {
        // Financial state changes are super-admin only. Tenant staff may never
        // mark their own payments as completed without the gateway's consent.
        return $user->is_super_admin;
    }
}
