<?php

/**
 * Production monitoring configuration.
 *
 * All thresholds and alerting knobs live here (env-driven). Values are
 * recommendations derived from the architecture audit — they are NOT law.
 * Adjust them to match observed capacity before/after load testing.
 *
 * Safe operation rules:
 *  - monitoring never breaks the application (all collectors degrade).
 *  - no secrets, passwords, tokens or raw request bodies are ever logged.
 *  - alert channels are opt-in via env.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Master switch
    |--------------------------------------------------------------------------
    | Disable the request-metrics middleware entirely (collectors/health
    | endpoints still work). Useful for very low-end boxes where every
    | microsecond matters during early development.
    */
    'enabled' => (bool) env('MONITORING_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Redis counter namespace
    |--------------------------------------------------------------------------
    | All request-level counters live here. Kept separate from the app
    | cache/queue/session namespace so flushing one never touches the other.
    */
    'redis_prefix' => env('MONITORING_REDIS_PREFIX', 'monitoring:'),

    /*
    |--------------------------------------------------------------------------
    | Request metrics
    |--------------------------------------------------------------------------
    */
    'request' => [
        // Requests slower than this (ms) are recorded to the monitoring log.
        'slow_threshold_ms' => (int) env('MONITORING_SLOW_MS', 1000),
        // Store slow requests to the database (monitoring_slow_requests).
        'persist_slow' => (bool) env('MONITORING_PERSIST_SLOW', true),
        // Maximum slow-request rows kept (pruned by scheduler).
        'slow_retention' => (int) env('MONITORING_SLOW_RETENTION', 10000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Snapshot collection cadence
    |--------------------------------------------------------------------------
    | monitoring:collect runs on this schedule (minutes). Each run stores one
    | row in monitoring_snapshots.
    */
    'collect_interval_minutes' => (int) env('MONITORING_COLLECT_MINUTES', 5),

    // How many snapshots to keep in the database (pruned by scheduler).
    'snapshot_retention' => (int) env('MONITORING_SNAPSHOT_RETENTION', 1440),

    /*
    |--------------------------------------------------------------------------
    | Host / VPS thresholds
    |--------------------------------------------------------------------------
    */
    'host' => [
        'cpu' => [
            'warning' => 70,
            'critical' => 90,
            // Sustained window in minutes (averaged over N snapshots).
            'window' => 15,
        ],
        'ram' => [
            'warning' => 75,
            'critical' => 90,
        ],
        'swap' => [
            'warning_mb' => (int) env('MONITORING_SWAP_WARNING_MB', 512),
        ],
        'disk' => [
            // Percent used on the root filesystem.
            'warning' => 70,
            'critical' => 85,
        ],
        'load' => [
            // load1 vs CPU cores ratio for sustained overload detection.
            'warning' => 1.0,
            'critical' => 1.5,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | PostgreSQL thresholds
    |--------------------------------------------------------------------------
    */
    'database' => [
        // Percent of max_connections in use.
        'connections_warning_pct' => 70,
        'connections_critical_pct' => 90,
        // A query longer than this (s) is a slow query.
        'slow_query_seconds' => (int) env('MONITORING_DB_SLOW_QUERY_S', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis thresholds
    |--------------------------------------------------------------------------
    */
    'redis' => [
        'memory_warning_pct' => 70,
        'memory_critical_pct' => 90,
        // Evicted keys per minute triggering a warning.
        'evictions_per_minute_warning' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue / Horizon thresholds
    |--------------------------------------------------------------------------
    | BACKLOG_GROWTH_WINDOW: number of consecutive snapshots where the total
    | pending queue depth kept growing before a CRITICAL alert fires.
    */
    'queue' => [
        'backlog_growth_window' => (int) env('MONITORING_QUEUE_GROWTH_WINDOW', 3),
        'failed_jobs_warning' => (int) env('MONITORING_FAILED_JOBS_WARNING', 5),
        'failed_jobs_critical' => (int) env('MONITORING_FAILED_JOBS_CRITICAL', 20),
        'pending_warning' => (int) env('MONITORING_QUEUE_PENDING_WARNING', 500),
        'pending_critical' => (int) env('MONITORING_QUEUE_PENDING_CRITICAL', 2000),
        // Queues monitored. Real queue names from the app: default + backups
        // (config/horizon.php supervisor-backups). Any extra queues are
        // discovered automatically at collect time.
        'names' => array_values(array_filter(array_map('trim', explode(',', (string) env('MONITORING_QUEUES', 'default,backups'))))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Scheduler heartbeat
    |--------------------------------------------------------------------------
    | The scheduler container writes a heartbeat file every minute via
    | schedule:work. If the file is older than this many minutes the
    | scheduler is considered stopped.
    */
    'scheduler' => [
        'heartbeat_path' => storage_path('monitoring/scheduler-heartbeat'),
        'stale_after_minutes' => (int) env('MONITORING_SCHEDULER_STALE_MIN', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Backup monitoring
    |--------------------------------------------------------------------------
    */
    'backup' => [
        // Critical if the newest successful backup is older than this (hours).
        'max_age_hours' => (int) env('MONITORING_BACKUP_MAX_AGE_HOURS', 24),
        // Warning if newest successful backup older than this (hours).
        'warn_age_hours' => (int) env('MONITORING_BACKUP_WARN_AGE_HOURS', 12),
        // Warning if off-site sync is not configured in production.
        'warn_missing_offsite' => (bool) env('MONITORING_BACKUP_WARN_MISSING_OFFSITE', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | SSL certificate monitoring
    |--------------------------------------------------------------------------
    | Path to the leaf certificate chain file. In Docker Compose the app and
    | scheduler containers mount ./certs read-only at /etc/monitoring/certs.
    | Set MONITORING_SSL_CERT_PATH explicitly when certificates live elsewhere.
    */
    'ssl' => [
        'cert_path' => env('MONITORING_SSL_CERT_PATH', '/etc/monitoring/certs/fullchain.pem'),
        'warn_days' => (int) env('MONITORING_SSL_WARN_DAYS', 30),
        'high_days' => (int) env('MONITORING_SSL_HIGH_DAYS', 14),
        'critical_days' => (int) env('MONITORING_SSL_CRITICAL_DAYS', 7),
    ],

    /*
    |--------------------------------------------------------------------------
    | Security monitoring
    |--------------------------------------------------------------------------
    */
    'security' => [
        // Failed login attempts per window (auth.failed_logins counter) that
        // triggers a warning.
        'failed_login_warning' => (int) env('MONITORING_AUTH_FAILED_WARNING', 20),
        // 4xx/5xx response counts per window that trigger a warning.
        'error_rate_warning' => (int) env('MONITORING_ERROR_RATE_WARNING', 100),
    ],

    /*
    |--------------------------------------------------------------------------
    | Alerting
    |--------------------------------------------------------------------------
    | Channels are opt-in. Alerts are always written to the monitoring log and
    | the monitoring_alerts table; mail/webhook only when configured.
    */
    'alerts' => [
        // Dedupe window: the same alert key won't re-fire within this window.
        'cooldown_minutes' => (int) env('MONITORING_ALERT_COOLDOWN_MIN', 15),
        // Email recipients (comma separated). Disabled when empty.
        'mail_to' => env('MONITORING_ALERT_MAIL_TO', ''),
        // Generic webhook URL (Slack/Discord/Telegram style JSON POST).
        'webhook_url' => env('MONITORING_ALERT_WEBHOOK_URL', ''),
        // Optional shared secret used to sign the webhook payload.
        // Header: X-Monitoring-Signature: sha256=<hex hmac>
        'webhook_secret' => env('MONITORING_ALERT_WEBHOOK_SECRET', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | External uptime monitoring
    |--------------------------------------------------------------------------
    | Not called from the app — documentation/configuration for external
    | providers (UptimeRobot, BetterStack, Healthchecks.io, Hetrix…).
    | See docs/PRODUCTION_MONITORING.md §Uptime.
    */
    'uptime' => [
        'enabled' => (bool) env('MONITORING_UPTIME_ENABLED', false),
        'check_interval_minutes' => (int) env('MONITORING_UPTIME_INTERVAL_MIN', 1),
    ],

];