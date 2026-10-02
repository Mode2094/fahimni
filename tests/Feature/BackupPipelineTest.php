<?php

namespace Tests\Feature;

use App\Models\Backup;
use App\Models\User;
use App\Services\Backup\BackupCipher;
use App\Services\Backup\BackupManager;
use App\Services\Backup\DatabaseDumper;
use App\Services\Backup\Verifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BackupPipelineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('backup');
        config()->set('backup.encryption_key', 'test-backup-encryption-key');

        // Inject a fake dumper so the pipeline is exercised against a real
        // filesystem (tar, openssl, checksums, verification) without needing
        // a PostgreSQL server. The dump file it writes is real bytes, so
        // checksums + presence checks behave exactly like production.
        $fakeDumper = new class extends DatabaseDumper
        {
            public function listTables(): array
            {
                return ['public.users', 'public.courses', 'public.payments', 'public.media_assets'];
            }

            public function dump(string $targetPath, array $tables): string
            {
                file_put_contents($targetPath, "FAKE-PGDUMP-".implode(',', $tables)."\n");

                return $targetPath;
            }

            public function verifyDump(string $dumpPath): bool
            {
                return is_file($dumpPath) && filesize($dumpPath) > 0;
            }

            public function databaseSizeBytes(): int
            {
                return 4096;
            }

            public function databaseVersion(): ?string
            {
                return '16.3';
            }
        };

        $this->app->instance(DatabaseDumper::class, $fakeDumper);
    }

    private function manager(): BackupManager
    {
        return $this->app->make(BackupManager::class);
    }

    public function test_full_pipeline_produces_a_completed_restore_ready_package(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_super_admin' => true])->save();
        $backup = $this->manager()->createRecord('manual', $admin->id);

        $this->assertSame('queued', $backup->status);

        $this->manager()->run($backup);

        $backup->refresh();

        $this->assertSame('completed', $backup->status);
        $this->assertSame(100, $backup->progress);
        $this->assertNotNull($backup->verified_at);
        $this->assertSame(4, count($backup->tables));

        // Every expected artifact exists on the backup disk.
        $disk = Storage::disk('backup');
        foreach (['database.dump', 'files.tar.gz', 'configuration.enc', 'manifest.json', 'checksums.json', 'system-info.json'] as $file) {
            $this->assertTrue($disk->exists($backup->backup_id.'/'.$file), "Missing artifact: {$file}");
        }

        // Checksums are real SHA-256 digests.
        $this->assertSame('sha256', $backup->checksums_data['algorithm']);
        $this->assertNotEmpty($backup->checksums_data['package_sha256']);
        $this->assertCount(4, $backup->checksums_data['files']);

        // Manifest carries the expected non-secret metadata.
        $this->assertSame($backup->backup_id, $backup->manifest_data['backup_id']);
        $this->assertSame($backup->checksums_data['package_sha256'], $backup->manifest_data['checksum']);
        $this->assertArrayHasKey('database_engine', $backup->manifest_data);
        $this->assertArrayHasKey('laravel_version', $backup->manifest_data);
        $this->assertArrayHasKey('file_count', $backup->manifest_data);

        // Verification report recorded and clean.
        $this->assertTrue($backup->verification['ok']);
        $this->assertNotEmpty($backup->verification['messages']);

        // Size recorded.
        $this->assertGreaterThan(0, $backup->size_bytes);
    }

    public function test_configuration_encrypts_and_contains_env_without_leaking_to_manifest(): void
    {
        $backup = $this->manager()->createRecord('manual', null);
        $this->manager()->run($backup);
        $backup->refresh();

        $dir = Storage::disk('backup')->path($backup->backup_id);
        $enc = file_get_contents($dir.'/configuration.enc');

        // Encrypted payload must not contain the plaintext env or secrets.
        $this->assertStringNotContainsString('DB_PASSWORD=', $enc);
        $this->assertStringStartsWith('SHAHEAD-ENC:v1:aes-256-gcm:', $enc);

        // Round-trip decrypts and carries the env file + media reference note.
        $json = json_decode(BackupCipher::decrypt($enc), true);
        $this->assertArrayHasKey('env_file', $json);
        $this->assertArrayHasKey('sensitive_keys', $json);
        $this->assertTrue($json['media']['video_files_included'] === false);
        $this->assertStringContainsString('video_references_in_database', json_encode($json));

        // Manifest must be clean of secrets.
        $manifestRaw = file_get_contents($dir.'/manifest.json');
        foreach (['DB_PASSWORD', 'APP_KEY=', 'SECRET', 'API_KEY', 'TOKEN'] as $needle) {
            $this->assertStringNotContainsString($needle, $manifestRaw);
        }
    }

    public function test_backup_cipher_round_trip(): void
    {
        $plaintext = json_encode(['a' => 1, 'b' => 'secret-value-here']);
        $encrypted = BackupCipher::encrypt($plaintext);

        $this->assertSame($plaintext, BackupCipher::decrypt($encrypted));
        $this->assertTrue(BackupCipher::canDecrypt($encrypted));

        // Tampering must fail decryption (GCM authenticated).
        $parts = explode(':', $encrypted);
        $parts[5] = base64_encode('tampered');
        $tampered = implode(':', $parts);
        $this->assertFalse(BackupCipher::canDecrypt($tampered));
    }

    public function test_failed_dump_marks_backup_failed(): void
    {
        $this->app->instance(DatabaseDumper::class, new class extends DatabaseDumper
        {
            public function listTables(): array
            {
                return ['public.users'];
            }

            public function dump(string $targetPath, array $tables): string
            {
                throw new \RuntimeException('pg_dump failed: connection refused');
            }
        });

        $backup = $this->manager()->createRecord('manual', null);

        try {
            $this->manager()->run($backup);
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('connection refused', $e->getMessage());
        }

        $backup->refresh();
        $this->assertSame('failed', $backup->status);
        $this->assertNotNull($backup->finished_at);
        $this->assertStringContainsString('connection refused', (string) $backup->error_message);
    }

    public function test_verification_failure_flips_status_to_failed(): void
    {
        // dump succeeds but verification fails → the package is not trustworthy.
        $this->app->instance(DatabaseDumper::class, new class extends DatabaseDumper
        {
            public function listTables(): array
            {
                return ['public.users'];
            }

            public function dump(string $targetPath, array $tables): string
            {
                file_put_contents($targetPath, "FAKE\n");

                return $targetPath;
            }

            public function verifyDump(string $dumpPath): bool
            {
                return false;
            }
        });

        $backup = $this->manager()->createRecord('manual', null);
        $this->manager()->run($backup);

        $backup->refresh();
        $this->assertSame('failed', $backup->status);
        $this->assertSame('verification_failed', $backup->stage);
        $this->assertNotEmpty($backup->verification['errors']);
    }

    public function test_retention_keeps_only_latest_n_completed_backups(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_super_admin' => true])->save();

        // Seed 9 completed backups (older first).
        $backups = [];
        for ($i = 0; $i < 9; $i++) {
            $backup = $this->manager()->createRecord('scheduled', null);
            $backup->forceFill([
                'status' => 'completed',
                'created_at' => now()->subDays(9 - $i),
            ])->save();
            $backups[] = $backup;
            Storage::disk('backup')->put($backup->backup_id.'/database.dump', 'x');
        }

        // Default retention is 7. Flush the array cache so a value set by
        // another test in the same process (settings test sets 14) cannot leak.
        \Illuminate\Support\Facades\Cache::flush();
        $pruned = app(\App\Services\Backup\RetentionService::class)->prune($admin->id);

        $this->assertCount(2, $pruned);
        $this->assertSame(7, Backup::where('status', 'completed')->count());
        $this->assertSame(2, Backup::where('status', 'deleted')->count());

        // The two oldest physical packages are gone.
        $oldest = $backups[0]->backup_id;
        $this->assertFalse(Storage::disk('backup')->exists($oldest.'/database.dump'));
    }
}