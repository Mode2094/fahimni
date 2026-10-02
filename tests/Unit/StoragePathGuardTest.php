<?php

namespace Tests\Unit;

use App\Support\StoragePathGuard;
use PHPUnit\Framework\TestCase;

class StoragePathGuardTest extends TestCase
{
    public function test_rejects_traversal(): void
    {
        $this->assertFalse(StoragePathGuard::isSafe('../../.env', ['lesson-files']));
        $this->assertFalse(StoragePathGuard::isSafe('lesson-files/a/../../.env', ['lesson-files']));
        $this->assertFalse(StoragePathGuard::isSafe('../.env', ['lesson-files']));
        $this->assertFalse(StoragePathGuard::isSafe('....env', ['lesson-files']));
    }

    public function test_rejects_absolute_and_drive_paths(): void
    {
        $this->assertFalse(StoragePathGuard::isSafe('/etc/passwd', ['lesson-files']));
        $this->assertFalse(StoragePathGuard::isSafe('C:/Windows/system32', ['lesson-files']));
        $this->assertFalse(StoragePathGuard::isSafe('C:\\Windows\\system32', ['lesson-files']));
    }

    public function test_rejects_paths_outside_allowlist(): void
    {
        $this->assertFalse(StoragePathGuard::isSafe('backups/dump.sql', ['lesson-files']));
        $this->assertFalse(StoragePathGuard::isSafe('videos/x.mp4', ['lesson-files']));
    }

    public function test_accepts_paths_inside_allowlist(): void
    {
        $this->assertTrue(StoragePathGuard::isSafe('lesson-files/abc-123/handout.pdf', ['lesson-files']));
        $this->assertTrue(StoragePathGuard::isSafe('videos/abc-123/clip.mp4', ['videos']));
        $this->assertTrue(StoragePathGuard::isSafe('uploads/abc-123.png', ['lesson-files', 'uploads']));
    }

    public function test_rejects_empty_or_missing_path(): void
    {
        $this->assertFalse(StoragePathGuard::isSafe('', ['lesson-files']));
        $this->assertFalse(StoragePathGuard::isSafe(null, ['lesson-files']));
    }
}
