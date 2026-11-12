<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubscriptionPlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'monthly_price' => $this->monthly_price,
            'yearly_price' => $this->yearly_price,
            'currency' => $this->currency,
            'trial_days' => $this->trial_days,
            'is_active' => $this->is_active,
            'is_public' => $this->is_public,
            'is_popular' => $this->is_popular,
            'sort_order' => $this->sort_order,
            'freemius_monthly_plan_id' => $this->metadata['freemius_monthly_plan_id'] ?? null,
            'freemius_yearly_plan_id' => $this->metadata['freemius_yearly_plan_id'] ?? null,
            'freemius_monthly_pricing_id' => $this->metadata['freemius_monthly_pricing_id'] ?? null,
            'freemius_yearly_pricing_id' => $this->metadata['freemius_yearly_pricing_id'] ?? null,
            'features' => PlanFeatureResource::collection($this->whenLoaded('features')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
