<?php

namespace App\Jobs\Media;

use App\Media\MediaQuotaService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class CleanupExpiredReservationsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function handle(MediaQuotaService $quota): void
    {
        $cleaned = $quota->cleanupExpiredReservations();

        Log::info('media.job.reservations_cleaned', ['count' => $cleaned]);
    }
}
