<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\Uid\UuidV7;

class TeacherSiteController extends Controller
{
    private function setting(Request $request): TenantSetting
    {
        $tenantId = $request->current_tenant->id;

        return TenantSetting::firstOrCreate(
            ['tenant_id' => $tenantId],
            ['id' => UuidV7::generate(), 'tenant_id' => $tenantId]
        );
    }

    private function ensureTeacher(Request $request): void
    {
        $pivot = $request->user()->tenantPivots()
            ->where('tenant_id', $request->current_tenant->id)
            ->first();

        abort_if(! $pivot || ! in_array($pivot->role, ['teacher', 'owner', 'admin'], true), 403, 'Teacher access required.');
    }

    public function show(Request $request): JsonResponse
    {
        $this->ensureTeacher($request);

        $setting = $this->setting($request);
        $tenant = Tenant::find($request->current_tenant->id);
        $config = $setting->metadata['site_config'] ?? [];

        return response()->json([
            'site' => $this->sitePayload($tenant, $setting, $config),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $this->ensureTeacher($request);

        // Basic site design (template, colors, hero, sections) is available
        // to every plan — it is a core "own your site" feature. Premium
        // branding (custom domain, white label) stays gated separately.
        $setting = $this->setting($request);

        $validated = $request->validate([
            'academy_name' => 'nullable|string|max:255',
            'logo_url' => 'nullable|string|max:500',
            'favicon_url' => 'nullable|string|max:500',
            'primary_color' => 'nullable|string|max:20|regex:/^#[0-9a-fA-F]{3,8}$/',
            'secondary_color' => 'nullable|string|max:20|regex:/^#[0-9a-fA-F]{3,8}$/',
            'accent_color' => 'nullable|string|max:20|regex:/^#[0-9a-fA-F]{3,8}$/',
            'site_config' => 'nullable|array',
        ]);

        // Image fields render on the PUBLIC site under the tenant's own
        // domain: only http(s) URLs are acceptable (javascript:/data: must
        // never reach a public page).
        foreach (['logo_url', 'favicon_url'] as $field) {
            if (! empty($validated[$field]) && ! $this->isSafeHttpUrl($validated[$field])) {
                return response()->json(['message' => ucfirst($field).' must be a valid http(s) URL.'], 422);
            }
        }

        if (isset($validated['site_config'])) {
            $validated['site_config'] = $this->sanitizeSiteConfig($validated['site_config']);
        }

        $setting->update(array_filter([
            'academy_name' => $validated['academy_name'] ?? null,
            'logo_url' => $validated['logo_url'] ?? null,
            'favicon_url' => $validated['favicon_url'] ?? null,
            'primary_color' => $validated['primary_color'] ?? null,
            'secondary_color' => $validated['secondary_color'] ?? null,
            'accent_color' => $validated['accent_color'] ?? null,
        ], fn ($v) => $v !== null));

        if (isset($validated['site_config'])) {
            $metadata = $setting->metadata ?? [];
            $metadata['site_config'] = $validated['site_config'];
            $setting->update(['metadata' => $metadata]);
        }

        $tenant = Tenant::find($request->current_tenant->id);
        $config = $setting->metadata['site_config'] ?? [];

        AuditLogger::log($request, 'site.design_updated', 'tenant', $tenant->id);

        return response()->json([
            'message' => 'Site design saved. Your page is live at /t/'.$tenant->slug,
            'site' => $this->sitePayload($tenant, $setting, $config),
        ]);
    }

    public static function sitePayload(?Tenant $tenant, ?TenantSetting $setting, array $config): array
    {
        $defaultSlides = [
            [
                'title' => 'ابنِ مستقبلك معنا',
                'subtitle' => 'كورسات احترافية بأسلوب مبسط، ابدأ رحلتك التعليمية اليوم',
                'image' => null,
                'button_text' => 'ابدأ الآن',
            ],
        ];

        return [
            'id' => $tenant?->id,
            'slug' => $tenant?->slug,
            'name' => $setting?->academy_name ?? $tenant?->name ?? 'منصتي',
            'email' => $tenant?->email,
            'phone' => $tenant?->phone,
            'logo_url' => $setting?->logo_url ?? null,
            'primary_color' => $setting?->primary_color ?? '#2563eb',
            'secondary_color' => $setting?->secondary_color ?? '#0f172a',
            'accent_color' => $setting?->accent_color ?? '#38bdf8',
            'site_config' => [
                'blocks' => $config['blocks'] ?? [],
                'template' => $config['template'] ?? 'modern-dark',
                'font' => $config['font'] ?? 'tajawal',
                'sections' => $config['sections'] ?? ['stats', 'about', 'courses', 'card', 'testimonials', 'faq', 'cta'],
                'badge_text' => $config['badge_text'] ?? 'أكاديمية تعليمية',
                'hero_slides' => $config['hero_slides'] ?? $defaultSlides,
                'show_stats' => $config['show_stats'] ?? true,
                'stats' => $config['stats'] ?? [
                    ['value' => '+500', 'label' => 'طالب'],
                    ['value' => '+40', 'label' => 'كورس'],
                    ['value' => '4.9', 'label' => 'تقييم'],
                    ['value' => '24/7', 'label' => 'دعم'],
                ],
                'show_about' => $config['show_about'] ?? false,
                'about_title' => $config['about_title'] ?? 'من نحن',
                'about_text' => $config['about_text'] ?? '',
                'about_image' => $config['about_image'] ?? null,
                'show_testimonials' => $config['show_testimonials'] ?? false,
                'testimonials' => $config['testimonials'] ?? [
                    ['name' => 'طالب', 'text' => 'تجربة رائعة!'],
                ],
                'show_faq' => $config['show_faq'] ?? false,
                'faq' => $config['faq'] ?? [
                    ['q' => 'كيف أبدأ؟', 'a' => 'سجل حساباً وابدأ فوراً'],
                ],
                'cta_title' => $config['cta_title'] ?? 'جاهز تبدأ رحلتك التعليمية؟',
                'cta_text' => $config['cta_text'] ?? 'سجل الآن وابدأ التعلم',
                'contact_whatsapp' => $config['contact_whatsapp'] ?? null,
                'contact_phone' => $config['contact_phone'] ?? $tenant?->phone,
                'contact_email' => $config['contact_email'] ?? $tenant?->email,
            ],
        ];
    }

    private function isSafeHttpUrl(string $url): bool
    {
        // المسارات النسبية الآمنة (شعار مرفوع محلياً): /uploads/... /storage/...
        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            $path = parse_url($url, PHP_URL_PATH) ?? $url;

            return str_starts_with($path, '/uploads/') || str_starts_with($path, '/storage/');
        }

        $parsed = parse_url($url);

        if ($parsed === false || ! isset($parsed['scheme'])) {
            return false;
        }

        return in_array(strtolower((string) $parsed['scheme']), ['http', 'https'], true);
    }

    /**
     * Strip dangerous payloads from the site-config JSON before it is
     * persisted and served on the tenant's public page:
     *  - every URL-ish field is restricted to http(s)
     *  - text fields are length-capped and tag-stripped
     */
    private function sanitizeSiteConfig(array $config): array
    {
        $maxText = 5000;

        $sanitize = function (mixed $value) use (&$sanitize, $maxText): mixed {
            if (is_array($value)) {
                return array_map($sanitize, $value);
            }

            if (! is_string($value)) {
                return $value;
            }

            $trimmed = trim($value);

            if ($trimmed === '') {
                return $trimmed;
            }

            // Scheme-bearing strings must be http(s) links.
            if (str_contains($trimmed, '://') || str_starts_with(strtolower($trimmed), 'data:')) {
                if (! $this->isSafeHttpUrl($trimmed)) {
                    return '';
                }
            }

            // Strip markup so text fields can never smuggle HTML/JS into
            // the public page.
            $plain = strip_tags($trimmed);

            return mb_substr($plain, 0, $maxText);
        };

        return $sanitize($config);
    }
}
