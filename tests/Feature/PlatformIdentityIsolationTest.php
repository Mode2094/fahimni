<?php

namespace Tests\Feature;

use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;

/**
 * هوية منصة فهمني العامة (الاسم/الشعار) مستقلة تمامًا عن هوية أي معلّم.
 *
 * علة إنتاجية خطيرة: كان GET /platform-settings يستبدل platform_name و logo_url
 * بقيم TenantSetting للمستأجر المذكور في ترويسة X-Tenant-Id. وبما أن الواجهة
 * ترسل tenant_id من localStorage بعد الدخول (Google مثلاً)، صارت هوية الموقع
 * العام تحمل اسم/شعار المعلّم في الهيدر والفوتر وحقوق النشر.
 *
 * هذه الاختبارات تقفل الخلل: ترويسة المستأجر لا تُغيّر هوية المنصة، وعلامة كل
 * معلّم تُخدَم من مصدرها المستقل فقط، ومع عزل تام بين المستأجرين.
 */
class PlatformIdentityIsolationTest extends TenantTestCase
{
    private function tenantWithBranding(string $academyName, ?string $logo = null): Tenant
    {
        $tenant = $this->makeTenant();

        TenantSetting::create([
            'tenant_id' => $tenant->id,
            'academy_name' => $academyName,
            'logo_url' => $logo,
        ]);

        return $tenant;
    }

    public function test_platform_identity_ignores_the_tenant_header(): void
    {
        PlatformSetting::set('platform_name', 'منصة فهمني');

        $teacher = $this->tenantWithBranding('MOHMMAD', 'https://cdn.example/mohmmad.png');

        $response = $this->getJson('/api/v1/platform-settings', [
            'Accept' => 'application/json',
            'X-Tenant-Id' => $teacher->id,
        ]);

        $response->assertOk();
        $response->assertJsonPath('settings.platform_name', 'منصة فهمني');
        $this->assertNotSame('MOHMMAD', $response->json('settings.platform_name'));
        // الشعار العام لا يُستبدل بشعار المعلّم.
        $this->assertEmpty($response->json('settings.logo_url'));
        $this->assertStringNotContainsString('mohmmad', strtolower($response->getContent()));
        $this->assertStringNotContainsString('MOHMMAD', $response->getContent());
    }

    public function test_platform_identity_cannot_be_overridden_by_any_tenant(): void
    {
        PlatformSetting::set('platform_name', 'منصة فهمني');

        $a = $this->tenantWithBranding('Tenant Alpha', 'https://cdn.example/alpha.png');
        $b = $this->tenantWithBranding('Tenant Beta', 'https://cdn.example/beta.png');

        foreach ([$a, $b] as $tenant) {
            $response = $this->getJson('/api/v1/platform-settings', [
                'Accept' => 'application/json',
                'X-Tenant-Id' => $tenant->id,
            ])->assertOk();

            $response->assertJsonPath('settings.platform_name', 'منصة فهمني');
            $this->assertEmpty($response->json('settings.logo_url'));
        }
    }

    public function test_platform_identity_is_official_without_any_header(): void
    {
        PlatformSetting::set('platform_name', 'منصة فهمني');

        $this->getJson('/api/v1/platform-settings')
            ->assertOk()
            ->assertJsonPath('settings.platform_name', 'منصة فهمني');
    }

    public function test_a_legacy_platform_name_is_normalised_to_the_official_brand(): void
    {
        PlatformSetting::set('platform_name', 'تدريسلي');

        $this->getJson('/api/v1/platform-settings')
            ->assertOk()
            ->assertJsonPath('settings.platform_name', 'منصة فهمني');
    }

    public function test_an_authenticated_member_with_a_tenant_header_still_sees_the_platform_identity(): void
    {
        PlatformSetting::set('platform_name', 'منصة فهمني');
        $tenant = $this->tenantWithBranding('MOHMMAD', 'https://cdn.example/mohmmad.png');

        $owner = $this->makeUser();
        $this->attachToTenant($owner, $tenant, 'owner');

        $this->getJson('/api/v1/platform-settings', $this->authHeaders($owner, $tenant))
            ->assertOk()
            ->assertJsonPath('settings.platform_name', 'منصة فهمني');
    }

    public function test_the_platform_settings_payload_never_leaks_secret_values(): void
    {
        PlatformSetting::setSecret('paypal_secret', 'must-not-leak');

        $response = $this->getJson('/api/v1/platform-settings')->assertOk();

        $this->assertStringNotContainsString('must-not-leak', $response->getContent());
        $this->assertSame('', $response->json('settings.paypal_secret'));
    }

    public function test_a_public_teacher_site_serves_only_its_own_branding(): void
    {
        $a = $this->tenantWithBranding('Acme A', 'https://cdn.example/a.png');
        $b = $this->tenantWithBranding('Acme B', 'https://cdn.example/b.png');

        $this->getJson("/api/v1/public/{$a->slug}")
            ->assertOk()
            ->assertJsonPath('site.name', 'Acme A')
            ->assertJsonPath('site.logo_url', 'https://cdn.example/a.png');

        $responseB = $this->getJson("/api/v1/public/{$b->slug}")->assertOk();
        $responseB->assertJsonPath('site.name', 'Acme B');
        $responseB->assertJsonPath('site.logo_url', 'https://cdn.example/b.png');
        $this->assertStringNotContainsString('Acme A', $responseB->getContent());
    }

    public function test_a_teacher_reads_their_own_site_branding(): void
    {
        $tenant = $this->tenantWithBranding('Acme A', 'https://cdn.example/a.png');
        $this->attachSubscription($tenant);

        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');

        $this->getJson('/api/v1/teacher/site-design', $this->authHeaders($teacher, $tenant))
            ->assertOk()
            ->assertJsonPath('site.name', 'Acme A');
    }

    public function test_a_teacher_cannot_read_another_tenants_branding(): void
    {
        $a = $this->tenantWithBranding('Acme A');
        $this->attachSubscription($a);
        $b = $this->tenantWithBranding('Acme B');
        $this->attachSubscription($b);

        $teacherA = $this->makeUser();
        $this->attachToTenant($teacherA, $a, 'teacher');

        $this->getJson('/api/v1/teacher/site-design', $this->authHeaders($teacherA, $b))
            ->assertStatus(403);
    }
}
