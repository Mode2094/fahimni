<?php

namespace App\Jobs;

use App\Models\TeacherInvitation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;

class ExpireTeacherInvitationsJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(): void
    {
        TeacherInvitation::where('status', TeacherInvitation::STATUS_PENDING)
            ->where('expires_at', '<', now())
            ->update(['status' => TeacherInvitation::STATUS_EXPIRED]);
    }
}
