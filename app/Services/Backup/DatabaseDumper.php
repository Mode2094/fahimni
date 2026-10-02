<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * PostgreSQL dump step. Produces a real, restorable pg_dump (custom format)
 * that is re-usable with pg_restore. Tables are discovered automatically from
 * the information schema — no hard-coded table list anywhere.
 *
 * The process is streamed to disk (never held in memory) so large databases
 * do not exhaust PHP memory or request timeouts.
 */
class DatabaseDumper
{
    public function listTables(): array
    {
        $rows = DB::select(
            "SELECT table_schema || '.' || table_name AS name
               FROM information_schema.tables
              WHERE table_schema NOT IN ('pg_catalog', 'information_schema')
                AND table_type = 'BASE TABLE'
              ORDER BY table_schema, table_name"
        );

        return array_map(fn ($r) => $r->name, $rows);
    }

    public function databaseSizeBytes(): int
    {
        try {
            $row = DB::selectOne('SELECT pg_database_size(current_database()) AS bytes');

            return (int) ($row->bytes ?? 0);
        } catch (\Throwable) {
            return 0;
        }
    }

    public function databaseVersion(): ?string
    {
        try {
            $row = DB::selectOne('SHOW server_version');

            return $row ? trim((string) $row->server_version) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Run pg_dump (custom format) into $targetPath. Returns the absolute path.
     *
     * @throws RuntimeException on failure
     */
    public function dump(string $targetPath, array $tables): string
    {
        $connection = config('database.connections.pgsql');

        if (! is_array($connection)) {
            throw new RuntimeException('pg_dump requires a pgsql connection.');
        }

        if (! is_dir(dirname($targetPath))) {
            mkdir(dirname($targetPath), 0775, true);
        }

        $command = [
            'pg_dump',
            '-U', (string) $connection['username'],
            '-h', (string) $connection['host'],
            '-p', (string) $connection['port'],
            '-d', (string) $connection['database'],
            '-Fc', // custom format — the only format pg_restore can parallel-restore
            '--no-owner',
            '--no-privileges',
            '-f', $targetPath,
        ];

        $timeout = (int) config('backup.process_timeouts.pg_dump', 3600);
        $env = [
            'PGPASSWORD' => (string) ($connection['password'] ?? ''),
            'PGSSLMODE' => (string) ($connection['sslmode'] ?? 'prefer'),
        ];

        $result = Process::timeout($timeout)
            ->path(base_path())
            ->env($env)
            ->run($command, function (string $type, string $output): void {
                if ($type === 'err' && trim($output) !== '') {
                    Log::warning('pg_dump stderr: '.trim($output));
                }
            });

        if ($result->exitCode() !== 0 || ! file_exists($targetPath) || filesize($targetPath) === 0) {
            $stderr = trim($result->errorOutput());
            @unlink($targetPath);

            throw new RuntimeException('pg_dump failed (exit '.$result->exitCode().'). '.$stderr);
        }

        return $targetPath;
    }

    /**
     * Cheap integrity probe of a dump file without restoring anything:
     * pg_restore --list must exit 0 and enumerate the archive.
     */
    public function verifyDump(string $dumpPath): bool
    {
        if (! file_exists($dumpPath)) {
            return false;
        }

        $timeout = (int) config('backup.process_timeouts.verify_dump', 600);

        $result = Process::timeout($timeout)->run(['pg_restore', '--list', $dumpPath]);

        return $result->exitCode() === 0 && trim($result->output()) !== '';
    }
}