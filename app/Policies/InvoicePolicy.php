<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\User;
use App\Policies\Concerns\ChecksTenantStaff;

class InvoicePolicy
{
    use ChecksTenantStaff;

    public function viewAny(User $user): bool
    {
        return $this->isTenantStaff($user);
    }

    public function view(User $user, Invoice $invoice): bool
    {
        if ($user->is_super_admin) {
            return true;
        }

        return $this->isTenantStaff($user, $invoice->tenant_id);
    }
}
