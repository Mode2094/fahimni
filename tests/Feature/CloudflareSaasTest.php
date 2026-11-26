<?php

namespace Tests\Feature;

use App\Models\PlatformSetting;
use App\Models\TenantSetting;
use App\Services\CloudflareSaasService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Uid\UuidV7;

/**
 * Cloudflare for SaaS custom hostnames — the permanent custom-domain path.
 *
 *   • connect() registers a Cloudflare custom hostname and stores its id,
 *   • verify() trusts Cloudflare's hostname + certificate status (not our own
 *     DNS lookups) and only then marks the domain servable,
 *   • the teacher is told to point a CNAME at the platform's CNAME target,
 *   • disconnect() removes the Cloudflare hostname,
 *   • ensureSetup() creates the proxied fallback origin + CNAME target.
 */
class CloudflareSaasTest extends TenantTestCase
{
    private function enableCloudflare(string $zone = 'zone123', string $target = 'customers.fahimni.me'): void
    {
        config([
            'services.cloudflare.api_token' => 'test-token',
            'services.cloudflare.zone_id' => $zone,
            'services.cloudflare.zone_name' => 'fahimni.me',
            'services.cloudflare.origin_ip' => '203.0.113.10',
            'services.cloudflare.fallback_origin' => 'proxy-fallback.fahimni.me',
            'services.cloudflare.cname_target' => $target,
            'services.cloudflare.enabled' => true,
        ]);

        PlatformSetting::forgetCache();
    }

    private function fakeCloudflare(bool $active = true): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();
            $method = $request->method();

            if ($method === 'POST' && str_ends_with($url, '/custom_hostnames')) {
                return Http::response(['success' => true, 'result' => [
                    'id' => 'ch_123',
                    'hostname' => 'brand-shop.com',
                    'status' => 'pending',
                    'ssl' => ['status' => 'pending_validation', 'validation_records' => []],
                    'ownership_verification' => ['type' => 'txt', 'name' => '_cf-custom-hostname.brand-shop.com', 'value' => 'own-token'],
                ]], 200);
            }

            if ($method === 'GET' && preg_match('#/custom_hostnames/([^/?]+)$#', $url, $m)) {
                $ready = str_contains($url, 'ch_') ? $this->activeHostname() : ['status' => 'pending', 'ssl' => ['status' => 'pending_validation', 'validation_records' => []]];

                return Http::response(['success' => true, 'result' => array_merge([
                    'id' => $m[1],
                    'hostname' => 'brand-shop.com',
                ], $ready)], 200);
            }

            if ($method === 'DELETE' && str_contains($url, '/custom_hostnames/')) {
                return Http::response(['success' => true, 'result' => []], 200);
            }

            return Http::response(['success' => false, 'errors' => [['message' => 'unexpected call']]], 404);
        });
    }

    private function activeHostname(): array
    {
        return ['status' => 'active', 'ssl' => ['status' => 'active', 'validation_records' => []]];
    }

    private function teacherFor(\App\Models\Tenant $tenant): \App\Models\User
    {
        $this->attachSubscription($tenant);
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');

        return $teacher;
    }

    public function test_connect_registers_a_cloudflare_custom_hostname_and_uses_the_cname_target(): void
    {
        $this->enableCloudflare();
        $this->fakeCloudflare();

        $tenant = $this->makeTenant();
        $teacher = $this->teacherFor($tenant);

        $res = $this->putJson('/api/v1/teacher/domain', ['domain' => 'brand-shop.com'], $this->authHeaders($teacher, $tenant));
        $res->assertOk();

        $setting = TenantSetting::withoutTenant()->where('tenant_id', $tenant->id)->first();
        $this->assertNotNull($setting);
        $this->assertSame('brand-shop.com', $setting->custom_domain);
        $this->assertSame('ch_123', $setting->metadata['cf_hostname_id'] ?? null);

        $res->assertJsonPath('dns_instructions.mode', 'cloudflare_saas')
            ->assertJsonPath('dns_instructions.cname_value', 'customers.fahimni.me');

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/custom_hostnames'));
    }

    public function test_verify_marks_active_only_when_hostname_and_certificate_are_active(): void
    {
        $this->enableCloudflare();
        $this->fakeCloudflare(true);

        $tenant = $this->makeTenant();
        $teacher = $this->teacherFor($tenant);

        TenantSetting::withoutTenant()->create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenant->id,
            'custom_domain' => 'brand-shop.com',
            'metadata' => ['domain_verified' => false, 'cf_hostname_id' => 'ch_123'],
        ]);

        $this->postJson('/api/v1/teacher/domain/verify', [], $this->authHeaders($teacher, $tenant))
            ->assertOk()
            ->assertJsonPath('verified', true);

        $setting = TenantSetting::withoutTenant()->where('tenant_id', $tenant->id)->first();
        $this->assertTrue((bool) $setting->metadata['domain_verified']);
        $this->assertSame('active', $setting->metadata['cf_status']);
        $this->assertSame('active', $setting->metadata['cf_ssl_status']);

        // Only now is the domain actually served / trusted by the edge gate.
        $this->getJson('/api/v1/public/tls/ask?domain=brand-shop.com')->assertOk();
        $this->getJson('/api/v1/public/domain/brand-shop.com')->assertOk();
    }

    public function test_verify_stays_pending_while_the_certificate_is_still_issuing(): void
    {
        $this->enableCloudflare();

        Http::fake(function (Request $request) {
            if ($request->method() === 'GET' && str_contains($request->url(), '/custom_hostnames/')) {
                return Http::response(['success' => true, 'result' => [
                    'id' => 'ch_123',
                    'hostname' => 'brand-shop.com',
                    'status' => 'active',
                    'ssl' => ['status' => 'pending_issuance', 'validation_records' => []],
                ]], 200);
            }

            return Http::response(['success' => false, 'errors' => [['message' => 'unexpected']]], 404);
        });

        $tenant = $this->makeTenant();
        $teacher = $this->teacherFor($tenant);

        TenantSetting::withoutTenant()->create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenant->id,
            'custom_domain' => 'brand-shop.com',
            'metadata' => ['domain_verified' => false, 'cf_hostname_id' => 'ch_123'],
        ]);

        $this->postJson('/api/v1/teacher/domain/verify', [], $this->authHeaders($teacher, $tenant))
            ->assertOk()
            ->assertJsonPath('verified', false);

        $setting = TenantSetting::withoutTenant()->where('tenant_id', $tenant->id)->first();
        $this->assertFalse((bool) $setting->metadata['domain_verified']);
        $this->assertNotEmpty($setting->metadata['domain_last_error']);
    }

    public function test_disconnect_deletes_the_cloudflare_hostname(): void
    {
        $this->enableCloudflare();
        $this->fakeCloudflare();

        $tenant = $this->makeTenant();
        $teacher = $this->teacherFor($tenant);

        TenantSetting::withoutTenant()->create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenant->id,
            'custom_domain' => 'brand-shop.com',
            'metadata' => ['domain_verified' => true, 'cf_hostname_id' => 'ch_123'],
        ]);

        $this->deleteJson('/api/v1/teacher/domain', [], $this->authHeaders($teacher, $tenant))->assertOk();

        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_contains($r->url(), '/custom_hostnames/ch_123'));

        $setting = TenantSetting::withoutTenant()->where('tenant_id', $tenant->id)->first();
        $this->assertNull($setting->custom_domain);
        $this->assertArrayNotHasKey('cf_hostname_id', $setting->metadata ?? []);
    }

    public function test_ensure_setup_creates_the_fallback_origin_and_cname_target(): void
    {
        $this->enableCloudflare();

        Http::fake(function (Request $request) {
            $url = $request->url();
            $method = $request->method();

            if ($method === 'GET' && str_contains($url, '/dns_records')) {
                return Http::response(['success' => true, 'result' => []], 200);
            }
            if ($method === 'POST' && str_contains($url, '/dns_records')) {
                return Http::response(['success' => true, 'result' => ['id' => 'rec_1']], 200);
            }
            if ($method === 'PUT' && str_contains($url, '/custom_hostnames/fallback_origin')) {
                return Http::response(['success' => true, 'result' => [
                    'origin' => 'proxy-fallback.fahimni.me',
                    'status' => 'pending_deployment',
                ]], 200);
            }

            return Http::response(['success' => false, 'errors' => [['message' => 'unexpected']]], 404);
        });

        $result = CloudflareSaasService::ensureSetup();

        $this->assertSame('customers.fahimni.me', $result['cname_target']);
        $this->assertNotEmpty($result['steps']);

        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && str_contains($r->url(), '/custom_hostnames/fallback_origin'));
    }

    public function test_instructions_fall_back_to_direct_mode_when_cloudflare_is_disabled(): void
    {
        config(['services.cloudflare.api_token' => '', 'services.cloudflare.zone_id' => '']);
        PlatformSetting::forgetCache();

        $dns = \App\Http\Controllers\Api\TeacherDomainController::instructionsFor('brand-shop.com', 'fahimni.me');

        $this->assertSame('direct', $dns['mode']);
        $this->assertSame('www', $dns['cname_host']);
        $this->assertSame('fahimni.me', $dns['cname_value']);
    }
}
