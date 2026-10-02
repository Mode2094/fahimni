<?php

namespace App\Http\Resources;

use App\Models\PlatformSetting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        [$amount, $currency] = PlatformSetting::convertAmount($this->amount, $this->currency);

        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'subscription_id' => $this->tenant_subscription_id,
            'transaction_id' => $this->transaction_id,
            'gateway' => $this->gateway,
            'amount' => $amount,
            'currency' => $currency,
            'original_amount' => (float) $this->amount,
            'original_currency' => $this->currency,
            'status' => $this->status,
            'payment_method' => $this->payment_method,
            'paid_at' => $this->paid_at,
            'created_at' => $this->created_at,
        ];
    }
}
