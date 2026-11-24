<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\TeacherDomainController;
use App\Models\TenantSetting;
use Symfony\Component\Uid\UuidV7;

/**
 * Custom teacher domains — the pieces that make a domain safe to connect:
 *   • the Caddy on-demand-TLS `ask` gate only ever blesses a VERIFIED domain,
 *   • lookup() serves the apex and its `www.` variant for the right tenant,
 *   • connect() stores the apex only and refuses a domain another tenant owns,
 *   • verification accepts both a CNAME and an apex A record.
 */
class TeacherDomainTest extends TenantTestCase
{
    private function setting(string $tenantId, ?string $domain, bool $verified = false): TenantSetting
    {
        return TenantSetting::withoutTenant()->create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenantId,
            'custom_domain' => $domain,
            'metadata' => $verified
                ? ['domain_verified' => true, 'domain_verified_at' => now()->toIso8601String()]
                : ['domain_verified' => false],
        ]);
    }

    public function test_tls_ask_only_blesses_a_verified_teacher_domain(): void
    {
        $verified = $this->makeTenant();
        $this->setting($verified->id, 'verified-teacher.com', true);

        $unverified = $this->makeTenant();
        $this->setting($unverified->id, 'pending-teacher.com', false);

        // Verified apex + its www variant → allowed.
        $this->getJson('/api/v1/public/tls/ask?domain=verified-teacher.com')->assertOk();
        $this->getJson('/api/v1/public/tls/ask?domain=www.verified-teacher.com')->assertOk();

        // Connected but NOT verified → refused.
        $this->getJson('/api/v1/public/tls/ask?domain=pending-teacher.com')->assertForbidden();
        $this->getJson('/api/v1/public/tls/ask?domain=www.pending-teacher.com')->assertForbidden();

        // Unknown / malformed → refused.
        $this->getJson('/api/v1/public/tls/ask?domain=attacker.example')->assertForbidden();
        $this->getJson('/api/v1/public/tls/ask?domain=')->assertForbidden();
        $this->getJson('/api/v1/public/tls/ask?domain=not a host')->assertForbidden();
    }

    public function test_tls_ask_allows_the_platform_and_a_live_subdomain(): void
    {
        // Production slugs are always normalised to lower-case
        // (TenantSlugService::normalize); the random test helper slug may not
        // be, so pin a deterministic one here.
        $tenant = $this->makeTenant();
        $tenant->update(['slug' => 'live-academy']);
        $platform = (string) \App\Models\PlatformSetting::get('platform_domain', 'lms.example.com');

        // The platform's own hosts are never blocked by the gate.
        $this->getJson('/api/v1/public/tls/ask?domain='.$platform)->assertOk();
        $this->getJson('/api/v1/public/tls/ask?domain=www.'.$platform)->assertOk();

        // A live teacher subdomain is allowed; an unknown one is not.
        $this->getJson('/api/v1/public/tls/ask?domain='.$tenant->slug.'.'.$platform)->assertOk();
        $this->getJson('/api/v1/public/tls/ask?domain='.strtoupper($tenant->slug).'.'.$platform)->assertOk();
        $this->getJson('/api/v1/public/tls/ask?domain=nobody-here.'.$platform)->assertForbidden();
    }

    public function test_a_deactivated_tenant_domain_is_no_longer_served(): void
    {
        $tenant = $this->makeTenant();
        $this->setting($tenant->id, 'suspended-teacher.com', true);
        $tenant->update(['status' => 'suspended']);

        $this->getJson('/api/v1/public/tls/ask?domain=suspended-teacher.com')->assertForbidden();
        $this->getJson('/api/v1/public/domain/suspended-teacher.com')->assertNotFound();
    }

    public function test_lookup_resolves_the_apex_and_its_www_variant(): void
    {
        $tenant = $this->makeTenant();
        $this->setting($tenant->id, 'brand-teacher.com', true);

        foreach (['brand-teacher.com', 'www.brand-teacher.com'] as $host) {
            $res = $this->getJson('/api/v1/public/domain/'.$host);
            $res->assertOk();
            $this->assertSame($tenant->id, $res->json('site.id'));
        }

        $this->getJson('/api/v1/public/domain/unknown-teacher.com')->assertNotFound();
    }

    public function test_connect_stores_the_apex_only_and_rejects_a_second_owner(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $this->attachSubscription($tenantA);
        $this->attachSubscription($tenantB);

        $teacherA = $this->makeUser();
        $teacherB = $this->makeUser();
        $this->attachToTenant($teacherA, $tenantA, 'teacher');
        $this->attachToTenant($teacherB, $tenantB, 'teacher');

        // `www.` is normalised away and stored as the apex.
        $this->putJson('/api/v1/teacher/domain', ['domain' => 'www.my-brand.com'],
            $this->authHeaders($teacherA, $tenantA))->assertOk();

        $this->assertDatabaseHas('tenant_settings', [
            'tenant_id' => $tenantA->id,
            'custom_domain' => 'my-brand.com',
        ]);
        $this->assertDatabaseMissing('tenant_settings', ['custom_domain' => 'www.my-brand.com']);

        // A second tenant cannot claim the same domain.
        $this->putJson('/api/v1/teacher/domain', ['domain' => 'my-brand.com'],
            $this->authHeaders($teacherB, $tenantB))->assertStatus(422);

        // Nor the platform's own domain.
        $platform = (string) \App\Models\PlatformSetting::get('platform_domain', 'lms.example.com');
        $this->putJson('/api/v1/teacher/domain', ['domain' => $platform],
            $this->authHeaders($teacherB, $tenantB))->assertStatus(422);
    }

    public function test_verification_accepts_a_cname_or_an_apex_a_record(): void
    {
        $platform = 'fahimni.me';

        // CNAME to the platform hostname (no DNS needed — IPs injected).
        $this->assertTrue(TeacherDomainController::recordsPointAtPlatform(
            ['CNAME → '.$platform], $platform, []
        ));

        // Apex A record resolving to one of the platform's own addresses.
        $this->assertTrue(TeacherDomainController::recordsPointAtPlatform(
            ['A → 203.0.113.9'], $platform, ['203.0.113.9']
        ));

        // A record for a DIFFERENT address is not proof of ownership.
        $this->assertFalse(TeacherDomainController::recordsPointAtPlatform(
            ['A → 203.0.113.9'], $platform, ['198.51.100.1']
        ));

        // Anything else is not proof of ownership.
        $this->assertFalse(TeacherDomainController::recordsPointAtPlatform(
            ['CNAME → somewhere-else.example', 'A → 203.0.113.7'], $platform, ['203.0.113.9']
        ));
        $this->assertFalse(TeacherDomainController::recordsPointAtPlatform([], $platform, []));
    }
}
