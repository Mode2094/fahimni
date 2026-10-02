<?php

namespace App\Http\Resources;

use App\Models\PlatformSetting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        [$amount, $currency] = PlatformSetting::convertAmount($this->amount, $this->currency);
        [$taxAmount] = PlatformSetting::convertAmount($this->tax_amount ?? 0, $this->currency);
        [$totalAmount] = PlatformSetting::convertAmount($this->total_amount ?? $this->amount, $this->currency);

        $subscription = $this->whenLoaded('subscription');
        $plan = $subscription?->plan;
        $payment = $this->whenLoaded('payment');

        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'tenant_name' => $this->whenLoaded('tenant', fn () => $this->tenant?->name),
            'subscription_id' => $this->tenant_subscription_id,
            'payment_id' => $this->payment_id,
            'invoice_number' => $this->invoice_number,
            'amount' => $amount,
            'tax_amount' => $taxAmount,
            'total_amount' => $totalAmount,
            'currency' => $currency,
            'original_currency' => $this->currency,
            'status' => $this->status,
            'due_date' => $this->due_date,
            'paid_at' => $this->paid_at,
            'invoice_pdf_url' => $this->invoice_pdf_url,
            'created_at' => $this->created_at,

            // ── تفاصيل الاشتراك ──────────────────────────
            'subscription' => $subscription ? [
                'id' => $subscription->id,
                'status' => $subscription->status,
                'billing_cycle' => $subscription->billing_cycle,
                'plan_id' => $plan?->id,
                'plan_name' => $plan?->name,
                'plan_monthly_price' => $plan?->monthly_price !== null ? (float) $plan->monthly_price : null,
                'plan_yearly_price' => $plan?->yearly_price !== null ? (float) $plan->yearly_price : null,
                'starts_at' => $subscription->starts_at,
                'ends_at' => $subscription->ends_at,
            ] : null,

            // ── تفاصيل الدفع ─────────────────────────────
            'payment' => $payment ? [
                'id' => $payment->id,
                'gateway' => $payment->gateway,
                'payment_method' => $payment->payment_method,
                'transaction_id' => $payment->transaction_id,
                'status' => $payment->status,
                'amount' => $payment->amount,
                'currency' => $payment->currency,
                'paid_at' => $payment->paid_at,
                'created_at' => $payment->created_at,
            ] : null,
        ];
    }
}
