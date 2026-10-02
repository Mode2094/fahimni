<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\Process;

/**
 * Collects non-secret environment/system facts for the manifest and the
 * system-info artifact. Never touches secrets.
 */
class SystemInfoCollector
{
    public function collect(bool $includeGitCommit = true): array
    {
        $appVersion = null;
        if (is_readable(base_path('composer.json'))) {
            $composer = json_decode((string) file_get_contents(base_path('composer.json')), true);
            $appVersion = $composer['version'] ?? null;
        }

        $laravel = null;
        try {
            $laravel = app()->version();
        } catch (\Throwable) {
        }

        $db = null;
        $dbVersion = null;
        try {
            $db = config('database.default');
            $dbVersion = $this->databaseVersion();
        } catch (\Throwable) {
        }

        $nodeVersion = null;
        $phpVersion = PHP_VERSION;

        try {
            $node = Process::timeout(10)->run(['node', '--version']);
            if ($node->exitCode() === 0) {
                $nodeVersion = trim($node->output());
            }
        } catch (\Throwable) {
        }

        $gitCommit = null;
        if ($includeGitCommit && config('backup.manifest.include_git_commit', true)) {
            try {
                $git = Process::path(base_path())->timeout(10)->run(['git', 'rev-parse', 'HEAD']);
                if ($git->exitCode() === 0) {
                    $gitCommit = trim($git->output());
                }
            } catch (\Throwable) {
            }
        }

        return [
            'platform' => 'Tadreesly',
            'app_version' => $appVersion,
            'laravel_version' => $laravel,
            'php_version' => $phpVersion,
            'node_version' => $nodeVersion,
            'git_commit' => $gitCommit,
            'app_env' => app()->environment(),
            'app_url' => (string) config('app.url'),
            'database_engine' => $db,
            'database_version' => $dbVersion,
            'server_time' => now()->toIso8601String(),
            'timezone' => date_default_timezone_get(),
            'php_os' => PHP_OS,
        ];
    }

    protected function databaseVersion(): ?string
    {
        try {
            $result = \Illuminate\Support\Facades\DB::select('SELECT version() AS v');
            $full = (string) ($result[0]->v ?? '');

            if (preg_match('/PostgreSQL (\d+(?:\.\d+)*)/', $full, $m)) {
                return $m[1];
            }

            return $full;
        } catch (\Throwable) {
            return null;
        }
    }
}