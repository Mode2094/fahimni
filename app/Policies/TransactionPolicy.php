<?php

namespace App\Policies;

use App\Models\Transaction;
use App\Models\User;
use App\Policies\Concerns\ChecksTenantStaff;

class TransactionPolicy
{
    use ChecksTenantStaff;

    public function viewAny(User $user): bool
    {
        return $this->isTenantStaff($user);
    }

    public function view(User $user, Transaction $transaction): bool
    {
        if ($user->is_super_admin) {
            return true;
        }

        return $this->isTenantStaff($user, $transaction->tenant_id);
    }
}
