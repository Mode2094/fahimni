<?php

namespace App\Http\Requests\TenantSetting;

use App\Models\TenantSetting;
use Illuminate\Foundation\Http\FormRequest;

class TenantSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = $this->header('X-Tenant-Id');
        $platform = config('app.url');

        return [
            'academy_name' => 'nullable|string|max:255',
            'logo_url' => 'nullable|string|url|max:500',
            'favicon_url' => 'nullable|string|url|max:500',
            'primary_color' => 'nullable|string|max:7',
            'secondary_color' => 'nullable|string|max:7',
            'accent_color' => 'nullable|string|max:7',
            'custom_domain' => [
                'nullable',
                'string',
                'max:255',
                'regex:/^([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/i',
                function ($attribute, $value, $fail) use ($tenantId, $platform) {
                    $domain = strtolower(rtrim((string) $value, '.'));

                    if ($domain === '') {
                        return;
                    }

                    // Same rules as the dedicated domain-connect endpoint:
                    // the platform's own domain and domains claimed by other
                    // tenants are rejected.
                    $platformHost = strtolower(parse_url((string) $platform, PHP_URL_HOST) ?? '');
                    if ($platformHost !== '' && str_contains($domain, $platformHost)) {
                        $fail('This domain belongs to the platform itself.');

                        return;
                    }

                    $claimed = TenantSetting::where('custom_domain', $domain)
                        ->when($tenantId, fn ($q) => $q->where('tenant_id', '!=', $tenantId))
                        ->exists();

                    if ($claimed) {
                        $fail('This domain is already connected to another platform.');
                    }
                },
            ],
            'custom_css' => 'nullable|string',
            'custom_js' => 'nullable|string',
            'email_from_name' => 'nullable|string|max:255',
            'email_logo_url' => 'nullable|string|url|max:500',
            'terms_url' => 'nullable|string|url|max:500',
            'privacy_url' => 'nullable|string|url|max:500',
        ];
    }
}
