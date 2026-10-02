<?php

namespace App\Services;

use App\Http\Controllers\Api\TeacherSiteController;
use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Plans\PlanEngine;
use Illuminate\Support\Str;

/**
 * Central decision point for the official Student Mobile App feature.
 *
 * One feature, three gates — ALL of them must pass before a student app
 * can bind to a teacher:
 *   1. Platform toggle (Super Admin → Settings → Student App).
 *   2. Teacher plan feature `student_mobile_app` (Feature Engine).
 *   3. Active subscription (Subscription Engine).
 *
 * No hardcoded plan names, no hardcoded store URLs: everything is read
 * from the database through the existing engines.
 */
class StudentAppService
{
    public const FEATURE_KEY = 'student_mobile_app';

    public const SETTING_ENABLED = 'student_mobile_app';

    public const SETTING_ANDROID_URL = 'student_app_android_url';

    public const SETTING_IOS_URL = 'student_app_ios_url';

    public const DEEP_LINK_SCHEME = 'shahead';

    public function __construct(
        private readonly PlanEngine $engine,
    ) {}

    /**
     * Super Admin platform-wide toggle (DB-driven, cached).
     */
    public function platformEnabled(): bool
    {
        return PlatformSetting::bool(self::SETTING_ENABLED, false);
    }

    public function androidUrl(): ?string
    {
        return $this->cleanUrl((string) PlatformSetting::get(self::SETTING_ANDROID_URL, ''));
    }

    public function iosUrl(): ?string
    {
        return $this->cleanUrl((string) PlatformSetting::get(self::SETTING_IOS_URL, ''));
    }

    /**
     * Per-plan gate from the Feature Engine (never plan-name based).
     */
    public function planEnabled(string $tenantId): bool
    {
        return $this->engine->hasFeature($tenantId, self::FEATURE_KEY);
    }

    /**
     * Subscription Engine: an active (paid or trialing) subscription must
     * exist and still be within its validity window.
     */
    public function subscriptionActive(string $tenantId): bool
    {
        return $this->engine->snapshot($tenantId)['active'] === true;
    }

    /**
     * Combined status for a single teacher. `enabled` is true only when the
     * platform toggle, the plan feature AND the subscription all allow it.
     *
     * @return array{enabled: bool, platform_enabled: bool, plan_enabled: bool, subscription_active: bool}
     */
    public function status(Tenant $tenant): array
    {
        $platform = $this->platformEnabled();
        $plan = $this->planEnabled((string) $tenant->id);
        $subscription = $this->subscriptionActive((string) $tenant->id);

        return [
            'enabled' => $platform && $plan && $subscription && $tenant->status === 'active',
            'platform_enabled' => $platform,
            'plan_enabled' => $plan,
            'subscription_active' => $subscription,
            'teacher_active' => $tenant->status === 'active',
        ];
    }

    /**
     * Validate a teacher code against every backend rule. Returns the tenant
     * plus a machine-readable reason when validation fails (never trusts the
     * client beyond the code string itself).
     *
     * @return array{ok: bool, tenant: ?Tenant, reason: ?string}
     */
    public function validateTeacherCode(string $code): array
    {
        $tenant = app(TeacherCodeService::class)->findTenantByCode($code);

        if (! $tenant) {
            return ['ok' => false, 'tenant' => null, 'reason' => 'code_not_found'];
        }

        if ($tenant->status !== 'active' || $tenant->trashed()) {
            return ['ok' => false, 'tenant' => $tenant, 'reason' => 'teacher_inactive'];
        }

        $status = $this->status($tenant);

        if (! $status['platform_enabled']) {
            return ['ok' => false, 'tenant' => $tenant, 'reason' => 'feature_disabled'];
        }

        if (! $status['subscription_active']) {
            return ['ok' => false, 'tenant' => $tenant, 'reason' => 'subscription_inactive'];
        }

        if (! $status['plan_enabled']) {
            return ['ok' => false, 'tenant' => $tenant, 'reason' => 'plan_denied'];
        }

        return ['ok' => true, 'tenant' => $tenant, 'reason' => null];
    }

    /**
     * Deep link that opens the app directly on the Teacher Code flow.
     * The code still works manually — the deep link is an accelerator.
     */
    public function deepLink(Tenant $tenant): string
    {
        return self::DEEP_LINK_SCHEME.'://teacher/'.strtoupper((string) $tenant->teacher_code);
    }

    /**
     * Payload for the TEACHER dashboard/panel: code + copy + store links +
     * optional QR deep link. Store links are only present when the admin
     * actually entered them in the database.
     *
     * @return array<string, mixed>
     */
    public function teacherPayload(Tenant $tenant): array
    {
        $status = $this->status($tenant);
        $androidUrl = $this->androidUrl();
        $iosUrl = $this->iosUrl();

        return [
            'enabled' => $status['enabled'],
            'status' => $status,
            'teacher_code' => $tenant->teacher_code,
            'android_url' => $androidUrl,
            'ios_url' => $iosUrl,
            'deep_link' => $status['enabled'] ? $this->deepLink($tenant) : null,
            'qr_payload' => $status['enabled'] ? $this->deepLink($tenant) : null,
            'instructions' => $status['enabled']
                ? 'شارك كود المعلم مع طلابك. يفتحون التطبيق، يدخلون الكود ثم يسجلون دخولهم.'
                : 'تطبيق الطلاب — قيد التطوير.',
        ];
    }

    /**
     * Payload the MOBILE APP receives after a successful teacher-code
     * validation: the teacher context + branding + feature gates + store
     * links. Uses the exact same landing-page source as the web
     * (TeacherSiteController::sitePayload) — never a second content system.
     *
     * @return array<string, mixed>
     */
    public function appContext(Tenant $tenant): array
    {
        $setting = $tenant->settings;

        return [
            'teacher' => [
                'id' => $tenant->id,
                'slug' => $tenant->slug,
                'name' => $tenant->name,
                'teacher_code' => $tenant->teacher_code,
            ],
            'site' => TeacherSiteController::sitePayload(
                $tenant,
                $setting,
                $setting?->metadata['site_config'] ?? []
            ),
            'app' => [
                'enabled' => $this->status($tenant)['enabled'],
                'android_url' => $this->androidUrl(),
                'ios_url' => $this->iosUrl(),
                'deep_link' => $this->deepLink($tenant),
                'secure_playback' => $this->engine->hasFeature((string) $tenant->id, 'secure_playback_enabled'),
                'video_watermark' => $this->engine->hasFeature((string) $tenant->id, 'video_watermark'),
                'community_enabled' => $this->engine->hasFeature((string) $tenant->id, 'community_enabled'),
                'certificates_enabled' => $this->engine->hasFeature((string) $tenant->id, 'certificates_enabled'),
            ],
        ];
    }

    private function cleanUrl(string $value): ?string
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        if (Str::startsWith($trimmed, '/')) {
            return $trimmed;
        }

        $parsed = parse_url($trimmed);

        if ($parsed === false || ! isset($parsed['scheme'])) {
            return null;
        }

        return in_array(strtolower((string) $parsed['scheme']), ['http', 'https'], true)
            ? $trimmed
            : null;
    }
}
