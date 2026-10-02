<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\UuidV7;

/**
 * Syncs a gateway-processed refund into the platform.
 *
 * Refunds are NOT initiated here: they are issued inside the gateway
 * dashboard (Click/wallet, PayTabs, PayPal…) and reported to the platform
 * via the refund webhook. This service applies the idempotent side effects:
 * payment → refunded, refund ledger entry, invoice cancellation and
 * subscription cancellation.
 *
 * Callers must only invoke this AFTER the payment was transitioned to
 * 'refunded' by a guarded conditional update (WHERE status = 'completed')
 * so replayed/duplicate webhooks can never double-apply.
 */
class RefundSyncService
{
    /**
     * @param  array<string, mixed>  $refundData  gateway refund details to store in gateway_data
     * @return array{status: string, already_refunded: bool, payment: Payment}
     */
    public function sync(Payment $payment, array $refundData = []): array
    {
        // Only money actually collected can be refunded, and only once.
        $affected = Payment::withoutTenant()
            ->where('id', $payment->id)
            ->where('status', 'completed')
            ->update(['status' => 'refunded']);

        $fresh = $payment->fresh();

        if ($affected !== 1) {
            return [
                'status' => (string) ($fresh?->status ?? $payment->status),
                'already_refunded' => $fresh?->status === 'refunded',
                'payment' => $fresh ?? $payment,
            ];
        }

        DB::transaction(function () use ($payment, $refundData) {
            $this->createLedgerEntry($payment);

            Invoice::where('payment_id', $payment->id)
                ->where('status', 'paid')
                ->update(['status' => 'cancelled']);

            $payment->subscription?->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'auto_renew' => false,
            ]);

            $payment->update([
                'gateway_data' => array_merge($payment->gateway_data ?? [], [
                    'refund' => $refundData,
                    'refund_synced_via_webhook' => true,
                ]),
            ]);
        });

        return [
            'status' => 'refunded',
            'already_refunded' => false,
            'payment' => $payment->fresh(),
        ];
    }

    private function createLedgerEntry(Payment $payment): void
    {
        // Guard against duplicate ledger rows from replayed webhooks.
        $exists = Transaction::where('reference_type', 'payment')
            ->where('reference_id', $payment->id)
            ->where('type', 'refund')
            ->exists();

        if ($exists) {
            return;
        }

        Transaction::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $payment->tenant_id,
            'type' => 'refund',
            'amount' => $payment->amount,
            'direction' => 'out',
            'balance_before' => 0,
            'balance_after' => 0,
            'currency' => $payment->currency,
            'status' => 'completed',
            'reference_type' => 'payment',
            'reference_id' => $payment->id,
            'description' => "Refund for payment {$payment->transaction_id}",
        ]);
    }
}
