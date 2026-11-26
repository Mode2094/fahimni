<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ManualSubscriptionOperationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'operation_type' => $this->operation_type,
            'status' => $this->status,
            'previous_plan' => $this->whenLoaded('previousPlan', fn () => $this->previousPlan ? [
                'id' => $this->previousPlan->id,
                'name' => $this->previousPlan->name,
            ] : null),
            'new_plan' => $this->whenLoaded('newPlan', fn () => $this->newPlan ? [
                'id' => $this->newPlan->id,
                'name' => $this->newPlan->name,
            ] : null),
            'previous_plan_id' => $this->previous_plan_id,
            'new_plan_id' => $this->new_plan_id,
            'billing_cycle' => $this->billing_cycle,
            'duration_days' => $this->duration_days,
            'previous_ends_at' => $this->previous_ends_at?->toIso8601String(),
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'amount' => $this->amount !== null ? (float) $this->amount : null,
            'currency' => $this->currency,
            'payment_method' => $this->payment_method,
            'payment_reference' => $this->payment_reference,
            'payment_date' => $this->payment_date?->toIso8601String(),
            'has_proof' => (bool) $this->proof_path,
            'is_free_grant' => (float) ($this->amount ?? 0) <= 0,
            'reason' => $this->reason,
            'performed_by_name' => $this->performed_by_name,
            'payment_id' => $this->payment_id,
            'subscription_id' => $this->tenant_subscription_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
