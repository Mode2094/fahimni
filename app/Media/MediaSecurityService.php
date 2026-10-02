<?php

namespace App\Media;

use App\Models\Enrollment;
use App\Models\LessonContent;
use App\Models\MediaAsset;
use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * أمان الوصول للوسائط — لا يصل أي شخص لفيديو خارج صلاحياته:
 *  - الملف مسجل في قاعدة البيانات (لا استخدام مباشر لمعرّف المزود).
 *  - الطالب: تسجيل فعّال بالكورس + الدرس ضمن الكورس + حالة الأصل ready.
 *  - المعلم/الأدمن: ملكية الأصل أو صلاحية عامة.
 *  - الروابط: موقعة ومنتهية الصلاحية عبر المزود.
 */
class MediaSecurityService
{
    public function __construct(
        private readonly MediaProviderManager $manager,
    ) {}

    /**
     * هل يستطيع المستخدم مشاهدة أصل وسائط معين؟
     */
    public function canAccess(MediaAsset $asset, User $user): bool
    {
        // لا يُقدم أبداً أصل غير جاهز.
        if ($asset->status !== MediaAsset::STATUS_READY) {
            return false;
        }

        // الأدمن العام يصل دائماً.
        if ($user->is_super_admin) {
            return true;
        }

        // مالك الأصل (المعلم) يصل.
        if ($asset->user_id !== null && (string) $asset->user_id === (string) $user->id) {
            return true;
        }

        // طاقم المؤسسة المالكة يصل.
        if ($this->isTenantStaff($asset->tenant_id, $user)) {
            return true;
        }

        // الطالب: يجب أن يكون الأصل مرتبطاً بكورس أو درس.
        if ($asset->lesson_content_id === null && $asset->course_id === null) {
            return false;
        }

        return $this->studentEnrolledInAssetCourse($asset, $user);
    }

    /**
     * رابط تشغيل آمن (موقّع) لأصل جاهز — يرجع null عند الرفض.
     */
    public function signedPlayback(MediaAsset $asset, ?User $user = null, int $ttlSeconds = 900): ?array
    {
        if ($asset->status !== MediaAsset::STATUS_READY) {
            return [
                'status' => $asset->status,
                'stream_url' => null,
                'expires_in' => 0,
            ];
        }

        try {
            $driver = $this->manager->provider($asset->provider);

            return [
                'status' => 'ready',
                'stream_url' => $driver->signedPlaybackUrl($asset->provider_asset_id, (string) ($user?->id ?? 'guest'), $ttlSeconds),
                'thumbnail_url' => $driver->thumbnailUrl($asset->provider_asset_id, (string) ($user?->id ?? 'guest'), $ttlSeconds),
                'expires_in' => $ttlSeconds,
                'provider' => $asset->provider,
            ];
        } catch (\Throwable $e) {
            Log::warning('media.security.playback_failed', [
                'asset' => $asset->id,
                'error' => $e->getMessage(),
            ]);

            return ['status' => 'error', 'stream_url' => null, 'expires_in' => 0];
        }
    }

    private function studentEnrolledInAssetCourse(MediaAsset $asset, User $user): bool
    {
        $courseId = $asset->course_id;

        if ($courseId === null && $asset->lesson_content_id !== null) {
            $lesson = LessonContent::withoutTenant()->find($asset->lesson_content_id)?->lesson;
            $courseId = $lesson?->course_id;
        }

        if ($courseId === null) {
            return false;
        }

        return Enrollment::withoutTenant()
            ->where('course_id', $courseId)
            ->where('user_id', $user->id)
            ->whereIn('status', ['active', 'completed'])
            ->exists();
    }

    private function isTenantStaff(string $tenantId, User $user): bool
    {
        return TenantUser::withoutTenant()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $user->id)
            ->whereIn('role', ['teacher', 'owner', 'admin'])
            ->where('status', 'active')
            ->exists();
    }
}
