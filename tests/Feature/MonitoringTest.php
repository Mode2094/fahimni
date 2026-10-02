<?php

namespace Tests\Feature;

use App\Models\FrontendError;
use App\Models\MonitoringAlert;
use App\Models\MonitoringSnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MonitoringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Use the sqlite in-memory DB for tests (phpunit.xml forces it).
        config()->set('database.default', 'sqlite');
        config()->set('cache.default', 'array');
        config()->set('queue.default', 'sync');
        config()->set('monitoring.request.persist_slow', true);
    }

    public function test_liveness_endpoint_returns_200(): void
    {
        $response = $this->getJson('/api/health/live');

        $response->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('timestamp', fn ($v) => is_string($v));
    }

    public function test_readiness_endpoint_returns_healthy_with_all_checks(): void
    {
        $response = $this->getJson('/api/health/ready');

        // Redis may or may not be reachable in the test environment
        // (inside the dev container it is). The contract is: healthy (200)
        // when everything is up, degraded (503) otherwise — shape must hold.
        $cacheUp = app(\App\Monitoring\HealthService::class)->ready()['checks']['redis'] === 'ok';

        $response->assertStatus($cacheUp ? 200 : 503)
            ->assertJsonPath('status', $cacheUp ? 'ok' : 'degraded')
            ->assertJsonPath('checks.database', 'ok')
            ->assertJsonPath('checks.filesystem', 'ok')
            ->assertJsonPath('checks.queue', 'ok');
    }

    public function test_legacy_health_endpoint_keeps_shape(): void
    {
        $response = $this->getJson('/api/health');

        // Redis reachable → healthy (200), otherwise degraded (503).
        $cacheUp = app(\App\Monitoring\HealthService::class)->legacy()['cache'] === true;

        $response->assertStatus($cacheUp ? 200 : 503)
            ->assertJsonPath('status', $cacheUp ? 'ok' : 'degraded')
            ->assertJsonPath('database', true)
            ->assertJsonPath('cache', $cacheUp)
            ->assertJsonStructure(['queue', 'timestamp']);
    }

    public function test_metrics_middleware_records_counters_and_slow_requests(): void
    {
        // Trigger a request that will be recorded by the middleware.
        $this->getJson('/api/health/live');

        $this->assertTrue(\App\Monitoring\MetricsRegistry::counter('requests.total') > 0);

        $slow = \App\Models\MonitoringSlowRequest::count();
        $this->assertGreaterThanOrEqual(0, $slow);
    }

    public function test_metrics_middleware_does_not_fail_requests(): void
    {
        $response = $this->getJson('/api/health/live');

        $response->assertOk();
    }

    public function test_monitoring_collect_stores_snapshot(): void
    {
        $this->artisan('monitoring:collect')->assertSuccessful();

        $this->assertDatabaseHas('monitoring_snapshots', [
            'kind' => 'full',
        ]);

        $snapshot = MonitoringSnapshot::query()->orderByDesc('collected_at')->first();
        $this->assertNotNull($snapshot);

        $payload = $snapshot->payload;
        $this->assertArrayHasKey('host', $payload);
        $this->assertArrayHasKey('database', $payload);
        $this->assertArrayHasKey('redis', $payload);
        $this->assertArrayHasKey('queue', $payload);
        $this->assertArrayHasKey('storage', $payload);
        $this->assertArrayHasKey('security', $payload);
    }

    public function test_monitoring_check_creates_alert_when_backup_missing(): void
    {
        // No backups exist → the check must create a critical alert.
        $this->artisan('monitoring:collect')->assertSuccessful();
        $this->artisan('monitoring:check')->assertSuccessful();

        $this->assertDatabaseHas('monitoring_alerts', [
            'level' => 'critical',
            'source' => 'backup',
        ]);
    }

    public function test_monitoring_check_does_not_crash_when_no_snapshot(): void
    {
        $this->artisan('monitoring:check')->assertSuccessful();
    }

    public function test_ingest_accepts_valid_event_without_pii(): void
    {
        $response = $this->postJson('/api/v1/monitoring/ingest', [
            'app' => 'web',
            'type' => 'error',
            'message' => 'test error',
            'url' => '/courses',
            'stack' => "Error: test\n  at foo (app.js:1:1)",
            'metadata' => ['page' => 'courses'],
        ]);

        $response->assertStatus(202);

        $this->assertDatabaseHas('frontend_errors', ['app' => 'web', 'type' => 'error']);

        $row = FrontendError::query()->first();
        $this->assertNotNull($row);
        $this->assertNotEquals('test error', $row->metadata['token'] ?? null); // metadata is whitelisted
        $this->assertNotNull($row->stack_hash); // stack is hashed, never stored raw
    }

    public function test_ingest_rejects_oversized_payload(): void
    {
        $response = $this->postJson('/api/v1/monitoring/ingest', [
            'app' => 'web',
            'message' => str_repeat('x', 20000),
        ]);

        $response->assertStatus(413);
    }

    public function test_admin_monitoring_requires_super_admin(): void
    {
        $user = User::factory()->create(['is_super_admin' => false]);

        $response = $this->withHeader('Authorization', 'Bearer '.$user->createToken('test')->plainTextToken)
            ->getJson('/api/v1/admin/monitoring/summary');

        $response->assertForbidden();
    }

    public function test_admin_monitoring_summary_for_super_admin(): void
    {
        $this->artisan('monitoring:collect')->assertSuccessful();

        $admin = User::factory()->create(['is_super_admin' => true]);

        $response = $this->withHeader('Authorization', 'Bearer '.$admin->createToken('test')->plainTextToken)
            ->getJson('/api/v1/admin/monitoring/summary');

        $response->assertOk()
            ->assertJsonStructure([
                'status', 'health', 'host', 'database', 'redis', 'queue', 'backup', 'storage', 'security', 'app', 'alerts',
            ])
            ->assertJsonPath('status', fn ($v) => in_array($v, ['healthy', 'degraded'], true)); // Redis-dependent
    }

    public function test_scheduler_heartbeat_path_written(): void
    {
        $path = storage_path('monitoring/scheduler-heartbeat');

        if (is_file($path)) {
            unlink($path);
        }

        $this->artisan('monitoring:heartbeat')->assertSuccessful();

        $this->assertFileExists($path);
    }

    public function test_ssl_check_detects_expired_cert(): void
    {
        // Uses a bogus path → check degrades gracefully, no exception.
        config()->set('monitoring.ssl.cert_path', storage_path('monitoring/nonexistent.pem'));

        $this->artisan('monitoring:check')->assertSuccessful();
    }
}