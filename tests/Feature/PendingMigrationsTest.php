<?php

namespace Tests\Feature;

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `migrations:pending` backs the deploy preflight. It must report the migration
 * FILES (with the `.php` extension) that have not been run, and it must FAIL
 * CLOSED whenever the state cannot be determined — never silently report
 * "nothing pending", which is what the old `migrate:status --pending` parsing did
 * on Laravel 13 (it strips `.php` from every name, so a filename-shaped parser
 * matched nothing).
 *
 * The `migrations` table stores names WITHOUT the `.php` extension (that is what
 * Laravel's `Migrator::getMigrationName()` / `getRan()` produce), so the fixtures
 * below mirror that real Laravel-13 shape on purpose.
 */
class PendingMigrationsTest extends TestCase
{
    private string $dir;

    private string $ranFile = '2020_01_01_000000_already_ran_example.php';

    private string $pendingFile = '2099_12_31_235959_never_run_example.php';

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = rtrim(sys_get_temp_dir(), '/').'/fahimni-pending-'.uniqid();
        if (! is_dir($this->dir)) {
            mkdir($this->dir, 0775, true);
        }

        $stub = "<?php\nreturn new class {\n    public function up(): void {}\n    public function down(): void {}\n};\n";
        file_put_contents($this->dir.'/'.$this->ranFile, $stub);
        file_put_contents($this->dir.'/'.$this->pendingFile, $stub);
    }
    protected function tearDown(): void
    {
        foreach ([$this->ranFile, $this->pendingFile] as $f) {
            @unlink($this->dir.'/'.$f);
        }
        @rmdir($this->dir);

        parent::tearDown();
    }

    /** The `migrations` table stores names WITHOUT `.php` — the real Laravel shape. */
    private function markRan(string $filename): void
    {
        DB::table('migrations')->insert([
            'migration' => str_replace('.php', '', $filename),
            'batch' => 1,
        ]);
    }

    private function runCommand(array $args = []): array
    {
        $code = Artisan::call('migrations:pending', array_merge(['--path' => [$this->dir]], $args));

        return [$code, trim(Artisan::output())];
    }

    public function test_lists_pending_files_with_the_php_extension(): void
    {
        $this->markRan($this->ranFile);

        [$code, $out] = $this->runCommand();

        $this->assertSame(0, $code, 'a readable state must exit 0');
        $this->assertStringContainsString($this->pendingFile, $out, 'the pending file must be listed with its .php extension');
        $this->assertStringNotContainsString($this->ranFile, $out, 'an already-run migration must not be listed');

        // Every emitted line is a migration filename WITH .php — nothing else.
        foreach (array_filter(explode("\n", $out)) as $line) {
            $this->assertMatchesRegularExpression('/^\d{4}_\d{2}_\d{2}_\d{6}_[A-Za-z0-9_]+\.php$/', $line);
        }
    }

    public function test_every_pending_file_is_reported_verbatim(): void
    {
        // Nothing ran: both files are pending, reported verbatim (with .php) in
        // sorted order so the deploy allowlist can match them exactly.
        [$code, $out] = $this->runCommand();

        $this->assertSame(0, $code);
        $this->assertSame(
            [$this->ranFile, $this->pendingFile],
            array_values(array_filter(explode("\n", $out))),
            'both pending files must be listed verbatim, sorted'
        );
    }

    public function test_reports_zero_pending_when_everything_ran(): void
    {
        $this->markRan($this->ranFile);
        $this->markRan($this->pendingFile);

        [$code, $out] = $this->runCommand();

        $this->assertSame(0, $code, 'a clean state must exit 0');
        $this->assertSame('', $out, 'no pending migration must produce no output');
    }

    public function test_fails_closed_when_the_migrations_table_is_missing(): void
    {
        config()->set('database.connections.empty_state', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        DB::purge('empty_state');

        [$code, $out] = $this->runCommand(['--database' => 'empty_state']);

        $this->assertNotSame(0, $code, 'an unreadable migration state must exit non-zero');
        $this->assertStringNotContainsString('.php', $out, 'nothing may be reported when the state is unknown');
    }

    public function test_never_writes_to_the_migrations_table(): void
    {
        $this->markRan($this->ranFile);
        $before = DB::table('migrations')->orderBy('migration')->pluck('migration')->all();

        [$code] = $this->runCommand();

        $this->assertSame(0, $code);
        $this->assertSame($before, DB::table('migrations')->orderBy('migration')->pluck('migration')->all(), 'the command must be read-only');
    }

    /**
     * The default path set must be Laravel 13's: the migrator's registered paths
     * MERGED with `database_path('migrations')`. Registering an extra path must
     * not hide the app's own migrations (the old code used one OR the other).
     */
    public function test_default_paths_merge_registered_paths_with_database_migrations(): void
    {
        $extraDir = rtrim(sys_get_temp_dir(), '/').'/fahimni-extra-path-'.uniqid();
        mkdir($extraDir, 0775, true);
        $extraFile = '2099_01_01_000000_extra_registered_path_example.php';
        file_put_contents(
            $extraDir.'/'.$extraFile,
            "<?php\nreturn new class {\n    public function up(): void {}\n    public function down(): void {}\n};\n"
        );

        // Register the extra path on the SAME migrator instance the command resolves.
        app(Migrator::class)->path($extraDir);

        // Guard: `paths()` is now non-empty, so the old
        // `$migrator->paths() ?: [database_path('migrations')]` fallback would have
        // inspected ONLY this custom path and lost the app's own migrations.
        $this->assertNotEmpty(
            app(Migrator::class)->paths(),
            'the custom path must be registered for this test to be meaningful'
        );

        // A real migration that is on disk but not yet recorded as ran.
        $coreFile = '2026_11_16_000001_add_lesson_id_to_assignments.php';
        DB::table('migrations')->where('migration', str_replace('.php', '', $coreFile))->delete();

        try {
            // No --path: the command must fall back to the merged defaults.
            $code = Artisan::call('migrations:pending');
            $out = Artisan::output();

            $this->assertSame(0, $code);
            $this->assertStringContainsString(
                $extraFile,
                $out,
                'a file from a registered custom path must be reported'
            );
            $this->assertStringContainsString(
                $coreFile,
                $out,
                'database/migrations must NOT be dropped when custom paths are registered'
            );
        } finally {
            @unlink($extraDir.'/'.$extraFile);
            @rmdir($extraDir);
        }
    }
}
