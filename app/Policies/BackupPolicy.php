<?php

namespace App\Policies;

use App\Models\Backup;
use App\Models\User;

class BackupPolicy
{
    /**
     * Backups are a platform-wide concern. Only the super admin may touch
     * them — teachers, students and regular staff are always denied, even if
     * they somehow reach the routes.
     */
    public function before(?User $user, string $ability): ?bool
    {
        if (! $user) {
            return false;
        }

        return $user->is_super_admin ? true : false;
    }

    public function viewAny(User $user): bool
    {
        return $user->is_super_admin;
    }

    public function view(User $user, Backup $backup): bool
    {
        return $user->is_super_admin;
    }

    public function create(User $user): bool
    {
        return $user->is_super_admin;
    }

    public function download(User $user, Backup $backup): bool
    {
        return $user->is_super_admin && $backup->status === Backup::STATUS_COMPLETED;
    }

    public function verify(User $user, Backup $backup): bool
    {
        return $user->is_super_admin && $backup->status !== Backup::STATUS_DELETED;
    }

    public function delete(User $user, Backup $backup): bool
    {
        return $user->is_super_admin && $backup->status !== Backup::STATUS_DELETED;
    }

    public function manageSettings(User $user): bool
    {
        return $user->is_super_admin;
    }
}