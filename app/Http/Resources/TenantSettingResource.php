<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TenantSettingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'academy_name' => $this->academy_name,
            'logo_url' => $this->logo_url,
            'favicon_url' => $this->favicon_url,
            'primary_color' => $this->primary_color,
            'secondary_color' => $this->secondary_color,
            'accent_color' => $this->accent_color,
            'custom_domain' => $this->custom_domain,
            'custom_css' => $this->custom_css,
            'custom_js' => $this->custom_js,
            'email_from_name' => $this->email_from_name,
            'email_logo_url' => $this->email_logo_url,
            'terms_url' => $this->terms_url,
            'privacy_url' => $this->privacy_url,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
