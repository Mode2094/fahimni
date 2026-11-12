<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PlatformSetting;
use App\Models\TenantSetting;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlatformSettingController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $settings = PlatformSetting::allFlattened();

        // لوجو المعلم (منصة المستأجر) يغلب لوجو المنصة العامة — عند طلب
        // يحمل X-Tenant-Id (المعلم، الطلاب التابعون له، صفحته العامة).
        // tenant_id عمود uuid في Postgres — أي قيمة غير UUID (مثلاً قديمة
        // معطوبة من localStorage) ترمي QueryException، لذا نتحقق قبل الاستعلام.
        $tenantId = $request->header('X-Tenant-Id');
        if ($tenantId && \Illuminate\Support\Str::isUuid($tenantId)) {
            $tenantSetting = TenantSetting::where('tenant_id', $tenantId)->first();
            if ($tenantSetting?->logo_url) {
                $settings['logo_url'] = $tenantSetting->logo_url;
            }
            if ($tenantSetting?->academy_name) {
                $settings['platform_name'] = $tenantSetting->academy_name;
            }
        }

        // المفاتيح السرية لا تُعاد أبداً عبر الـAPI — تُخزَّن مشفَّرة
        // (Crypt) ويخبر `*_set` الواجهةَ بوجودها فقط.

        $payload = [
            'platform_name' => $settings['platform_name'] ?? 'Tadreesly',
            'currency' => $settings['currency'] ?? 'JOD',
            'currency_rate' => (float) ($settings['currency_rate'] ?? 1),
            'default_locale' => $settings['default_locale'] ?? 'ar',
            'logo_url' => $settings['logo_url'] ?? null,
            'primary_color' => $settings['primary_color'] ?? '#2563eb',
            'notify_new_teacher' => (bool) ($settings['notify_new_teacher'] ?? true),
            'notify_payment' => (bool) ($settings['notify_payment'] ?? true),
            'notify_exam_submitted' => (bool) ($settings['notify_exam_submitted'] ?? true),
            'notify_low_storage' => (bool) ($settings['notify_low_storage'] ?? false),
            'registration_enabled' => (bool) ($settings['registration_enabled'] ?? true),
            'teacher_registration_enabled' => (bool) ($settings['teacher_registration_enabled'] ?? false),
            'maintenance_mode' => (bool) ($settings['maintenance_mode'] ?? false),
            'two_factor_enabled' => (bool) ($settings['two_factor_enabled'] ?? false),
            'captcha_enabled' => (bool) ($settings['captcha_enabled'] ?? false),
            'student_mobile_app' => (bool) ($settings['student_mobile_app'] ?? false),
            'student_app_hidden' => (bool) ($settings['student_app_hidden'] ?? false),
            'student_app_android_url' => $settings['student_app_android_url'] ?? '',
            'student_app_ios_url' => $settings['student_app_ios_url'] ?? '',
            'platform_domain' => $settings['platform_domain'] ?? 'lms.example.com',
            // ── Booking gateways (student ↔ teacher one-off payments) ──
            'paytabs_profile_id' => $settings['paytabs_profile_id'] ?? '',
            'paytabs_server_key' => '',
            'paytabs_server_key_set' => (bool) ($settings['paytabs_server_key'] ?? false),
            'paytabs_enabled' => (bool) ($settings['paytabs_enabled'] ?? false),
            'paytabs_test_mode' => (bool) ($settings['paytabs_test_mode'] ?? true),
            'paypal_client_id' => $settings['paypal_client_id'] ?? '',
            'paypal_secret' => '',
            'paypal_secret_set' => (bool) ($settings['paypal_secret'] ?? false),
            'paypal_enabled' => (bool) ($settings['paypal_enabled'] ?? false),
            'paypal_test_mode' => (bool) ($settings['paypal_test_mode'] ?? true),
            // ── Freemius (the ONLY platform-subscription gateway) ──
            // Secrets live in the environment only and are NEVER stored in
            // the database, so only a presence boolean is exposed.
            'freemius_enabled' => (bool) ($settings['freemius_enabled'] ?? config('services.freemius.enabled', false)),
            'freemius_product_id' => (string) ($settings['freemius_product_id'] ?? config('services.freemius.product_id', '')),
            'freemius_api_url' => (string) ($settings['freemius_api_url'] ?? config('services.freemius.api_url', 'https://api.freemius.com')),
            'freemius_checkout_url' => (string) ($settings['freemius_checkout_url'] ?? config('services.freemius.checkout_url', 'https://checkout.freemius.com')),
            'freemius_sandbox' => (bool) ($settings['freemius_sandbox'] ?? config('services.freemius.sandbox', false)),
            'freemius_api_token_set' => (bool) config('services.freemius.api_token', ''),
            'freemius_secret_key_set' => (bool) config('services.freemius.secret_key', ''),
            'freemius_public_key_set' => (bool) config('services.freemius.public_key', ''),
            'contact_email' => $settings['contact_email'] ?? 'support@tadreesly.com',
            'contact_whatsapp' => $settings['contact_whatsapp'] ?? '',
            'live_google_meet_enabled' => (bool) ($settings['live_google_meet_enabled'] ?? true),
            'live_default_provider' => (string) ($settings['live_default_provider'] ?? 'google_meet'),
        ];

        return response()->json(['settings' => $payload]);
    }

    public function update(Request $request): JsonResponse
    {
        if (! $request->user()->is_super_admin) {
            return response()->json(['message' => 'This action is unauthorized.'], 403);
        }

        $allowed = [
            'platform_name' => 'string|max:100',
            'currency' => 'string|max:10',
            'currency_rate' => 'numeric|min:0',
            'default_locale' => 'string|in:ar,en',
            'logo_url' => 'nullable|string|max:500',
            'primary_color' => 'nullable|string|max:20',
            'notify_new_teacher' => 'boolean',
            'notify_payment' => 'boolean',
            'notify_exam_submitted' => 'boolean',
            'notify_low_storage' => 'boolean',
            'registration_enabled' => 'boolean',
            'teacher_registration_enabled' => 'boolean',
            'maintenance_mode' => 'boolean',
            'two_factor_enabled' => 'boolean',
            'captcha_enabled' => 'boolean',
            'student_mobile_app' => 'boolean',
            'student_app_hidden' => 'boolean',
            'student_app_android_url' => 'nullable|string|max:500',
            'student_app_ios_url' => 'nullable|string|max:500',
            'platform_domain' => 'nullable|string|max:190',
            'paytabs_profile_id' => 'nullable|string|max:100',
            'paytabs_server_key' => 'nullable|string|max:500',
            'paytabs_enabled' => 'boolean',
            'paytabs_test_mode' => 'boolean',
            'paypal_client_id' => 'nullable|string|max:500',
            'paypal_secret' => 'nullable|string|max:500',
            'paypal_enabled' => 'boolean',
            'paypal_test_mode' => 'boolean',
            'freemius_enabled' => 'boolean',
            'freemius_product_id' => 'nullable|string|max:100',
            'freemius_api_url' => 'nullable|string|max:190',
            'freemius_checkout_url' => 'nullable|string|max:190',
            'freemius_sandbox' => 'boolean',
            'contact_email' => 'nullable|string|max:190',
            'contact_whatsapp' => 'nullable|string|max:30',
            'live_google_meet_enabled' => 'boolean',
            'live_default_provider' => 'string|in:google_meet',
        ];

        $validated = $request->validate($allowed);

        $secretKeys = PlatformSetting::secretKeys();

        foreach ($validated as $key => $value) {
            // المفاتيح السرية لا تُمسح بإرسالها فارغة — تُحدَّث فقط عند إدخال قيمة جديدة
            if (in_array($key, $secretKeys, true) && trim((string) $value) === '') {
                continue;
            }

            // الأسرار تُخزَّن مشفَّرة (enc:v1:) — القراءة تدعم القيم القديمة.
            if (in_array($key, $secretKeys, true)) {
                PlatformSetting::setSecret($key, $value);

                continue;
            }

            PlatformSetting::set($key, $value);
        }

        AuditLogger::log($request, 'settings.updated', 'platform', null, ['keys' => array_keys($validated)]);

        return response()->json([
            'message' => 'Settings updated.',
            'settings' => $this->show($request)->getData(true)['settings'],
        ]);
    }
}
