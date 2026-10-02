<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PlanFeatureResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'plan_id' => $this->plan_id,
            'feature_key' => $this->feature_key,
            'feature_value' => $this->feature_value,
            'feature_type' => $this->feature_type,
            'sort_order' => $this->sort_order,
        ];
    }
}
