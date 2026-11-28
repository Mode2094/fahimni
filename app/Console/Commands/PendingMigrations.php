<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migrator;
use Throwable;

/**
 * Read-only: print the migration FILES that have not been run yet, one filename
 * per line, INCLUDING the `.php` extension.
 *
 * Why this exists (deploy safety)
 * ------------------------------
 * The deploy preflight must compare "what is pending" against an explicit
 * allowlist. It cannot rely on `migrate:status --pending`:
 *
 *   • Laravel's `Migrator::getMigrationName()` strips `.php`, so the CLI prints
 *     migration names WITHOUT the extension — a filename-shaped parser silently
 *     matches nothing and reports "no pending migrations";
 *   • `migrate:status --pending`'s exit code is not a reliable "error" signal
 *     (`handle()` returns the option value, so it can be 0 with pending
 *     migrations, 1 with `--pending=1`, and 1 when the table is missing).
 *
 * This command instead diffs the migration FILES on disk against the `migrations`
 * table directly, so the output is exactly the release's file basenames — the same
 * strings the allowlist carries — regardless of framework version.
 *
 * Paths follow Laravel 13's own `BaseCommand::getMigrationPaths()`: an explicit
 * `--path` wins, otherwise the migrator's registered paths are MERGED with
 * `database_path('migrations')` so a custom path can never hide the app's own
 * migrations.
 *
 * It NEVER writes: no migration is run, no row is inserted or updated.
 *
 * Exit codes: 0 = the list was produced (possibly empty);
 *             non-zero = the state could not be determined (fail closed).
 */
class PendingMigrations extends Command
{
    protected $signature = 'migrations:pending
                            {--database= : the database connection to inspect}
                            {--path=* : migration paths used verbatim (defaults to the merged Laravel paths)}';

    protected $description = 'List the migration files (.php) that have not been run yet. Read-only; never touches the schema.';

    public function handle(Migrator $migrator): int
    {
        return $migrator->usingConnection($this->option('database'), function () use ($migrator) {
            try {
                if (! $migrator->repositoryExists()) {
                    $this->error('the migrations table was not found — cannot determine pending migrations');

                    return self::FAILURE;
                }

                $paths = $this->migrationPaths($migrator);

                // [ migration name (no .php) => absolute file path ]
                $files = $migrator->getMigrationFiles($paths);
                $ran = $migrator->getRepository()->getRan();
            } catch (Throwable $e) {
                $this->error('could not read the migration state: '.$e->getMessage());

                return self::FAILURE;
            }

            $pending = [];
            foreach ($files as $path) {
                $name = $migrator->getMigrationName($path);
                if (! in_array($name, $ran, true)) {
                    // The file basename, WITH the .php extension — what the deploy
                    // allowlist and `--path=database/migrations/<file>` both use.
                    $pending[] = basename($path);
                }
            }
            sort($pending);

            foreach ($pending as $file) {
                $this->line($file);
            }

            return self::SUCCESS;
        });
    }

    /**
     * The migration paths to inspect.
     *
     * Mirrors Laravel 13's `BaseCommand::getMigrationPaths()`: an explicit
     * `--path` wins (used verbatim, as the caller passed it); otherwise the
     * defaults are the paths registered on the migrator **merged with** the
     * app's `database/migrations`. Using only one of the two would hide real
     * migrations — a custom registered path would mask the app's own ones, or
     * vice versa — so both are always included and de-duplicated.
     *
     * @return string[]
     */
    private function migrationPaths(Migrator $migrator): array
    {
        $paths = array_values(array_filter((array) $this->option('path')));

        if ($paths !== []) {
            return $paths;
        }

        return array_values(array_unique(array_merge(
            $migrator->paths(),
            [database_path('migrations')],
        )));
    }
}
