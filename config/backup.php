<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Backup system configuration
    |--------------------------------------------------------------------------
    |
    | All business rules live here (or in platform_settings for admin-editable
    | values) — never hard-coded inside controllers/services.
    */

    /*
    | Storage disk used for backup packages. The "backup" disk is a private
    | local disk (never public). Swap this to an S3-compatible disk later
    | without touching business code — the whole pipeline addresses files via
    | Storage::disk(config('backup.disk')).
    */
    'disk' => env('BACKUP_DISK', 'backup'),

    /*
    | Backup naming prefix. The full ID looks like:
    | SHAHEAD_BACKUP_2026-08-19_120000_a1b2c3
    | The random suffix keeps concurrent backups collision-free. No secrets
    | are ever embedded in the ID or directory name.
    */
    'name_prefix' => env('BACKUP_NAME_PREFIX', 'SHAHEAD_BACKUP'),

    /*
    | Queue used by backup jobs. Keep it separate from the main queue so a
    | large backup never starves user-facing jobs.
    */
    'queue' => env('BACKUP_QUEUE', 'backups'),

    /*
    | Job timeout for the full pipeline (seconds). Large file sets + pg_dump
    | can take a while; the job must not die mid-way.
    */
    'timeout' => (int) env('BACKUP_JOB_TIMEOUT', 7200),

    /*
    | Process-level timeouts (seconds) for the individual heavy steps.
    */
    'process_timeouts' => [
        'pg_dump' => (int) env('BACKUP_PGDUMP_TIMEOUT', 3600),
        'files_archive' => (int) env('BACKUP_ARCHIVE_TIMEOUT', 3600),
        'verify_dump' => (int) env('BACKUP_VERIFY_TIMEOUT', 600),
        'verify_archive' => (int) env('BACKUP_VERIFY_TIMEOUT', 600),
    ],

    /*
    | Encryption key for configuration.enc. Falls back to APP_KEY so the
    | system works out of the box, but a dedicated BACKUP_ENCRYPTION_KEY is
    | strongly recommended so backup secrets stay decryptable even if the
    | primary APP_KEY is rotated.
    |
    | The raw key string is hashed (SHA-256) to a 32-byte AES key, so it may
    | be a plain passphrase or a base64 value — never stored in the package.
    */
    'encryption_key' => env('BACKUP_ENCRYPTION_KEY', env('APP_KEY', '')),

    /*
    | AES cipher used for configuration.enc. GCM provides authenticated
    | encryption (tamper detection) which doubles as a verification step.
    */
    'encryption_cipher' => 'aes-256-gcm',

    /*
    | Retention (number of COMPLETED backups to keep). The admin can override
    | this from the UI — it is stored in platform_settings as
    | backup_retention_count. This value is only the default.
    */
    'retention' => [
        'default' => (int) env('BACKUP_RETENTION_DEFAULT', 7),
        // Failed/aborted backups older than this many days are pruned too.
        'failed_retention_days' => (int) env('BACKUP_FAILED_RETENTION_DAYS', 3),
    ],

    /*
    | Automatic backup schedule. The admin enables this from the UI
    | (platform_settings backup_automatic_enabled). Disabled by default —
    | automatic backups NEVER run until an admin explicitly enables them.
    */
    'schedule' => [
        'enabled' => (bool) env('BACKUP_AUTOMATIC_ENABLED', false),
        'frequency' => env('BACKUP_FREQUENCY', 'daily'), // daily | weekly
        'time' => env('BACKUP_TIME', '02:00'),           // HH:MM (24h)
        'weekly_day' => (int) env('BACKUP_WEEKLY_DAY', 1), // 0=Sun ... 6=Sat (Carbon dayOfWeek)
    ],

    /*
    | Filesystem paths included in the files archive (relative to base_path).
    | The scanner always includes storage/app/public and storage/app/private
    | (excluding the backup store itself). Add extra roots here if the
    | deployment keeps uploads elsewhere (e.g. a top-level uploads/ dir).
    */
    'files' => [
        'base' => storage_path('app'),
        'always_include' => ['public', 'private'],
        'extra_paths' => array_values(array_filter(explode(',', (string) env('BACKUP_EXTRA_PATHS', '')))),
        'exclude_patterns' => ['backups', 'cache', 'logs', '*.tmp'],
    ],

    /*
    | Verification behavior. Every artifact checksum is always recomputed;
    | the dump is additionally validated with pg_restore --list and the file
    | archive with tar --test. These can be skipped in constrained setups.
    */
    'verify' => [
        'dump' => (bool) env('BACKUP_VERIFY_DUMP', true),
        'archive' => (bool) env('BACKUP_VERIFY_ARCHIVE', true),
    ],

    /*
    | Signed download links (no secret in URL). Signature = HMAC-SHA256 of
    | "{backupId}|{artifact}|{expires}" with the app key.
    */
    'download' => [
        'ttl_seconds' => (int) env('BACKUP_DOWNLOAD_TTL', 3600),
    ],

    /*
    | Manifest metadata gate: if git is available the commit hash is recorded
    | in the manifest. Purely informational — never used for anything that
    | could leak secrets.
    */
    'manifest' => [
        'include_git_commit' => true,
    ],

];