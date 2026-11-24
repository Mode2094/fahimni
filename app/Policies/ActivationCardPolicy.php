<?php

namespace App\Policies;

use App\Models\ActivationCard;
use App\Models\User;
use App\Policies\Concerns\ChecksTenantStaff;

class ActivationCardPolicy
{
    use ChecksTenantStaff;

    public function viewAny(User $user): bool
    {
        return $this->isTenantStaff($user);
    }

    public function view(User $user, ActivationCard $card): bool
    {
        if ($user->is_super_admin) {
            return true;
        }

        return $card->tenant_id === $this->tenantId()
            && $this->isTenantStaff($user, $card->tenant_id);
    }

    public function create(User $user): bool
    {
        return $this->isTenantStaff($user);
    }

    public function redeem(User $user, ActivationCard $card): bool
    {
        if ($user->is_super_admin || $user->hasRole('Teacher') || $card->tenant_id !== $this->tenantId()) {
            return false;
        }

        $membership = $user->tenantPivots()
            ->where('tenant_id', $card->tenant_id)
            ->first();

        // A valid card may establish a first student membership. Existing
        // disabled or non-student memberships must not be silently re-enabled.
        return ! $membership
            || ($membership->status === 'active' && $membership->role === 'student');
    }
}
