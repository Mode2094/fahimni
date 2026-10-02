<?php

namespace Tests\Feature;

use App\Jobs\CreateBackupJob;
use App\Models\Backup;
use App\Models\PlatformSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BackupApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('backup');
        config()->set('backup.encryption_key', 'test-backup-encryption-key');
        Queue::fake();
    }

    private function makeAdmin(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['is_super_admin' => true])->save();

        return $user->fresh();
    }

    private function makeTeacher(): User
    {
        return User::factory()->create(['is_super_admin' => false]);
    }

    private function headers(User $user): array
    {
        $this->app['auth']->forgetGuards();

        return [
            'Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken,
            'Accept' => 'application/json',
        ];
    }

    private function makeCompletedBackup(?User $admin = null): Backup
    {
        $backup = Backup::create([
            'backup_id' => 'SHAHEAD_BACKUP_2026-08-19_000000_test',
            'status' => 'completed',
            'stage' => 'completed',
            'progress' => 100,
            'reason' => 'manual',
            'created_by' => $admin?->id,
            'database_dump' => 'database.dump',
            'files_archive' => 'files.tar.gz',
            'config_archive' => 'configuration.enc',
            'manifest' => 'manifest.json',
            'checksums' => 'checksums.json',
            'system_info' => 'system-info.json',
            'size_bytes' => 1234,
        ]);

        foreach ($backup->artifactFilenames() as $name) {
            Storage::disk('backup')->put($backup->backup_id.'/'.$name, 'content-'.$name);
        }

        return $backup;
    }

    public function test_teacher_cannot_list_backups(): void
    {
        $this->getJson('/api/v1/admin/backups', $this->headers($this->makeTeacher()))
            ->assertForbidden();
    }

    public function test_student_cannot_access_backups(): void
    {
        $student = User::factory()->create();
        $this->getJson('/api/v1/admin/backups', $this->headers($student))->assertForbidden();
        $this->postJson('/api/v1/admin/backups', [], $this->headers($student))->assertForbidden();
    }

    public function test_unauthenticated_cannot_access_backups(): void
    {
        $this->getJson('/api/v1/admin/backups')->assertUnauthorized();
    }

    public function test_super_admin_can_create_backup_and_job_is_dispatched(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->postJson('/api/v1/admin/backups', [], $this->headers($admin));

        $response->assertStatus(202);
        $response->assertJsonPath('backup.status', 'queued');

        Queue::assertPushed(CreateBackupJob::class);

        $this->assertDatabaseHas('backups', [
            'backup_id' => $response->json('backup.backup_id'),
            'status' => 'queued',
        ]);
    }

    public function test_second_backup_is_rejected_while_one_is_in_progress(): void
    {
        $admin = $this->makeAdmin();

        Backup::create([
            'backup_id' => 'SHAHEAD_BACKUP_2026-08-19_000000_running',
            'status' => 'running',
            'reason' => 'manual',
        ]);

        $this->postJson('/api/v1/admin/backups', [], $this->headers($admin))->assertStatus(409);
    }

    public function test_super_admin_can_list_and_view_backups(): void
    {
        $admin = $this->makeAdmin();
        $backup = $this->makeCompletedBackup($admin);

        $list = $this->getJson('/api/v1/admin/backups', $this->headers($admin));
        $list->assertOk();
        $list->assertJsonCount(1, 'backups');
        $list->assertJsonPath('backups.0.status', 'completed');

        $show = $this->getJson("/api/v1/admin/backups/{$backup->id}", $this->headers($admin));
        $show->assertOk();
        $show->assertJsonPath('backup.backup_id', $backup->backup_id);
        $show->assertJsonPath('backup.size', '1.2 KB');
    }

    public function test_backup_download_requires_super_admin(): void
    {
        $backup = $this->makeCompletedBackup();

        $this->getJson("/api/v1/admin/backups/{$backup->id}/download", $this->headers($this->makeTeacher()))
            ->assertForbidden();
    }

    public function test_backup_download_streams_artifact_for_super_admin(): void
    {
        $admin = $this->makeAdmin();
        $backup = $this->makeCompletedBackup($admin);

        $this->get("/api/v1/admin/backups/{$backup->id}/download/database.dump", $this->headers($admin))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/octet-stream')
            ->assertStreamedContent('content-database.dump');
    }

    public function test_signed_download_works_without_bearer_token(): void
    {
        $admin = $this->makeAdmin();
        $backup = $this->makeCompletedBackup($admin);

        $expires = now()->addHour()->timestamp;
        $sig = $backup->signedDownloadToken('database.dump', $expires);

        $this->get("/api/v1/admin/backups/{$backup->backup_id}/signed-download?artifact=database.dump&expires={$expires}&sig={$sig}")
            ->assertOk()
            ->assertStreamedContent('content-database.dump');
    }

    public function test_signed_download_rejects_invalid_signature(): void
    {
        $backup = $this->makeCompletedBackup();
        $expires = now()->addHour()->timestamp;

        $this->get("/api/v1/admin/backups/{$backup->backup_id}/signed-download?artifact=database.dump&expires={$expires}&sig=bad")
            ->assertForbidden();
    }

    public function test_signed_download_rejects_expired_link(): void
    {
        $backup = $this->makeCompletedBackup();
        $expires = now()->subMinute()->timestamp;
        $sig = $backup->signedDownloadToken('database.dump', $expires);

        $this->get("/api/v1/admin/backups/{$backup->backup_id}/signed-download?artifact=database.dump&expires={$expires}&sig={$sig}")
            ->assertForbidden();
    }

    public function test_super_admin_can_delete_backup(): void
    {
        $admin = $this->makeAdmin();
        $backup = $this->makeCompletedBackup($admin);

        $this->deleteJson("/api/v1/admin/backups/{$backup->id}", [], $this->headers($admin))->assertOk();

        $backup->refresh();
        $this->assertSame('deleted', $backup->status);
        $this->assertFalse(Storage::disk('backup')->exists($backup->backup_id.'/database.dump'));

        // Deleted backups are hidden from the default list.
        $this->getJson('/api/v1/admin/backups', $this->headers($admin))
            ->assertJsonCount(0, 'backups');
    }

    public function test_teacher_cannot_delete_backup(): void
    {
        $backup = $this->makeCompletedBackup();
        $this->deleteJson("/api/v1/admin/backups/{$backup->id}", [], $this->headers($this->makeTeacher()))
            ->assertForbidden();
    }

    public function test_settings_require_super_admin_and_persist(): void
    {
        $admin = $this->makeAdmin();

        $get = $this->getJson('/api/v1/admin/backups/settings', $this->headers($admin));
        $get->assertOk();
        $get->assertJsonPath('settings.automatic_enabled', false);
        $get->assertJsonPath('settings.retention_count', 7);

        $put = $this->putJson('/api/v1/admin/backups/settings', [
            'automatic_enabled' => true,
            'frequency' => 'weekly',
            'time' => '03:30',
            'weekly_day' => 2,
            'retention_count' => 14,
        ], $this->headers($admin));

        $put->assertOk();
        $put->assertJsonPath('settings.automatic_enabled', true);
        $put->assertJsonPath('settings.frequency', 'weekly');
        $put->assertJsonPath('settings.retention_count', 14);

        $this->assertTrue(PlatformSetting::bool('backup_automatic_enabled'));
        $this->assertSame('weekly', PlatformSetting::get('backup_frequency'));
        $this->assertSame(14, (int) PlatformSetting::get('backup_retention_count'));

        // Non-admin cannot change settings.
        $this->putJson('/api/v1/admin/backups/settings', ['retention_count' => 5], $this->headers($this->makeTeacher()))
            ->assertForbidden();
    }

    public function test_verify_endpoint_dispatches_reverification(): void
    {
        $admin = $this->makeAdmin();
        $backup = $this->makeCompletedBackup($admin);

        $this->postJson("/api/v1/admin/backups/{$backup->id}/verify", [], $this->headers($admin))
            ->assertStatus(202)
            ->assertJsonPath('backup.status', 'verifying');

        $backup->refresh();
        $this->assertSame('verifying', $backup->status);
    }
}