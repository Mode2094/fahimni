<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'avatar_url' => $this->avatar_url,
            'is_super_admin' => $this->is_super_admin,
            'is_active' => $this->is_active,
            'locale' => $this->locale,
            'email_verified_at' => $this->email_verified_at,
            'last_login_at' => $this->last_login_at,
            'tenants' => TenantResource::collection($this->whenLoaded('tenants')),
            'roles' => $this->getRoleNames(),
            'permissions' => $this->getAllPermissions()->pluck('name'),
            'created_at' => $this->created_at,
        ];
    }
}
