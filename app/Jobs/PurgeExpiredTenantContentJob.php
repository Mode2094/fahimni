<?php

namespace App\Jobs;

use App\Models\Announcement;
use App\Models\Assignment;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Exam;
use App\Models\ExamQuestion;
use App\Models\ExamSection;
use App\Models\FileContent;
use App\Models\Lesson;
use App\Models\LessonContent;
use App\Models\LiveSession;
use App\Models\Section;
use App\Models\TenantSubscription;
use App\Models\TenantUser;
use App\Models\User;
use App\Plans\PlanEngine;
use App\Services\NotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Deletes a tenant's educational content when 10 days pass after
 * subscription expiry without renewal.
 *
 * Models with SoftDeletes are soft-deleted (recoverable); the rest are
 * removed. Physical media files are never touched. Runs once per
 * subscription (dedup via metadata.content_purged_at).
 */
class PurgeExpiredTenantContentJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    /**
     * Children first so orphaned rows never outlive their parents.
     *
     * @var array<class-string, string>
     */
    private const CONTENT_MODELS = [
        ExamQuestion::class => 'أسئلة الامتحانات',
        ExamSection::class => 'أقسام الامتحانات',
        Exam::class => 'الامتحانات',
        LessonContent::class => 'محتويات الدروس',
        FileContent::class => 'الملفات',
        Lesson::class => 'الدروس',
        Section::class => 'الأقسام',
        Course::class => 'الدورات',
        Assignment::class => 'الواجبات',
        Announcement::class => 'الإعلانات',
        LiveSession::class => 'الجلسات المباشرة',
        Enrollment::class => 'تسجيلات الطلاب',
    ];

    public function handle(): void
    {
        $subs = TenantSubscription::whereIn('status', ['past_due', 'expired'])
            ->whereNotNull('ends_at')
            ->where('ends_at', '<', now()->subDays(ExpiryFollowUpJob::GRACE_DAYS))
            ->get();

        foreach ($subs as $sub) {
            $meta = $sub->metadata ?? [];

            if (! empty($meta['content_purged_at'])) {
                continue; // already purged.
            }

            $counts = $this->purgeTenantContent((string) $sub->tenant_id);

            $meta['content_purged_at'] = now()->toIso8601String();
            $meta['content_purged_counts'] = $counts;
            $sub->update(['metadata' => $meta]);

            $this->notifyOwners($sub, $counts);
        }
    }

    /**
     * @return array<string, int> label => deleted rows count
     */
    private function purgeTenantContent(string $tenantId): array
    {
        $counts = [];

        DB::transaction(function () use ($tenantId, &$counts) {
            foreach (self::CONTENT_MODELS as $model => $label) {
                $deleted = $model::withoutTenant()
                    ->where('tenant_id', $tenantId)
                    ->delete();

                if ($deleted > 0) {
                    $counts[$label] = $deleted;
                }
            }
        });

        // Bulk deletes bypass observers — refresh cached plan features.
        PlanEngine::bumpVersion();

        return $counts;
    }

    private function notifyOwners(TenantSubscription $sub, array $counts): void
    {
        try {
            $summary = collect($counts)
                ->map(fn ($count, $label) => "{$label}: {$count}")
                ->implode('، ');

            $userIds = TenantUser::where('tenant_id', $sub->tenant_id)
                ->whereIn('role', ['owner', 'admin'])
                ->where('status', 'active')
                ->pluck('user_id');

            app(NotificationService::class)->dispatch(
                User::whereIn('id', $userIds)->where('is_active', true)->get(),
                'subscription.content_deleted',
                'تم حذف المحتوى التعليمي',
                'انتهت مهلة التجديد بعد انتهاء الاشتراك، وتم حذف المحتوى التعليمي الخاص بك.'
                .($summary !== '' ? " ما تم حذفه: {$summary}." : '')
                .' يمكنك التواصل مع الدعم لاستعادة المحتوى عند إعادة الاشتراك.',
                null,
                $sub->tenant_id,
                ['subscription_id' => $sub->id, 'deleted_counts' => $counts],
                ['database', 'mail'],
            );
        } catch (\Throwable) {
            // Notification failures must never break the purge job.
        }
    }
}
