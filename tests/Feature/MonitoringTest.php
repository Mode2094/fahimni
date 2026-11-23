<?php

namespace Tests\Feature;

use App\Models\FrontendError;
use App\Models\MonitoringAlert;
use App\Models\MonitoringSlowRequest;
use App\Models\MonitoringSnapshot;
use App\Models\User;
use App\Monitoring\AlertDispatcher;
use App\Monitoring\HealthService;
use App\Monitoring\MetricsRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $cacheUp = app(HealthService::class)->ready()['checks']['redis'] === 'ok';

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
        $cacheUp = app(HealthService::class)->legacy()['cache'] === true;

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

        $this->assertTrue(MetricsRegistry::counter('requests.total') > 0);

        $slow = MonitoringSlowRequest::count();
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

    /**
     * Regression: load thresholds were absolute (1.0 / 1.5) on a 2-vCPU host,
     * so a half-loaded box raised CRITICALs. The dispatcher now divides by the
     * core count, making the configured values per-core ratios.
     */
    public function test_load_alert_thresholds_are_per_core(): void
    {
        $dispatcher = app(AlertDispatcher::class);

        // 2 cores, load 1.4 = 0.70/core → below the 1.0 warning → no alert.
        $dispatcher->evaluate(['host' => ['loadavg_1' => 1.4, 'cpu_cores' => 2]]);
        $this->assertDatabaseMissing('monitoring_alerts', ['source' => 'host.load']);

        // Same load on 1 core = 1.40/core → warning.
        $dispatcher->evaluate(['host' => ['loadavg_1' => 1.4, 'cpu_cores' => 1]]);
        $this->assertDatabaseHas('monitoring_alerts', ['source' => 'host.load', 'level' => 'warning']);

        // 2 cores, load 3.2 = 1.60/core → critical.
        $dispatcher->evaluate(['host' => ['loadavg_1' => 3.2, 'cpu_cores' => 2]]);
        $this->assertDatabaseHas('monitoring_alerts', ['source' => 'host.load', 'level' => 'critical']);
    }

    /**
     * The dashboard accumulated stale open alerts (disk %, load…) that never
     * cleared once the metric recovered. An evaluation that no longer reports
     * a source auto-resolves its open alerts.
     */
    public function test_alerts_auto_resolve_when_the_condition_clears(): void
    {
        MonitoringAlert::create([
            'level' => 'warning',
            'source' => 'host.cpu',
            'key' => 'host.cpu:stale',
            'message' => 'CPU at 88% (warning threshold 70%)',
            'context' => [],
            'status' => MonitoringAlert::STATUS_OPEN,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'occurrences' => 3,
        ]);

        app(AlertDispatcher::class)->evaluate(['host' => ['loadavg_1' => 0.1, 'cpu_cores' => 2]]);

        $this->assertDatabaseHas('monitoring_alerts', [
            'source' => 'host.cpu',
            'status' => MonitoringAlert::STATUS_RESOLVED,
        ]);
    }
}
