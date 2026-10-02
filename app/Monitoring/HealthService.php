<?php

namespace App\Monitoring;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;

/**
 * HealthService — liveness and readiness probes.
 *
 *  - Liveness  (LIVE):  is the process alive? No dependency checks.
 *  - Readiness (READY): is the app able to serve traffic? Checks DB, Redis,
 *    filesystem writability, queue infrastructure and critical config.
 *
 * Checks are deliberately shallow (SELECT 1 / ping / temp-file write) so a
 * busy load-balancer probe never adds measurable load to the database.
 * Results are never cached for readiness (monitors expect a fresh signal),
 * except the Horizon process probe which is cached for 30s to avoid spawning
 * a PHP process on every poll.
 */
class HealthService
{
    public function live(): array
    {
        return [
            'status' => 'ok',
            'timestamp' => now()->toIso8601String(),
        ];
    }

    public function ready(): array
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'redis' => $this->checkRedis(),
            'filesystem' => $this->checkFilesystem(),
            'queue' => $this->checkQueue(),
            'config' => $this->checkConfig(),
        ];

        $healthy = ! collect($checks)->contains(fn ($check) => $check !== 'ok');

        return [
            'status' => $healthy ? 'ok' : 'degraded',
            'checks' => $checks,
            'scheduler' => $this->schedulerHeartbeat(),
            'timestamp' => now()->toIso8601String(),
        ];
    }

    /** Backward-compatible aggregate for the existing /api/health endpoint. */
    public function legacy(): array
    {
        $ready = $this->ready();

        return [
            'status' => $ready['status'] === 'ok' ? 'ok' : 'degraded',
            'database' => $ready['checks']['database'] === 'ok',
            'cache' => $ready['checks']['redis'] === 'ok',
            'queue' => $ready['checks']['queue'] === 'ok',
            'scheduler' => $ready['scheduler']['status'],
            'timestamp' => now()->toIso8601String(),
        ];
    }

    private function checkDatabase(): string
    {
        try {
            DB::connection()->select('SELECT 1');

            return 'ok';
        } catch (\Throwable $e) {
            Log::channel('monitoring')->error('health.database_failed', ['error' => $e->getMessage()]);

            return 'failed';
        }
    }

    private function checkRedis(): string
    {
        try {
            Redis::connection()->ping();

            return 'ok';
        } catch (\Throwable $e) {
            Log::channel('monitoring')->error('health.redis_failed', ['error' => $e->getMessage()]);

            return 'failed';
        }
    }

    private function checkFilesystem(): string
    {
        try {
            $disk = Storage::disk('local');
            $probe = 'health-probe-'.bin2hex(random_bytes(4)).'.tmp';
            $disk->put($probe, 'ok');
            $read = $disk->get($probe) === 'ok';
            $disk->delete($probe);

            return $read ? 'ok' : 'failed';
        } catch (\Throwable $e) {
            Log::channel('monitoring')->error('health.filesystem_failed', ['error' => $e->getMessage()]);

            return 'failed';
        }
    }

    private function checkQueue(): string
    {
        $driver = config('queue.default', 'sync');

        if ($driver === 'sync') {
            return 'ok';
        }

        // Redis-backed queue: the broker itself must be reachable.
        if ($driver === 'redis') {
            try {
                Redis::connection()->ping();

                return 'ok';
            } catch (\Throwable $e) {
                Log::channel('monitoring')->error('health.queue_redis_failed', ['error' => $e->getMessage()]);

                return 'failed';
            }
        }

        return 'ok';
    }

    /**
     * Horizon liveness is probed by spawning `artisan horizon:status`. That is
     * comparatively expensive, so the result is cached for 30 seconds.
     */
    public function horizonStatus(): string
    {
        return Cache::remember('monitoring:horizon:status', 30, function () {
            try {
                $result = \Illuminate\Support\Facades\Process::timeout(5)
                    ->path(base_path())
                    ->run(['php', 'artisan', 'horizon:status']);

                return $result->exitCode() === 0 ? 'running' : 'stopped';
            } catch (\Throwable) {
                return 'unknown';
            }
        });
    }

    private function checkConfig(): string
    {
        // Production hard requirements — the app itself refuses to boot
        // without a signing key, so a missing key means broken config.
        if (app()->environment('production')) {
            $signingKey = (string) config('stream.signing_key', '');
            if (trim($signingKey) === '') {
                return 'misconfigured';
            }
        }

        if (config('app.env') === 'production' && config('app.debug')) {
            return 'misconfigured';
        }

        return 'ok';
    }

    private function schedulerHeartbeat(): array
    {
        $path = (string) config('monitoring.scheduler.heartbeat_path', storage_path('monitoring/scheduler-heartbeat'));
        $staleAfter = (int) config('monitoring.scheduler.stale_after_minutes', 5);

        if (! is_file($path)) {
            return ['status' => 'unknown', 'message' => 'no heartbeat file'];
        }

        $ageMinutes = (int) round((time() - (int) filemtime($path)) / 60);

        return [
            'status' => $ageMinutes <= $staleAfter ? 'ok' : 'stale',
            'age_minutes' => $ageMinutes,
        ];
    }
}