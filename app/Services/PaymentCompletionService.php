<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\TenantSubscription;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\UuidV7;

/**
 * Applies a completed payment to its target (booking or subscription) with
 * full side effects: ledger entry, subscription activation + supersede,
 * invoice creation and teacher notification.
 *
 * Callers must only invoke this after transitioning the payment to
 * 'completed' via a guarded conditional update (WHERE status != 'completed')
 * so concurrent gateway callbacks can never double-apply.
 */
class PaymentCompletionService
{
    public function complete(Payment $payment): void
    {
        DB::transaction(function () use ($payment) {
            $this->createLedgerEntry($payment);

            // Coupon usage was reserved at initiate (see PaymentInitiate
            // Controller) — nothing to release here.

            if ($payment->booking_id) {
                $booking = Booking::find($payment->booking_id);

                if ($booking && in_array($booking->status, ['pending', 'confirmed'], true)) {
                    $booking->update(['status' => 'confirmed', 'payment_id' => $payment->id]);
                    $this->notifyBookingConfirmed($booking);
                }

                return;
            }

            $subscription = $payment->subscription;

            if (! $subscription) {
                return;
            }

            // Supersede other active/trialing subscriptions FIRST: the
            // partial unique index only allows one active/trialing row per
            // tenant, and activating ours before cancelling the old one
            // would violate it.
            TenantSubscription::where('tenant_id', $subscription->tenant_id)
                ->where('id', '!=', $subscription->id)
                ->whereIn('status', ['active', 'trialing'])
                ->update(['status' => 'cancelled', 'cancelled_at' => now(), 'auto_renew' => false]);

            // Renewals (auto-charge or a payment completing while the
            // subscription is still active) extend from the current period
            // end — never from "now" — so no paid time is lost. Fresh
            // checkouts have ends_at in the past or null and start today.
            $renewalBase = $subscription->ends_at && $subscription->ends_at->isFuture()
                ? $subscription->ends_at
                : now();

            $subscription->update([
                'status' => 'active',
                'trial_ends_at' => null,
                'ends_at' => $subscription->billing_cycle === 'yearly'
                    ? $renewalBase->copy()->addYear()
                    : $renewalBase->copy()->addMonth(),
            ]);

            Invoice::firstOrCreate(
                ['payment_id' => $payment->id],
                [
                    'id' => UuidV7::generate(),
                    'tenant_id' => $payment->tenant_id,
                    'tenant_subscription_id' => $subscription->id,
                    'invoice_number' => 'INV-'.strtoupper(bin2hex(random_bytes(6))),
                    'amount' => $payment->amount,
                    'tax_amount' => 0,
                    'total_amount' => $payment->amount,
                    'currency' => $payment->currency,
                    'status' => 'paid',
                    'due_date' => now(),
                    'paid_at' => now(),
                ]
            );
        });
    }

    private function createLedgerEntry(Payment $payment): void
    {
        // Guard against duplicate ledger rows from replayed callbacks.
        $exists = Transaction::where('reference_type', $payment->booking_id ? 'booking' : 'payment')
            ->where('reference_id', $payment->booking_id ?: $payment->id)
            ->where('type', 'payment')
            ->exists();

        if ($exists) {
            return;
        }

        Transaction::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $payment->tenant_id,
            'type' => 'payment',
            'amount' => $payment->amount,
            'direction' => 'in',
            'balance_before' => 0,
            'balance_after' => $payment->amount,
            'currency' => $payment->currency,
            'status' => 'completed',
            'reference_type' => $payment->booking_id ? 'booking' : 'payment',
            'reference_id' => $payment->booking_id ?: $payment->id,
        ]);
    }

    private function notifyBookingConfirmed(Booking $booking): void
    {
        try {
            $teacher = $booking->teacher;

            if ($teacher) {
                app(NotificationService::class)->notify(
                    notifiable: $teacher,
                    type: 'booking.confirmed',
                    title: 'Booking confirmed',
                    body: 'A paid booking was confirmed for your calendar.',
                    tenantId: $booking->tenant_id,
                    data: [
                        'booking_id' => $booking->id,
                        'student_name' => $booking->student?->name,
                        'starts_at' => $booking->starts_at?->toIso8601String(),
                    ],
                );
            }
        } catch (\Throwable) {
            // Notification failures must never roll back the payment.
        }
    }
}
