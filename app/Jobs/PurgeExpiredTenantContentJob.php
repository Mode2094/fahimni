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
use Illuminate\Support\Facades\Log;

/**
 * SAFETY-DISABLED: never delete teacher/student content on subscription
 * expiry.
 *
 * Educational content (courses, lessons, exams, assignments, live sessions,
 * enrollments, …) is the teacher's and their students' data. It must NEVER be
 * destroyed because a payment lapsed. This job therefore performs NO deletion
 * at all — it only records that a tenant passed the grace window so the UI can
 * prompt a renewal.
 *
 * The class is retained (and its schedule entry removed in routes/console.php)
 * so no other code path resurrects destructive purging. Purging of content is
 * a deliberate, manual, operator-initiated operation, not an automated one.
 */
class PurgeExpiredTenantContentJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    /**
     * Educational content that MUST never be auto-deleted on expiry.
     * Kept for documentation and for any future, explicitly-approved manual
     * retention tooling.
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
        // Intentionally a no-op. We never delete tenant content on expiry.
        // (Retained only to mark the grace window; no rows are touched.)
        Log::info('subscription.purge_disabled', [
            'detail' => 'PurgeExpiredTenantContentJob is disabled by policy — content is never deleted on subscription expiry.',
        ]);
    }
}
