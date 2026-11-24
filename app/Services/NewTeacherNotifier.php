<?php

namespace App\Services;

use App\Jobs\NotifyAdminNewTeacherJob;
use App\Models\PlatformSetting;
use App\Models\User;

/**
 * Entry point for the "new teacher joined" concierge alert.
 *
 * Callers pass the freshly-registered teacher; this decides whether the alert
 * is enabled (the existing `notify_new_teacher` platform switch) and hands the
 * work to the queue. Callers invoke it *after* their provisioning transaction
 * has committed, so a rolled-back registration never emails the admin about a
 * teacher that does not exist.
 */
class NewTeacherNotifier
{
    public function announce(User $teacher, ?string $tenantId, bool $trialStarted): void
    {
        if (! PlatformSetting::bool('notify_new_teacher', true)) {
            return;
        }

        NotifyAdminNewTeacherJob::dispatch($teacher->id, $tenantId, $trialStarted);
    }
}
