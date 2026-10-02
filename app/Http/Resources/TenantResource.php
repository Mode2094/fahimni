<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TenantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type ?? 'tutor',
            'slug' => $this->slug,
            'email' => $this->email,
            'phone' => $this->phone,
            'status' => $this->status,
            'locale' => $this->locale,
            'timezone' => $this->timezone,
            'max_students' => $this->max_students,
            'storage_limit_mb' => $this->storage_limit_mb,
            'settings' => new TenantSettingResource($this->whenLoaded('settings')),
            'subscriptions' => TenantSubscriptionResource::collection($this->whenLoaded('subscriptions')),
            'role' => $this->whenPivotLoaded('tenant_user', fn () => $this->pivot->role),
            'tenant_role' => $this->whenPivotLoaded('tenant_user', fn () => $this->pivot->tenant_role),
            'created_at' => $this->created_at,
        ];
    }
}
