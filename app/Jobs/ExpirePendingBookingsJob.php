<?php

namespace App\Jobs;

use App\Models\Booking;
use App\Models\Coupon;
use App\Models\Payment;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Expire abandoned pending bookings (and their unpaid pending payments).
 *
 * A pending booking holds a slot on the teacher's calendar: students could
 * otherwise squat every slot at zero cost. Free bookings expire after 15
 * minutes; paid bookings keep their slot for 60 minutes so the payer can
 * complete checkout. Coupon reservations tied to abandoned payments are
 * released as well.
 */
class ExpirePendingBookingsJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(): void
    {
        $now = now();

        // Free bookings: 15 minutes to confirm.
        $free = Booking::where('status', 'pending')
            ->where('price', '<=', 0)
            ->where('created_at', '<', $now->copy()->subMinutes(15))
            ->get();

        // Paid bookings: 60 minutes to pay.
        $paid = Booking::where('status', 'pending')
            ->where('price', '>', 0)
            ->where('created_at', '<', $now->copy()->subMinutes(60))
            ->get();

        foreach ($free->merge($paid) as $booking) {
            if ($booking->payment_id) {
                $payment = Payment::where('id', $booking->payment_id)
                    ->where('status', 'pending')
                    ->first();

                if ($payment) {
                    $this->releaseCoupon($payment);
                    $payment->update(['status' => 'failed']);
                }
            }

            $booking->update(['status' => 'cancelled', 'cancelled_at' => $now]);
        }
    }

    private function releaseCoupon(Payment $payment): void
    {
        $data = $payment->gateway_data ?? [];

        if (empty($data['coupon_id']) || empty($data['coupon_reserved'])) {
            return;
        }

        Coupon::where('id', $data['coupon_id'])->decrement('used_count', 1);

        $data['coupon_reserved'] = false;
        $payment->update(['gateway_data' => $data]);
    }
}
