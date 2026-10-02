<?php

namespace App\Monitoring;

use App\Models\Backup;
use App\Models\MonitoringSnapshot;
use App\Monitoring\MetricsRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Redis;

/**
 * MetricCollector — gathers a full infrastructure snapshot from inside the
 * application container.
 *
 * Everything degrades gracefully: a missing binary, unreadable file or an
 * unreachable service is reported as null/0 rather than throwing. Monitoring
 * must never break the application.
 *
 * No secrets are ever collected: only aggregate counts, percentages and
 * sizes. Certificate expiry is read from the public cert file (never the
 * private key).
 */
class MetricCollector
{
    public function collect(): array
    {
        $start = hrtime(true);

        $payload = [
            'collected_at' => now()->toIso8601String(),
            'host' => $this->hostMetrics(),
            'database' => $this->databaseMetrics(),
            'redis' => $this->redisMetrics(),
            'queue' => $this->queueMetrics(),
            'backup' => $this->backupMetrics(),
            'storage' => $this->storageMetrics(),
            'security' => $this->securityMetrics(),
            'app' => $this->appMetrics(),
        ];

        $duration = (int) round((hrtime(true) - $start) / 1_000_000);

        try {
            MonitoringSnapshot::create([
                'kind' => 'full',
                'payload' => $payload,
                'collected_at' => now(),
                'duration_ms' => $duration,
            ]);
        } catch (\Throwable $e) {
            Log::channel('monitoring')->error('monitoring.snapshot_persist_failed', ['error' => $e->getMessage()]);
        }

        return $payload;
    }

    // ─── Host ─────────────────────────────────────────────

    private function hostMetrics(): array
    {
        $cpu = $this->cpuPercent();
        $mem = $this->memoryMetrics();
        $disk = $this->diskMetrics();
        $load = $this->loadAverage();

        return [
            'cpu_percent' => $cpu,
            'loadavg_1' => $load['load1'] ?? null,
            'loadavg_5' => $load['load5'] ?? null,
            'loadavg_15' => $load['load15'] ?? null,
            'mem_total_mb' => $mem['total_mb'] ?? null,
            'mem_used_mb' => $mem['used_mb'] ?? null,
            'mem_available_mb' => $mem['available_mb'] ?? null,
            'mem_percent' => $mem['percent'] ?? null,
            'swap_total_mb' => $mem['swap_total_mb'] ?? null,
            'swap_used_mb' => $mem['swap_used_mb'] ?? null,
            'disk_root_total_mb' => $disk['root_total_mb'] ?? null,
            'disk_root_used_mb' => $disk['root_used_mb'] ?? null,
            'disk_root_percent' => $disk['root_percent'] ?? null,
            'inodes_root_percent' => $disk['inodes_percent'] ?? null,
            'uptime_seconds' => $this->uptime(),
        ];
    }

    private function cpuPercent(): ?float
    {
        $stat = @file_get_contents('/proc/stat');
        if ($stat === false) {
            return null;
        }

        $lines = explode("\n", trim($stat));
        $line = $lines[0] ?? '';
        $parts = preg_split('/\s+/', $line);
        array_shift($parts);

        $idle = (int) ($parts[3] ?? 0) + (int) ($parts[4] ?? 0);
        $total = array_sum(array_map('intval', $parts));

        $key = 'monitoring:prev_cpu';

        $prev = MetricsRegistry::get($key);
        MetricsRegistry::set($key, implode(':', [$total, $idle]), 300);

        if ($prev === null) {
            return 0.0; // first read — no delta yet
        }

        $prevParts = explode(':', (string) $prev);

        // Malformed/legacy cached value — treat like a first read.
        if (count($prevParts) < 2 || ! is_numeric($prevParts[0]) || ! is_numeric($prevParts[1])) {
            return 0.0;
        }

        [$prevTotal, $prevIdle] = array_map('intval', $prevParts);

        $dTotal = $total - $prevTotal;
        $dIdle = $idle - $prevIdle;

        if ($dTotal <= 0) {
            return 0.0;
        }

        return round((($dTotal - $dIdle) / $dTotal) * 100, 2);
    }

    private function memoryMetrics(): array
    {
        $info = @file_get_contents('/proc/meminfo');
        if ($info === false) {
            return [];
        }

        $get = function (string $key) use ($info): ?float {
            if (preg_match('/^'.$key.':\s+(\d+)\s*kB/m', $info, $m)) {
                return (int) $m[1] / 1024;
            }

            return null;
        };

        $total = $get('MemTotal');
        $available = $get('MemAvailable') ?? $get('MemFree');
        $swapTotal = $get('SwapTotal');
        $swapFree = $get('SwapFree');

        $used = $total !== null && $available !== null ? $total - $available : null;

        return [
            'total_mb' => $total !== null ? round($total) : null,
            'used_mb' => $used !== null ? round($used) : null,
            'available_mb' => $available !== null ? round($available) : null,
            'percent' => $total && $used !== null ? round(($used / $total) * 100, 2) : null,
            'swap_total_mb' => $swapTotal !== null ? round($swapTotal) : null,
            'swap_used_mb' => $swapTotal !== null && $swapFree !== null ? round($swapTotal - $swapFree) : null,
        ];
    }

    private function diskMetrics(): array
    {
        $result = $this->run(['df', '-B1', '/']);

        if ($result === null) {
            return [];
        }

        $lines = explode("\n", trim($result));
        $cols = preg_split('/\s+/', $lines[1] ?? '');

        $total = isset($cols[1]) ? (int) $cols[1] : null;
        $used = isset($cols[2]) ? (int) $cols[2] : null;

        $inodes = $this->run(['df', '-i', '/']);
        $inodePercent = null;
        if ($inodes !== null) {
            $inodeLines = explode("\n", trim($inodes));
            $inodeCols = preg_split('/\s+/', $inodeLines[1] ?? '');
            if (isset($inodeCols[4]) && str_contains($inodeCols[4], '%')) {
                $inodePercent = (int) rtrim($inodeCols[4], '%');
            }
        }

        return [
            'root_total_mb' => $total !== null ? (int) round($total / 1048576) : null,
            'root_used_mb' => $used !== null ? (int) round($used / 1048576) : null,
            'root_percent' => $total && $used !== null ? round(($used / $total) * 100, 2) : null,
            'inodes_percent' => $inodePercent,
        ];
    }

    private function loadAverage(): array
    {
        $load = @file_get_contents('/proc/loadavg');
        if ($load === false) {
            return [];
        }

        $parts = preg_split('/\s+/', trim($load));

        return [
            'load1' => isset($parts[0]) ? (float) $parts[0] : null,
            'load5' => isset($parts[1]) ? (float) $parts[1] : null,
            'load15' => isset($parts[2]) ? (float) $parts[2] : null,
        ];
    }

    private function uptime(): ?int
    {
        $uptime = @file_get_contents('/proc/uptime');
        if ($uptime === false) {
            return null;
        }

        return (int) explode(' ', trim($uptime))[0];
    }

    // ─── Database ─────────────────────────────────────────

    private function databaseMetrics(): array
    {
        if (config('database.default') !== 'pgsql') {
            return ['engine' => config('database.default', 'unknown')];
        }

        try {
            $activity = DB::select("SELECT state, count(*) AS count FROM pg_stat_activity WHERE datname = current_database() GROUP BY state");
            $total = DB::selectOne("SELECT count(*) AS count FROM pg_stat_activity");
            $maxConnections = DB::selectOne("SELECT setting FROM pg_settings WHERE name = 'max_connections'");
            $dbSize = DB::selectOne("SELECT pg_database_size(current_database()) AS size");
            $cache = DB::selectOne("SELECT sum(blks_hit)::float AS hits, sum(blks_read)::float AS reads FROM pg_stat_database WHERE datname = current_database()");
            $deadlocks = DB::selectOne("SELECT deadlocks FROM pg_stat_database WHERE datname = current_database()");
            $numBackends = DB::selectOne("SELECT numbackends FROM pg_stat_database WHERE datname = current_database()");
            $locks = DB::selectOne("SELECT count(*) AS count FROM pg_locks");
            $longQueries = DB::select("SELECT pid, state, round(extract(epoch FROM now() - xact_start)::numeric, 1) AS seconds, query FROM pg_stat_activity WHERE datname = current_database() AND state = 'active' AND now() - xact_start > interval '" . (int) config('monitoring.database.slow_query_seconds', 5) . " seconds' ORDER BY xact_start LIMIT 20");

            $states = [];
            foreach ($activity as $row) {
                $states[(string) $row->state] = (int) $row->count;
            }

            $max = $maxConnections ? (int) $maxConnections->setting : null;
            $conn = $total ? (int) $total->count : null;

            return [
                'engine' => 'pgsql',
                'connections_total' => $conn,
                'connections_active' => $states['active'] ?? 0,
                'connections_idle' => $states['idle'] ?? 0,
                'connections_other' => $states['idle in transaction'] ?? 0,
                'connections_max' => $max,
                'connections_percent' => $max && $conn ? round(($conn / $max) * 100, 2) : null,
                'database_size_bytes' => $dbSize ? (int) $dbSize->size : null,
                'database_size_mb' => $dbSize ? (int) round($dbSize->size / 1048576) : null,
                'cache_hit_ratio' => $cache && ($cache->hits + $cache->reads) > 0 ? round(($cache->hits / ($cache->hits + $cache->reads)) * 100, 2) : null,
                'deadlocks' => $deadlocks ? (int) $deadlocks->deadlocks : null,
                'backends' => $numBackends ? (int) $numBackends->numbackends : null,
                'locks' => $locks ? (int) $locks->count : null,
                'slow_queries_running' => count($longQueries),
            ];
        } catch (\Throwable $e) {
            Log::channel('monitoring')->error('monitoring.database_collect_failed', ['error' => $e->getMessage()]);

            return ['engine' => 'pgsql', 'error' => 'collect_failed'];
        }
    }

    // ─── Redis ─────────────────────────────────────────

    private function redisMetrics(): array
    {
        try {
            $info = Redis::connection()->info();
            $usedMemory = isset($info['used_memory']) ? (int) $info['used_memory'] : null;
            $maxMemory = isset($info['maxmemory']) ? (int) $info['maxmemory'] : null;
            $hits = isset($info['keyspace_hits']) ? (int) $info['keyspace_hits'] : null;
            $misses = isset($info['keyspace_misses']) ? (int) $info['keyspace_misses'] : null;

            return [
                'connected' => true,
                'version' => $info['redis_version'] ?? null,
                'used_memory_bytes' => $usedMemory,
                'used_memory_mb' => $usedMemory !== null ? (int) round($usedMemory / 1048576) : null,
                'maxmemory_bytes' => $maxMemory,
                'maxmemory_mb' => $maxMemory !== null && $maxMemory > 0 ? (int) round($maxMemory / 1048576) : null,
                'memory_percent' => $maxMemory && $maxMemory > 0 && $usedMemory !== null ? round(($usedMemory / $maxMemory) * 100, 2) : null,
                'hit_ratio' => $hits !== null && $misses !== null && ($hits + $misses) > 0 ? round(($hits / ($hits + $misses)) * 100, 2) : null,
                'connected_clients' => isset($info['connected_clients']) ? (int) $info['connected_clients'] : null,
                'ops_per_sec' => isset($info['instantaneous_ops_per_sec']) ? (int) $info['instantaneous_ops_per_sec'] : null,
                'evicted_keys' => isset($info['evicted_keys']) ? (int) $info['evicted_keys'] : null,
                'blocked_clients' => isset($info['blocked_clients']) ? (int) $info['blocked_clients'] : null,
                'rdb_last_bgsave_status' => $info['rdb_last_bgsave_status'] ?? null,
                'aof_enabled' => isset($info['aof_enabled']) ? (bool) $info['aof_enabled'] : null,
            ];
        } catch (\Throwable $e) {
            Log::channel('monitoring')->error('monitoring.redis_collect_failed', ['error' => $e->getMessage()]);

            return ['connected' => false];
        }
    }

    // ─── Queue / Horizon ─────────────────────────────────

    private function queueMetrics(): array
    {
        $queues = config('monitoring.queue.names', ['default', 'backups']);

        $depth = [];
        $reserved = [];

        try {
            foreach ($queues as $queue) {
                $key = 'queues:'.$queue;
                $depth[$queue] = (int) (Redis::connection()->llen($key) ?? 0);
                $reserved[$queue] = (int) (Redis::connection()->llen($key.':reserved') ?? 0);
            }

            // Auto-discover any other queues Horizon may have created.
            $keys = Redis::connection()->keys('queues:*') ?: [];
            foreach ($keys as $key) {
                $name = preg_replace('/^queues:/', '', (string) $key);
                if ($name !== '' && ! isset($depth[$name]) && ! str_ends_with($name, ':reserved')) {
                    $depth[$name] = (int) (Redis::connection()->llen($key) ?? 0);
                }
            }
        } catch (\Throwable) {
            // Redis down — queue depth unknown.
        }

        try {
            $failedCount = DB::table('failed_jobs')->count();
            $failed24h = DB::table('failed_jobs')->where('failed_at', '>=', now()->subHours(24))->count();
        } catch (\Throwable) {
            $failedCount = null;
            $failed24h = null;
        }

        $totalDepth = array_sum($depth);

        return [
            'depth' => $depth,
            'reserved' => $reserved,
            'total_pending' => $totalDepth,
            'failed_total' => $failedCount,
            'failed_last_24h' => $failed24h,
            'horizon' => app(HealthService::class)->horizonStatus(),
        ];
    }

    // ─── Backup ─────────────────────────────────────────

    private function backupMetrics(): array
    {
        try {
            $latest = Backup::query()
                ->orderByDesc('created_at')
                ->first();

            $count = Backup::count();
            $last24h = Backup::where('created_at', '>=', now()->subHours(24))->count();

            $backupsDir = storage_path('app/backups');

            return [
                'latest_created_at' => $latest?->created_at?->toIso8601String(),
                'latest_age_hours' => $latest?->created_at ? round(now()->diffInHours($latest->created_at), 1) : null,
                'latest_status' => $latest?->status ?? null,
                'latest_verified' => $latest?->verified ?? null,
                'latest_size_bytes' => $latest?->size_bytes ?? null,
                'total_count' => $count,
                'created_last_24h' => $last24h,
                'dir_size_bytes' => is_dir($backupsDir) ? $this->dirSize($backupsDir) : 0,
                'offsite_configured' => (string) config('services.backup.remote', '') !== '',
                'automatic_enabled' => (bool) config('backup.schedule.enabled', false),
            ];
        } catch (\Throwable $e) {
            Log::channel('monitoring')->error('monitoring.backup_collect_failed', ['error' => $e->getMessage()]);

            return ['error' => 'collect_failed'];
        }
    }

    // ─── Storage ─────────────────────────────────────────

    private function storageMetrics(): array
    {
        $roots = [
            'public' => storage_path('app/public'),
            'private' => storage_path('app/private'),
            'backups' => storage_path('app/backups'),
            'logs' => storage_path('logs'),
        ];

        $sizes = [];
        $oldest = null;
        $largest = [];

        foreach ($roots as $label => $path) {
            $sizes[$label] = is_dir($path) ? $this->dirSize($path) : 0;
        }

        $files = $this->largestFiles(storage_path('app'), 8);

        return [
            'dirs' => $sizes,
            'total_bytes' => array_sum($sizes),
            'largest_files' => $files,
            'root_used_percent' => null, // filled from host metrics
        ];
    }

    // ─── Security ─────────────────────────────────────────

    private function securityMetrics(): array
    {
        $security = MetricsRegistry::flush('security');
        $requests = MetricsRegistry::counter('requests.4xx');
        $requests5xx = MetricsRegistry::counter('requests.5xx');

        return [
            'failed_logins' => (int) ($security['monitoring:security.failed_logins'] ?? 0),
            'rate_limit_hits' => (int) ($security['monitoring:security.rate_limit_hits'] ?? 0),
            'access_denied' => (int) ($security['monitoring:security.access_denied'] ?? 0),
            'request_4xx' => (int) $requests,
            'request_5xx' => (int) $requests5xx,
        ];
    }

    // ─── Application counters ─────────────────────────────

    private function appMetrics(): array
    {
        $counters = MetricsRegistry::flush('requests');

        return [
            'requests_total' => (int) ($counters['monitoring:requests.total'] ?? 0),
            'requests_2xx' => (int) ($counters['monitoring:requests.2xx'] ?? 0),
            'requests_3xx' => (int) ($counters['monitoring:requests.3xx'] ?? 0),
            'requests_4xx' => (int) ($counters['monitoring:requests.4xx'] ?? 0),
            'requests_5xx' => (int) ($counters['monitoring:requests.5xx'] ?? 0),
            'duration_ms_total' => (int) ($counters['monitoring:requests.duration_ms'] ?? 0),
            'max_duration_ms' => (int) ($counters['monitoring:requests.max_duration_ms'] ?? 0),
        ];
    }

    // ─── Helpers ─────────────────────────────────────────

    private function run(array $command): ?string
    {
        try {
            $result = Process::timeout(10)->run($command);

            return $result->successful() ? $result->output() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function dirSize(string $path): int
    {
        $result = $this->run(['du', '-sb', $path]);
        if ($result === null) {
            return 0;
        }

        $parts = preg_split('/\s+/', trim($result));

        return isset($parts[0]) ? (int) $parts[0] : 0;
    }

    private function largestFiles(string $root, int $limit): array
    {
        $result = $this->run(['find', $root, '-type', 'f', '-printf', '%s\t%p\n']);

        if ($result === null) {
            return [];
        }

        $files = [];
        foreach (explode("\n", trim($result)) as $line) {
            $tab = strpos($line, "\t");
            if ($tab === false) {
                continue;
            }

            $size = (int) substr($line, 0, $tab);
            $path = substr($line, $tab + 1);

            if ($size > 0 && ! str_contains($path, '/storage/app/backups/')) {
                $files[] = ['path' => $path, 'size_bytes' => $size];
            }
        }

        usort($files, fn ($a, $b) => $b['size_bytes'] <=> $a['size_bytes']);

        return array_slice($files, 0, $limit);
    }
}