<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PlatformSetting;
use App\Plans\TeacherTrialProgram;
use App\Support\AuditLogger;
use App\Support\PlatformBrand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlatformSettingController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        // هوية منصة فهمني العامة مستقلة تمامًا عن هوية أي معلّم وعن الجلسة:
        // لا يُسمح لأي ترويسة (خصوصًا X-Tenant-Id) بتخصيص اسم المنصة أو شعارها
        // هنا. علامة كل معلّم تُخدَم من مصدرها المستقل والآمن فقط:
        //   • الموقع العلني للمعلّم  → GET /public/{slug} (بالـslug، بلا مصادقة)
        //   • لوحة المعلّم           → GET /teacher/site-design (TenantMiddleware)
        // وإلا فإن أي مستخدم يحمل tenant_id في localStorage (بعد الدخول عبر
        // Google مثلاً) كان يُلوّث اسم/شعار منصة فهمني في الهيدر والفوتر.
        $settings = PlatformSetting::allFlattened();

        $trialProgram = app(TeacherTrialProgram::class);

        // المفاتيح السرية لا تُعاد أبداً عبر الـAPI — تُخزَّن مشفَّرة
        // (Crypt) ويخبر `*_set` الواجهةَ بوجودها فقط.

        $payload = [
            'platform_name' => PlatformBrand::normalize($settings['platform_name'] ?? null),
            'currency' => $settings['currency'] ?? 'USD',
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
            // ── Custom domain target (Cloudflare for SaaS) ──
            // Centrally changeable so the target address never lives in code.
            'cloudflare_saas_enabled' => (bool) ($settings['cloudflare_saas_enabled'] ?? config('services.cloudflare.enabled', false)),
            'cloudflare_saas_configured' => \App\Services\CloudflareSaasService::configured(),
            'custom_domain_cname_target' => (string) ($settings['custom_domain_cname_target'] ?? config('services.cloudflare.cname_target', '')),
            'custom_domain_fallback_origin' => (string) ($settings['custom_domain_fallback_origin'] ?? config('services.cloudflare.fallback_origin', '')),
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
            'contact_email' => PlatformBrand::email($settings['contact_email'] ?? null),
            'contact_whatsapp' => $settings['contact_whatsapp'] ?? '',
            'live_google_meet_enabled' => (bool) ($settings['live_google_meet_enabled'] ?? true),
            'live_default_provider' => (string) ($settings['live_default_provider'] ?? 'google_meet'),
            // ── Analytics & tracking (public IDs only — never secrets) ──
            'analytics_enabled' => (bool) ($settings['analytics_enabled'] ?? true),
            'meta_pixel_id' => (string) ($settings['meta_pixel_id'] ?? ''),
            'meta_domain_verification' => (string) ($settings['meta_domain_verification'] ?? ''),
            'ga_measurement_id' => (string) ($settings['ga_measurement_id'] ?? ''),
            'gtm_container_id' => (string) ($settings['gtm_container_id'] ?? ''),
            // ── Teacher free trial: master switch + limited "first N" offer ──
            // The master switch is the single source of truth; the seat numbers
            // are read from the real DB counter, never a fake/local value.
            'teacher_trial_enabled' => (bool) ($settings[TeacherTrialProgram::KEY_ENABLED] ?? true),
            'teacher_trial_offer_enabled' => (bool) ($settings[TeacherTrialProgram::KEY_OFFER_ENABLED] ?? false),
            'teacher_trial_offer_seats' => (int) ($settings[TeacherTrialProgram::KEY_OFFER_SEATS] ?? 50),
            'teacher_trial_offer_text' => (string) ($settings[TeacherTrialProgram::KEY_OFFER_TEXT] ?? TeacherTrialProgram::DEFAULT_OFFER_TEXT),
            'teacher_trial_days' => $trialProgram->trialDays(),
            'teacher_trial_offer_text_rendered' => $trialProgram->renderOfferText(),
            'teacher_trial_offer_seats_used' => $trialProgram->seatsUsed(),
            'teacher_trial_offer_seats_remaining' => $trialProgram->seatsRemaining(),
            'trial_available' => $trialProgram->trialAvailable(),
            'trial_days' => $trialProgram->trialDays(),
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
            'cloudflare_saas_enabled' => 'boolean',
            'custom_domain_cname_target' => 'nullable|string|max:190',
            'custom_domain_fallback_origin' => 'nullable|string|max:190',
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
            'analytics_enabled' => 'boolean',
            'meta_pixel_id' => 'nullable|string|max:60',
            'meta_domain_verification' => 'nullable|string|max:120',
            'ga_measurement_id' => 'nullable|string|max:40',
            'gtm_container_id' => 'nullable|string|max:40',
            // Teacher free trial: master switch + limited-offer settings.
            'teacher_trial_enabled' => 'boolean',
            'teacher_trial_days' => 'integer|min:1|max:365',
            'teacher_trial_offer_enabled' => 'boolean',
            'teacher_trial_offer_seats' => 'integer|min:1|max:1000000',
            'teacher_trial_offer_text' => 'nullable|string|max:500',
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
