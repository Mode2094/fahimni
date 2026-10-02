<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'tenant' => $this->whenLoaded('tenant') ? ['id' => $this->tenant->id, 'name' => $this->tenant->name, 'slug' => $this->tenant->slug] : null,
            'currency' => $this->currency ?? 'JOD',
            'type' => $this->type,
            'amount' => $this->amount,
            'direction' => $this->direction,
            'balance_before' => $this->balance_before,
            'balance_after' => $this->balance_after,
            'reference_type' => $this->reference_type,
            'reference_id' => $this->reference_id,
            'description' => $this->description,
            'created_at' => $this->created_at,
        ];
    }
}
