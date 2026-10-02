<?php

namespace App\Monitoring;

use App\Models\MonitoringAlert;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * AlertDispatcher — evaluates thresholds against the latest snapshot and
 * creates/updates deduplicated alerts. Channels are opt-in:
 *
 *  - log      (always, structured monitoring channel)
 *  - table    (always, monitoring_alerts)
 *  - mail     (MONITORING_ALERT_MAIL_TO)
 *  - webhook  (MONITORING_ALERT_WEBHOOK_URL + optional HMAC signature)
 *
 * Every alert carries only aggregate/derived values — never secrets.
 */
class AlertDispatcher
{
    /** @var list<array{level: string, source: string, message: string, context?: array}> */
    private array $pending = [];

    public function evaluate(array $snapshot): array
    {
        $this->pending = [];

        $this->evalHost($snapshot['host'] ?? []);
        $this->evalDatabase($snapshot['database'] ?? []);
        $this->evalRedis($snapshot['redis'] ?? []);
        $this->evalQueue($snapshot['queue'] ?? []);
        $this->evalBackup($snapshot['backup'] ?? []);
        $this->evalStorage($snapshot['storage'] ?? [], $snapshot['host'] ?? []);
        $this->evalSecurity($snapshot['security'] ?? []);
        $this->evalSsl();

        $dispatched = [];
        foreach ($this->pending as $alert) {
            $dispatched[] = $this->dispatch($alert['level'], $alert['source'], $alert['message'], $alert['context'] ?? []);
        }

        return $dispatched;
    }

    private function dispatch(string $level, string $source, string $message, array $context): bool
    {
        try {
            $key = $source.':'.substr(hash('sha256', $message), 0, 24);

            $existing = MonitoringAlert::where('key', $key)->first();

            if ($existing) {
                $existing->update([
                    'last_seen_at' => now(),
                    'occurrences' => $existing->occurrences + 1,
                    'context' => array_merge($existing->context ?? [], $context),
                ]);

                return false; // recurring — do not re-dispatch
            }

            MonitoringAlert::create([
                'level' => $level,
                'source' => $source,
                'key' => $key,
                'message' => substr($message, 0, 2000),
                'context' => $context,
                'status' => MonitoringAlert::STATUS_OPEN,
                'first_seen_at' => now(),
                'last_seen_at' => now(),
                'occurrences' => 1,
            ]);

            $this->notify($level, $source, $message, $context);

            return true;
        } catch (\Throwable $e) {
            Log::channel('monitoring')->error('monitoring.alert_dispatch_failed', ['error' => $e->getMessage()]);

            return false;
        }
    }

    private function notify(string $level, string $source, string $message, array $context): void
    {
        $payload = [
            'level' => $level,
            'source' => $source,
            'message' => $message,
            'context' => $context,
            'timestamp' => now()->toIso8601String(),
            'environment' => app()->environment(),
        ];

        // 1) Structured log (always).
        Log::channel('monitoring')->{$level === 'critical' ? 'critical' : 'warning'}('alert.'.$source, $payload);

        // 2) Email (opt-in).
        $mailTo = (string) config('monitoring.alerts.mail_to', '');
        if ($mailTo !== '') {
            try {
                $this->sendMail($mailTo, $payload);
            } catch (\Throwable $e) {
                Log::channel('monitoring')->warning('monitoring.alert_mail_failed', ['error' => $e->getMessage()]);
            }
        }

        // 3) Webhook (opt-in).
        $webhookUrl = (string) config('monitoring.alerts.webhook_url', '');
        if ($webhookUrl !== '') {
            try {
                $this->sendWebhook($webhookUrl, $payload);
            } catch (\Throwable $e) {
                Log::channel('monitoring')->warning('monitoring.alert_webhook_failed', ['error' => $e->getMessage()]);
            }
        }
    }

    private function sendMail(string $to, array $payload): void
    {
        $from = config('mail.from.address', 'monitoring@localhost');

        \Illuminate\Support\Facades\Mail::raw(
            sprintf(
                "[%s] %s — %s\n\nContext: %s",
                strtoupper($payload['level']),
                $payload['source'],
                $payload['message'],
                json_encode($payload['context'], JSON_UNESCAPED_UNICODE),
            ),
            function ($message) use ($to, $from) {
                $message->to(explode(',', $to))
                    ->from($from)
                    ->subject('[Tadreesly Monitor] '.strtoupper($payload['level']).' — '.$payload['source']);
            }
        );
    }

    private function sendWebhook(string $url, array $payload): void
    {
        $request = Http::timeout(10)->acceptJson()->asJson();

        $secret = (string) config('monitoring.alerts.webhook_secret', '');
        if ($secret !== '') {
            $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
            $request = $request->withHeaders([
                'X-Monitoring-Signature' => 'sha256='.hash_hmac('sha256', (string) $body, $secret),
            ])->withBody((string) $body, 'application/json');
        }

        $request->post($url, $payload);
    }

    // ─── Evaluators ───────────────────────────────────────

    private function evalHost(array $host): void
    {
        $cpu = (float) ($host['cpu_percent'] ?? 0);
        $ram = (float) ($host['mem_percent'] ?? 0);
        $disk = (float) ($host['disk_root_percent'] ?? 0);
        $swap = (int) ($host['swap_used_mb'] ?? 0);
        $load1 = (float) ($host['loadavg_1'] ?? 0);

        if ($cpu >= (float) config('monitoring.host.cpu.critical', 90)) {
            $this->pending[] = ['level' => 'critical', 'source' => 'host.cpu', 'message' => "CPU at {$cpu}% (critical threshold " . config('monitoring.host.cpu.critical', 90) . '%)', 'context' => ['cpu_percent' => $cpu]];
        } elseif ($cpu >= (float) config('monitoring.host.cpu.warning', 70)) {
            $this->pending[] = ['level' => 'warning', 'source' => 'host.cpu', 'message' => "CPU at {$cpu}% (warning threshold " . config('monitoring.host.cpu.warning', 70) . '%)', 'context' => ['cpu_percent' => $cpu]];
        }

        if ($ram >= (float) config('monitoring.host.ram.critical', 90)) {
            $this->pending[] = ['level' => 'critical', 'source' => 'host.ram', 'message' => "RAM at {$ram}% (critical threshold " . config('monitoring.host.ram.critical', 90) . '%)', 'context' => ['mem_percent' => $ram]];
        } elseif ($ram >= (float) config('monitoring.host.ram.warning', 75)) {
            $this->pending[] = ['level' => 'warning', 'source' => 'host.ram', 'message' => "RAM at {$ram}% (warning threshold " . config('monitoring.host.ram.warning', 75) . '%)', 'context' => ['mem_percent' => $ram]];
        }

        if ($disk >= (float) config('monitoring.host.disk.critical', 85)) {
            $this->pending[] = ['level' => 'critical', 'source' => 'host.disk', 'message' => "Root disk at {$disk}% (critical threshold " . config('monitoring.host.disk.critical', 85) . '%)', 'context' => ['disk_percent' => $disk]];
        } elseif ($disk >= (float) config('monitoring.host.disk.warning', 70)) {
            $this->pending[] = ['level' => 'warning', 'source' => 'host.disk', 'message' => "Root disk at {$disk}% (warning threshold " . config('monitoring.host.disk.warning', 70) . '%)', 'context' => ['disk_percent' => $disk]];
        }

        if ($swap > (int) config('monitoring.host.swap.warning_mb', 512)) {
            $this->pending[] = ['level' => 'warning', 'source' => 'host.swap', 'message' => "Swap usage at {$swap}MB", 'context' => ['swap_used_mb' => $swap]];
        }

        $loadWarn = (float) config('monitoring.host.load.warning', 1.0);
        $loadCrit = (float) config('monitoring.host.load.critical', 1.5);
        if ($load1 >= $loadCrit) {
            $this->pending[] = ['level' => 'critical', 'source' => 'host.load', 'message' => "Load average {$load1} (critical threshold {$loadCrit})", 'context' => ['load1' => $load1]];
        } elseif ($load1 >= $loadWarn) {
            $this->pending[] = ['level' => 'warning', 'source' => 'host.load', 'message' => "Load average {$load1} (warning threshold {$loadWarn})", 'context' => ['load1' => $load1]];
        }
    }

    private function evalDatabase(array $db): void
    {
        if (($db['error'] ?? null) !== null) {
            $this->pending[] = ['level' => 'critical', 'source' => 'database', 'message' => 'PostgreSQL metrics collection failed — database may be down', 'context' => ['error' => $db['error']]];

            return;
        }

        $connPct = (float) ($db['connections_percent'] ?? 0);
        $conn = (int) ($db['connections_total'] ?? 0);
        $max = (int) ($db['connections_max'] ?? 0);

        if ($connPct >= (float) config('monitoring.database.connections_critical_pct', 90)) {
            $this->pending[] = ['level' => 'critical', 'source' => 'database.connections', 'message' => "PostgreSQL connections at {$conn}/{$max} ({$connPct}%) — exhaustion imminent", 'context' => ['connections' => $conn, 'max' => $max]];
        } elseif ($connPct >= (float) config('monitoring.database.connections_warning_pct', 70)) {
            $this->pending[] = ['level' => 'warning', 'source' => 'database.connections', 'message' => "PostgreSQL connections at {$conn}/{$max} ({$connPct}%)", 'context' => ['connections' => $conn, 'max' => $max]];
        }

        if ((int) ($db['slow_queries_running'] ?? 0) > 0) {
            $this->pending[] = ['level' => 'warning', 'source' => 'database.slow_queries', 'message' => "Slow PostgreSQL queries running: " . (int) $db['slow_queries_running'], 'context' => ['count' => $db['slow_queries_running']]];
        }

        if ((int) ($db['deadlocks'] ?? 0) > 0) {
            $this->pending[] = ['level' => 'warning', 'source' => 'database.deadlocks', 'message' => "PostgreSQL deadlocks detected: " . (int) $db['deadlocks'], 'context' => ['count' => $db['deadlocks']]];
        }
    }

    private function evalRedis(array $redis): void
    {
        if (($redis['connected'] ?? false) !== true) {
            $this->pending[] = ['level' => 'critical', 'source' => 'redis', 'message' => 'Redis is unreachable', 'context' => []];

            return;
        }

        $memPct = (float) ($redis['memory_percent'] ?? 0);

        if ($memPct >= (float) config('monitoring.redis.memory_critical_pct', 90)) {
            $this->pending[] = ['level' => 'critical', 'source' => 'redis.memory', 'message' => "Redis memory at {$memPct}% of maxmemory", 'context' => ['memory_percent' => $memPct]];
        } elseif ($memPct >= (float) config('monitoring.redis.memory_warning_pct', 70)) {
            $this->pending[] = ['level' => 'warning', 'source' => 'redis.memory', 'message' => "Redis memory at {$memPct}% of maxmemory", 'context' => ['memory_percent' => $memPct]];
        }

        if ((int) ($redis['evicted_keys'] ?? 0) > (int) config('monitoring.redis.evictions_per_minute_warning', 10)) {
            $this->pending[] = ['level' => 'warning', 'source' => 'redis.evictions', 'message' => "Redis evicted " . (int) $redis['evicted_keys'] . ' keys — cache pressure; sessions may be dropped (allkeys-lru)', 'context' => ['evicted_keys' => $redis['evicted_keys']]];
        }

        if (($redis['rdb_last_bgsave_status'] ?? 'ok') !== 'ok') {
            $this->pending[] = ['level' => 'critical', 'source' => 'redis.persistence', 'message' => 'Redis RDB background save failed — persistence at risk', 'context' => []];
        }
    }

    private function evalQueue(array $queue): void
    {
        $pending = (int) ($queue['total_pending'] ?? 0);
        $failed = (int) ($queue['failed_total'] ?? 0);

        if ($pending >= (int) config('monitoring.queue.pending_critical', 2000)) {
            $this->pending[] = ['level' => 'critical', 'source' => 'queue.backlog', 'message' => "Queue backlog at {$pending} pending jobs", 'context' => ['pending' => $pending]];
        } elseif ($pending >= (int) config('monitoring.queue.pending_warning', 500)) {
            $this->pending[] = ['level' => 'warning', 'source' => 'queue.backlog', 'message' => "Queue backlog at {$pending} pending jobs", 'context' => ['pending' => $pending]];
        }

        $failedCritical = (int) config('monitoring.queue.failed_jobs_critical', 20);
        $failedWarning = (int) config('monitoring.queue.failed_jobs_warning', 5);

        if ($failed >= $failedCritical) {
            $this->pending[] = ['level' => 'critical', 'source' => 'queue.failed_jobs', 'message' => "{$failed} failed jobs (critical threshold {$failedCritical})", 'context' => ['failed' => $failed]];
        } elseif ($failed >= $failedWarning) {
            $this->pending[] = ['level' => 'warning', 'source' => 'queue.failed_jobs', 'message' => "{$failed} failed jobs (warning threshold {$failedWarning})", 'context' => ['failed' => $failed]];
        }

        if (($queue['horizon'] ?? '') === 'stopped') {
            $this->pending[] = ['level' => 'critical', 'source' => 'horizon', 'message' => 'Horizon is not running — jobs are not being processed', 'context' => []];
        }

        // Backlog growth across consecutive snapshots.
        $previous = MetricsRegistry::get('monitoring:queue_trend');
        MetricsRegistry::set('monitoring:queue_trend', (string) $pending, 3600);

        if ($previous !== null && (int) $previous > 0 && $pending > (int) $previous) {
            $window = (int) config('monitoring.queue.backlog_growth_window', 3);
            $streak = (int) MetricsRegistry::counter('monitoring:queue_growth_streak') + 1;
            MetricsRegistry::increment('monitoring:queue_growth_streak');

            if ($streak >= $window) {
                $this->pending[] = ['level' => 'critical', 'source' => 'queue.growth', 'message' => "Queue backlog grew for {$streak} consecutive checks (now {$pending} pending)", 'context' => ['streak' => $streak, 'pending' => $pending]];
            }
        } else {
            MetricsRegistry::set('monitoring:queue_growth_streak', '0', 3600);
        }
    }

    private function evalBackup(array $backup): void
    {
        $maxAge = (int) config('monitoring.backup.max_age_hours', 24);
        $warnAge = (int) config('monitoring.backup.warn_age_hours', 12);

        if (($backup['latest_age_hours'] ?? null) === null) {
            $this->pending[] = ['level' => 'critical', 'source' => 'backup', 'message' => 'No backups found — no backup has ever been created', 'context' => []];

            return;
        }

        $age = (float) $backup['latest_age_hours'];

        if ($age > $maxAge) {
            $this->pending[] = ['level' => 'critical', 'source' => 'backup.age', 'message' => "Newest backup is {$age}h old (critical threshold {$maxAge}h)", 'context' => ['age_hours' => $age]];
        } elseif ($age > $warnAge) {
            $this->pending[] = ['level' => 'warning', 'source' => 'backup.age', 'message' => "Newest backup is {$age}h old (warning threshold {$warnAge}h)", 'context' => ['age_hours' => $age]];
        }

        if (($backup['latest_status'] ?? '') !== 'completed' && ($backup['latest_status'] ?? '') !== 'ready') {
            $this->pending[] = ['level' => 'warning', 'source' => 'backup.status', 'message' => "Newest backup has non-successful status: " . ($backup['latest_status'] ?? 'unknown'), 'context' => ['status' => $backup['latest_status']]];
        }

        if (app()->environment('production') && config('monitoring.backup.warn_missing_offsite', true) && ($backup['offsite_configured'] ?? false) !== true) {
            $this->pending[] = ['level' => 'warning', 'source' => 'backup.offsite', 'message' => 'BACKUP_RCLONE_REMOTE is not configured — local backups alone are NOT disaster recovery', 'context' => []];
        }
    }

    private function evalStorage(array $storage, array $host): void
    {
        $backupsBytes = (int) ($storage['dirs']['backups'] ?? 0);
        $backupsMb = (int) round($backupsBytes / 1048576);

        if ($backupsMb > (int) config('monitoring.host.disk.warning', 70)) {
            $this->pending[] = ['level' => 'warning', 'source' => 'storage.backups', 'message' => "Backup directory is {$backupsMb}MB — monitor disk headroom", 'context' => ['backups_mb' => $backupsMb]];
        }

        $diskPct = (float) ($host['disk_root_percent'] ?? 0);
        if ($diskPct >= (float) config('monitoring.host.disk.warning', 70)) {
            $this->pending[] = ['level' => 'warning', 'source' => 'storage.root', 'message' => "Root disk at {$diskPct}% — backups/uploads are consuming space", 'context' => ['disk_percent' => $diskPct]];
        }
    }

    private function evalSecurity(array $security): void
    {
        $failedLogins = (int) ($security['failed_logins'] ?? 0);
        $rateHits = (int) ($security['rate_limit_hits'] ?? 0);
        $accessDenied = (int) ($security['access_denied'] ?? 0);
        $errors5xx = (int) ($security['request_5xx'] ?? 0);

        if ($failedLogins >= (int) config('monitoring.security.failed_login_warning', 20)) {
            $this->pending[] = ['level' => 'warning', 'source' => 'security.failed_logins', 'message' => "{$failedLogins} failed logins in the window — possible brute-force attempt", 'context' => ['failed_logins' => $failedLogins]];
        }

        if ($rateHits >= (int) config('monitoring.security.error_rate_warning', 100)) {
            $this->pending[] = ['level' => 'warning', 'source' => 'security.rate_limit', 'message' => "{$rateHits} rate-limit hits in the window — possible abuse", 'context' => ['rate_limit_hits' => $rateHits]];
        }

        if ($accessDenied >= (int) config('monitoring.security.error_rate_warning', 100)) {
            $this->pending[] = ['level' => 'warning', 'source' => 'security.access_denied', 'message' => "{$accessDenied} forbidden accesses in the window — possible probing", 'context' => ['access_denied' => $accessDenied]];
        }

        if ($errors5xx >= (int) config('monitoring.security.error_rate_warning', 100)) {
            $this->pending[] = ['level' => 'warning', 'source' => 'security.http_5xx', 'message' => "{$errors5xx} HTTP 5xx responses in the window", 'context' => ['http_5xx' => $errors5xx]];
        }
    }

    private function evalSsl(): void
    {
        $certPath = (string) config('monitoring.ssl.cert_path', '');

        if ($certPath === '' || ! is_file($certPath)) {
            return; // cert path not mounted — host-level check only (see docs)
        }

        try {
            $pem = file_get_contents($certPath);
            if ($pem === false) {
                return;
            }

            $cert = openssl_x509_parse($pem);
            if ($cert === false) {
                return;
            }

            $validTo = (int) ($cert['validTo_time_t'] ?? 0);
            if ($validTo === 0) {
                return;
            }

            $daysLeft = (int) ceil(($validTo - time()) / 86400);

            if ($daysLeft <= (int) config('monitoring.ssl.critical_days', 7)) {
                $this->pending[] = ['level' => 'critical', 'source' => 'ssl.expiry', 'message' => "SSL certificate expires in {$daysLeft} day(s)", 'context' => ['days_left' => $daysLeft, 'subject' => $cert['subject']['CN'] ?? null]];
            } elseif ($daysLeft <= (int) config('monitoring.ssl.high_days', 14)) {
                $this->pending[] = ['level' => 'warning', 'source' => 'ssl.expiry', 'message' => "SSL certificate expires in {$daysLeft} day(s)", 'context' => ['days_left' => $daysLeft, 'subject' => $cert['subject']['CN'] ?? null]];
            } elseif ($daysLeft <= (int) config('monitoring.ssl.warn_days', 30)) {
                $this->pending[] = ['level' => 'warning', 'source' => 'ssl.expiry', 'message' => "SSL certificate expires in {$daysLeft} day(s)", 'context' => ['days_left' => $daysLeft, 'subject' => $cert['subject']['CN'] ?? null]];
            }
        } catch (\Throwable) {
            // openssl missing — degrade silently.
        }
    }
}