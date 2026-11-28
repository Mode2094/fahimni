<?php

namespace App\Jobs;

use App\Models\ActivationCard;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Flip issued-but-unused activation cards to `expired` once their expiry date
 * has passed.
 *
 * Without this, a card with `expires_at` in the past kept the `generated`
 * status forever: the dashboard counted it as available and the course stayed
 * "activable" in the teacher's view, while redemption only rejected it lazily.
 *
 * Only `generated` cards are touched — a card a student already redeemed keeps
 * its `redeemed` status even if the expiry date passes, so no student loses
 * access they legitimately activated.
 */
class ExpireActivationCardsJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(): void
    {
        ActivationCard::withoutTenant()
            ->where('status', 'generated')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->update([
                'status' => 'expired',
                'updated_at' => now(),
            ]);
    }
}
