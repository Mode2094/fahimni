<?php

namespace App\Services;

use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\User;
use App\Models\VideoContent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Uid\UuidV7;
use Throwable;

/**
 * موضع المشاهدة (استكمال الفيديو) — يخزّن آخر ثانية وصل إليها الطالب في كل
 * فيديو، ليستكمل من حيث توقف حتى بعد إغلاق التطبيق أو من جهاز آخر.
 *
 * لا نبني نظام تقدّم موازياً: الموضع يعيش داخل `lesson_progress.metadata`
 * (نظام التقدّم القائم)، ولا يمسّ إطلاقاً احتساب دقائق المشاهدة ولا حالة
 * الإكمال ولا جلسات التشغيل المحمية. مجرّد نقطة استئناف.
 *
 *  - المفتاح: `metadata.video_positions[video_id] = { s, d, u }`
 *    (s = الموضع بالثواني، d = مدة الفيديو عند الحفظ، u = وقت الحفظ).
 *  - لكل طالب على حدة (`user_id` من الجلسة) — لا يمكن قراءة موضع طالب آخر.
 *  - رتيب (monotonic): لا نُتراجع للخلف أبداً، فلا يضيع تقدّم جهاز سابق عند
 *    وصول تحديث متأخر أو متزامن من جهاز آخر. إعادة التشغيل من البداية تبقى
 *    سلوكاً محلياً للجلسة الحالية فقط.
 *  - فيديو قارب على الانتهاء يُعدّ «منتهياً» فلا نعرض استئنافاً عند آخره.
 */
class VideoWatchPositionService
{
    /** أقل فرق يستحق الكتابة — نتجاهل النبضات الدقيقة المكرّرة. */
    private const MIN_PERSIST_DELTA_SECONDS = 2;

    /**
     * عنصر يحفظ `video_positions` داخل metadata التقدّم. لا نلمس باقي المفاتيح.
     */
    private function writeEntry(LessonProgress $progress, string $videoId, int $position, int $duration): void
    {
        $metadata = $progress->metadata ?? [];
        $metadata['video_positions'][$videoId] = [
            's' => $position,
            'd' => $duration,
            'u' => now()->toIso8601String(),
        ];
        $progress->metadata = $metadata;
    }

    /**
     * هل تغيّرت مدة الفيديو بما يكفي لنعتبر أنه أُعيد رفعه/استُبدل؟ عندها
     * الموضع القديم لم يعد معبّراً عن المحتوى الحالي.
     */
    private function durationChanged(int $stored, int $current): bool
    {
        if ($stored <= 0 || $current <= 0) {
            return false;
        }

        return abs($stored - $current) > max(60, (int) round($current * 0.1));
    }

    /** هل الموضع قريباً جداً من النهاية (فيديو منتهٍ)؟ */
    private function watchedToEnd(int $position, int $duration): bool
    {
        if ($duration <= 0) {
            return false;
        }

        return $position >= $duration - max(5, (int) round($duration * 0.02));
    }

    /**
     * الموضع المحفوظ للطالب في هذا الفيديو، أو null إن لم يوجد (أو صار غير
     * موثوق، أو كان الفيديو منتهياً).
     *
     * @return array{position_seconds:int, duration_seconds:int, updated_at:?string}|null
     */
    public function read(VideoContent $video, User $user): ?array
    {
        $enrollment = $this->enrollmentFor($video, $user);

        if (! $enrollment) {
            return null;
        }

        $lessonId = $this->lessonIdFor($video);

        if (! $lessonId) {
            return null;
        }

        $progress = LessonProgress::withoutTenant()
            ->where('enrollment_id', $enrollment->id)
            ->where('lesson_id', $lessonId)
            ->first();

        $entry = $progress?->metadata['video_positions'][$video->id] ?? null;

        if (! is_array($entry)) {
            return null;
        }

        $storedDuration = (int) ($entry['d'] ?? 0);
        $currentDuration = (int) $video->duration;

        // أُعيد رفع الفيديو بمدة مختلفة → الموضع القديم لا يعود معبّراً عن
        // المحتوى الحالي. نبدأ من جديد بدل القفز إلى موضع خاطئ.
        if ($this->durationChanged($storedDuration, $currentDuration)) {
            return null;
        }

        $position = max(0, (int) ($entry['s'] ?? 0));
        $duration = $storedDuration > 0 ? $storedDuration : $currentDuration;

        if ($duration > 0) {
            $position = min($position, $duration);
        }

        if ($position <= 0 || $this->watchedToEnd($position, $duration)) {
            return null;
        }

        return [
            'position_seconds' => $position,
            'duration_seconds' => $duration,
            'updated_at' => isset($entry['u']) ? (string) $entry['u'] : null,
        ];
    }

    /**
     * يحفظ موضع المشاهدة — idempotent، رتيب، وآمن تحت التزامن (قفل صف + دمج).
     * لا يرمي استثناء للفشل المتوقّع: يعيد null حتى لا يتعطّل تشغيل الفيديو.
     *
     * @return array{position_seconds:int, duration_seconds:int}|null
     */
    public function save(VideoContent $video, User $user, int $positionSeconds, ?int $durationSeconds = null): ?array
    {
        $enrollment = $this->enrollmentFor($video, $user);

        if (! $enrollment) {
            // معاينة/موظّف/غير مسجّل: لا نقطة استئناف شخصية تُحفظ.
            return null;
        }

        $lessonId = $this->lessonIdFor($video);

        if (! $lessonId) {
            return null;
        }

        $duration = $durationSeconds !== null && $durationSeconds > 0
            ? $durationSeconds
            : (int) $video->duration;

        $position = max(0, $positionSeconds);

        if ($duration > 0) {
            $position = min($position, $duration);
        }

        try {
            return DB::transaction(function () use ($video, $user, $enrollment, $lessonId, $position, $duration) {
                $progress = LessonProgress::withoutTenant()
                    ->where('enrollment_id', $enrollment->id)
                    ->where('lesson_id', $lessonId)
                    ->lockForUpdate()
                    ->first();

                if (! $progress) {
                    $progress = LessonProgress::create([
                        'id' => UuidV7::generate(),
                        'tenant_id' => $enrollment->tenant_id,
                        'enrollment_id' => $enrollment->id,
                        'lesson_id' => $lessonId,
                        'user_id' => $user->id,
                        'status' => 'in_progress',
                        'watch_time_seconds' => 0,
                        'metadata' => [],
                    ]);
                }

                $existing = $progress->metadata['video_positions'][$video->id] ?? null;
                $stored = is_array($existing) ? max(0, (int) ($existing['s'] ?? 0)) : null;
                $storedDuration = is_array($existing) ? (int) ($existing['d'] ?? 0) : 0;

                // الفيديو تغيّر (أُعيد رفعه بمدة مختلفة): الموضع القديم ملغى.
                if ($stored !== null && $this->durationChanged($storedDuration, $duration)) {
                    $stored = null;
                }

                // رتيب: لا نُتراجع للخلف أبداً → لا يضيع تقدّم جهاز آخر.
                $effective = $stored === null ? $position : max($stored, $position);

                // لا نكتب نبضة لا تقدم شيئاً (تخفيف الحمل على القاعدة).
                if ($stored !== null && $effective - $stored < self::MIN_PERSIST_DELTA_SECONDS) {
                    $this->markStarted($progress);
                    if ($progress->isDirty()) {
                        $progress->save();
                    }

                    return ['position_seconds' => $stored, 'duration_seconds' => $storedDuration > 0 ? $storedDuration : $duration];
                }

                if ($effective <= 0) {
                    // لا شيء يستحق الحفظ بعد (لم يبدأ المشاهدة فعلاً).
                    return ['position_seconds' => 0, 'duration_seconds' => $duration];
                }

                $this->writeEntry($progress, (string) $video->id, $effective, $duration);
                $this->markStarted($progress);
                $progress->save();

                return ['position_seconds' => $effective, 'duration_seconds' => $duration];
            });
        } catch (Throwable $e) {
            // فشل حفظ الموضع لا يجب أن يعطّل تشغيل الفيديو أبداً.
            Log::warning('video.watch_position_save_failed', [
                'event' => 'video_watch_position_save_failed',
                'video_id' => $video->id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /** بدء المشاهدة فقط — لا نلمس watch_time_seconds ولا الإكمال. */
    private function markStarted(LessonProgress $progress): void
    {
        if ($progress->status === 'not_started') {
            $progress->status = 'in_progress';
        }
    }

    private function lessonIdFor(VideoContent $video): ?string
    {
        if ($video->lesson_id) {
            return (string) $video->lesson_id;
        }

        return $video->lessonContent?->lesson_id
            ? (string) $video->lessonContent->lesson_id
            : null;
    }

    /**
     * تسجيل الطالب الفعّال في كورس هذا الفيديو — نفس قاعدة الوصول المستخدمة
     * في التشغيل، فلا يُحفظ/يُقرأ موضع لطالب غير مصرّح له.
     */
    private function enrollmentFor(VideoContent $video, User $user): ?Enrollment
    {
        $lesson = $this->lessonFor($video);

        if (! $lesson || ! $lesson->section) {
            return null;
        }

        return Enrollment::withoutTenant()
            ->where('course_id', $lesson->section->course_id)
            ->where('user_id', $user->id)
            ->effective()
            ->first();
    }

    private function lessonFor(VideoContent $video): ?Lesson
    {
        if ($video->lesson_id) {
            return Lesson::withoutTenant()->with('section')->find($video->lesson_id);
        }

        $content = $video->lessonContent()->with('lesson.section')->first();

        return $content?->lesson;
    }
}
