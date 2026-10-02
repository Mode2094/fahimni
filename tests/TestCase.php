<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    private static bool $schemaBuilt = false;

    /**
     * The app container runs inside Docker where the .env values are
     * injected as real process variables (env_file in docker-compose).
     * Those win over phpunit.xml's <env> entries, so:
     *  1. block the RefreshDatabase trait from migrating on the
     *     production Postgres connection (it would read the process
     *     environment before we can override the config),
     *  2. force a file-based SQLite database (in-memory databases are
     *     dropped between tests), migrate it once, and wrap every test
     *     in a transaction so tests stay isolated and fast.
     *
     * The SQLite file is stored in the container-local temp dir (NOT the
     * repo's tests/ directory, which sits on a Docker bind-mount). SQLite
     * on Docker-Desktop bind mounts is prone to "database disk image is
     * malformed" corruption when many tests write sequentially — keeping
     * the file out of the bind mount makes the whole suite deterministic.
     */
    private static function testDatabasePath(): string
    {
        return rtrim(sys_get_temp_dir(), '/').'/shahead-lms-tests/database.sqlite';
    }

    protected function setUp(): void
    {
        $path = self::testDatabasePath();
        $dir = dirname($path);

        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        // A crashed run can leave a stale/locked file behind; reset it once
        // per process so migrate:fresh always starts from a clean slate.
        if (! self::$schemaBuilt) {
            foreach ([$path, $path.'-journal', $path.'-wal', $path.'-shm'] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }

        $_ENV['APP_ENV'] = 'testing';
        $_ENV['DB_CONNECTION'] = 'sqlite';
        $_ENV['DB_DATABASE'] = $path;
        putenv('APP_ENV=testing');
        putenv('DB_CONNECTION=sqlite');
        putenv('DB_DATABASE='.$path);

        parent::setUp();

        config()->set('app.env', 'testing');
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', $path);

        // Force the queue to sync REGARDLESS of a cached config (config:cache
        // freezes queue.default to the production driver, which would leak
        // test-dispatched jobs into the real Redis queue — those jobs carry
        // sqlite-serialized models and crash the production Horizon worker).
        config()->set('queue.default', 'sync');
        config()->set('mail.default', 'array');

        if (! self::$schemaBuilt) {
            $this->artisan('migrate:fresh', ['--force' => true]);
            self::$schemaBuilt = true;
        }

        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        if (DB::connection()->transactionLevel() > 0) {
            DB::rollBack();
        }

        parent::tearDown();
    }
}