<?php

namespace App\Services;

use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\User;
use App\Support\TokenSigner;
use Illuminate\Support\Facades\URL;

class FileProtectionService
{
    public const DEFAULT_TTL = 900; // 15 minutes

    /**
     * Short-lived, HMAC-signed file URL bound to a specific user + lesson.
     * Useless after expiry and cannot be replayed by another user.
     */
    public function fileUrl(Lesson $lesson, User $user, int $ttl = self::DEFAULT_TTL): string
    {
        return URL::to('/api/v1/lessons/'.$lesson->id.'/file?token='.$this->sign($lesson->id, $user->id, $ttl));
    }

    public function sign(string $lessonId, string $userId, int $ttl = self::DEFAULT_TTL): string
    {
        $payload = [
            'l' => $lessonId,
            'u' => $userId,
            'exp' => time() + $ttl,
            'n' => bin2hex(random_bytes(8)),
        ];

        $encoded = TokenSigner::base64UrlEncode(json_encode($payload));

        return $encoded.'.'.TokenSigner::sign($encoded);
    }

    /**
     * Verify a signed token and return the bound user, or null.
     */
    public function resolveUserFromToken(string $token, Lesson $lesson): ?User
    {
        $parts = explode('.', $token);

        if (count($parts) !== 2) {
            return null;
        }

        [$encoded, $signature] = $parts;

        if (! TokenSigner::matches($encoded, $signature)) {
            return null;
        }

        $decoded = json_decode(TokenSigner::base64UrlDecode($encoded), true);

        if (! is_array($decoded)) {
            return null;
        }

        if (($decoded['l'] ?? null) !== $lesson->id) {
            return null;
        }

        if (($decoded['exp'] ?? 0) < time()) {
            return null;
        }

        return User::find($decoded['u']);
    }

    /**
     * Access rule: super admin, tenant staff, enrolled student, or free-preview lesson.
     * Drip content uses the same unlock rules as every other content path.
     */
    public function canAccess(Lesson $lesson, ?User $user): bool
    {
        if (! $user) {
            return false;
        }

        if ($user->is_active === false) {
            return false;
        }

        if ($user->is_super_admin) {
            return true;
        }

        $isTenantStaff = $user->tenants()
            ->where('tenant_id', $lesson->tenant_id)
            ->wherePivotIn('role', ['teacher', 'owner', 'admin'])
            ->wherePivot('status', 'active')
            ->exists();

        if ($isTenantStaff) {
            return true;
        }

        $course = $lesson->section?->course;

        if (! $course) {
            return false;
        }

        $enrollment = Enrollment::where('course_id', $course->id)
            ->where('user_id', $user->id)
            ->whereIn('status', ['active', 'completed'])
            ->first();

        if (! $enrollment) {
            return false;
        }

        // Drip, prerequisites and expiry enforcement shared with videos,
        // text content and the progress controller.
        return app(CourseAccessService::class)->lessonUnlocked($enrollment, $lesson);
    }
}
