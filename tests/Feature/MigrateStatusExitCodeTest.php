<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Locks the premise of the deploy preflight fix against the REAL Laravel 13
 * command, so the reasoning cannot silently rot on a framework upgrade:
 *
 *   • `migrate:status --pending` prints migration names WITHOUT the `.php`
 *     extension (`Migrator::getMigrationName()` strips it), so a filename-shaped
 *     parser matches nothing and reports "no pending migrations"; and
 *   • its exit code is NOT a state signal. `handle()` ends with
 *     `return $this->option('pending')`, so `--pending` (no value → null) exits 0
 *     even while a migration IS pending, while `--pending=1` exits 1 for the very
 *     same state. The exit code tracks the option, not the database.
 *
 * The only non-zero case driven by real state is an unreadable repository. That
 * is why `db_pending_migrations()` now calls the read-only `migrations:pending`
 * command (see PendingMigrationsTest) and fails closed.
 */
class MigrateStatusExitCodeTest extends TestCase
{
    private string $dir;

    private string $file = '2099_12_31_235959_pending_example.php';

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = rtrim(sys_get_temp_dir(), '/').'/fahimni-migrate-status-'.uniqid();
        mkdir($this->dir, 0775, true);
        file_put_contents(
            $this->dir.'/'.$this->file,
            "<?php\nreturn new class {\n    public function up(): void {}\n    public function down(): void {}\n};\n"
        );
    }

    protected function tearDown(): void
    {
        @unlink($this->dir.'/'.$this->file);
        @rmdir($this->dir);

        parent::tearDown();
    }

    private function callStatus(array $extra = []): int
    {
        return Artisan::call('migrate:status', array_merge(['--realpath' => true, '--path' => [$this->dir]], $extra));
    }

    public function test_the_exit_code_tracks_the_option_value_not_the_pending_state(): void
    {
        // `--pending` with no value: the option value is null → exit 0 …
        $codeNoValue = $this->callStatus(['--pending' => null]);
        $this->assertStringContainsString('Pending', Artisan::output(), 'a migration really IS pending');

        // … while `--pending=1` returns 1 for the very same state.
        $codeOne = $this->callStatus(['--pending' => '1']);

        $this->assertSame(0, $codeNoValue, '`--pending` (no value) exits 0 even though a migration is pending');
        $this->assertSame(1, $codeOne, '`--pending=1` exits 1 for the same state — the code cannot gate the deploy');
    }

    public function test_pending_output_strips_the_php_extension(): void
    {
        $this->callStatus(['--pending' => null]);
        $out = Artisan::output();

        $this->assertStringContainsString(
            str_replace('.php', '', $this->file),
            $out,
            'the migration name is printed WITHOUT the .php extension'
        );
        $this->assertStringNotContainsString(
            $this->file,
            $out,
            'so a parser keyed on `.php` matches nothing — the original silent-pass defect'
        );
    }

    public function test_a_missing_migrations_table_is_the_reliable_failure_signal(): void
    {
        config()->set('database.connections.empty_state', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        DB::purge('empty_state');

        $code = $this->callStatus(['--pending' => null, '--database' => 'empty_state']);

        $this->assertNotSame(0, $code, 'an unreadable repository is the one case that does exit non-zero');
    }
}
