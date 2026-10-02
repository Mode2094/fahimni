<?php

namespace App\Plans;

use App\Models\ActivationCard;
use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\Booking;
use App\Models\CommunityGroup;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Exam;
use App\Models\FileContent;
use App\Models\LessonProgress;
use App\Models\LiveSession;
use App\Models\PaidService;
use App\Models\Post;
use App\Models\Question;
use App\Models\TenantUser;
use App\Models\VideoContent;
use Illuminate\Support\Facades\Cache;

/**
 * Usage Engine — computes real consumption for every countable resource.
 * New resources register here once and every consumer (dashboard, quota
 * checks, admin views) gets them automatically.
 */
class UsageService
{
    private const CACHE_TTL = 30;

    /**
     * Registry of countable resources.
     * gate = feature key that must be enabled, limit = limit feature key.
     *
     * @return array<string, array{label_ar: string, label_en: string, gate: ?string, limit: ?string}>
     */
    public function registry(): array
    {
        return [
            'students' => ['label_ar' => 'الطلاب', 'label_en' => 'Students', 'gate' => null, 'limit' => 'students_limit'],
            'courses' => ['label_ar' => 'الكورسات', 'label_en' => 'Courses', 'gate' => null, 'limit' => 'courses_limit'],
            'videos' => ['label_ar' => 'الفيديوهات', 'label_en' => 'Videos', 'gate' => 'video_upload_enabled', 'limit' => 'videos_limit'],
            'files' => ['label_ar' => 'الملفات', 'label_en' => 'Files', 'gate' => 'file_upload_enabled', 'limit' => 'files_limit'],
            'pdfs' => ['label_ar' => 'ملفات PDF', 'label_en' => 'PDFs', 'gate' => 'file_upload_enabled', 'limit' => 'pdfs_limit'],
            'questions' => ['label_ar' => 'الأسئلة', 'label_en' => 'Questions', 'gate' => 'question_bank_enabled', 'limit' => 'questions_limit'],
            'exams' => ['label_ar' => 'الاختبارات', 'label_en' => 'Exams', 'gate' => null, 'limit' => 'exams_limit'],
            'assignments' => ['label_ar' => 'الواجبات', 'label_en' => 'Assignments', 'gate' => 'assignments_enabled', 'limit' => 'assignments_limit'],
            'teachers' => ['label_ar' => 'المعلمون', 'label_en' => 'Teachers', 'gate' => 'multi_teacher_enabled', 'limit' => 'teachers_limit'],
            'community_groups' => ['label_ar' => 'مجموعات المجتمع', 'label_en' => 'Community groups', 'gate' => 'community_enabled', 'limit' => 'community_groups_limit'],
            'live_sessions' => ['label_ar' => 'جلسات البث', 'label_en' => 'Live sessions', 'gate' => 'live_stream_enabled', 'limit' => 'live_sessions_limit'],
            'appointments' => ['label_ar' => 'المواعيد', 'label_en' => 'Appointments', 'gate' => 'appointments_enabled', 'limit' => 'appointments_limit'],
            'services' => ['label_ar' => 'الخدمات المدفوعة', 'label_en' => 'Paid services', 'gate' => 'paid_services_enabled', 'limit' => 'services_limit'],
            'blog_posts' => ['label_ar' => 'مقالات المدونة', 'label_en' => 'Blog posts', 'gate' => 'blog_enabled', 'limit' => 'blog_posts_limit'],
            'cards' => ['label_ar' => 'بطاقات التسجيل', 'label_en' => 'Registration cards', 'gate' => 'cards_enabled', 'limit' => 'cards_limit'],
            'storage' => ['label_ar' => 'مساحة التخزين', 'label_en' => 'Storage', 'gate' => null, 'limit' => 'storage_limit_mb'],
            'reports' => ['label_ar' => 'تصدير التقارير', 'label_en' => 'Reports export', 'gate' => 'export_reports', 'limit' => null],
            'branding' => ['label_ar' => 'العلامة التجارية المخصصة', 'label_en' => 'Custom branding', 'gate' => 'custom_branding_enabled', 'limit' => null],
        ];
    }

    public function usageOf(string $tenantId, string $resource): int|float
    {
        return match ($resource) {
            'students' => Enrollment::withoutTenant()->where('tenant_id', $tenantId)->distinct('user_id')->count('user_id'),
            'courses' => Course::withoutTenant()->where('tenant_id', $tenantId)->count(),
            'videos' => VideoContent::withoutTenant()->where('tenant_id', $tenantId)->count(),
            'files' => FileContent::withoutTenant()->where('tenant_id', $tenantId)->count(),
            'pdfs' => FileContent::withoutTenant()->where('tenant_id', $tenantId)->where('extension', 'pdf')->count(),
            'questions' => Question::withoutTenant()->where('tenant_id', $tenantId)->count(),
            'exams' => Exam::withoutTenant()->where('tenant_id', $tenantId)->count(),
            'assignments' => Assignment::withoutTenant()->where('tenant_id', $tenantId)->count(),
            'teachers' => TenantUser::where('tenant_id', $tenantId)
                ->whereIn('role', ['teacher', 'admin'])
                ->where('status', 'active')
                ->count(),
            'community_groups' => CommunityGroup::withoutTenant()->where('tenant_id', $tenantId)->count(),
            'live_sessions' => LiveSession::withoutTenant()->where('tenant_id', $tenantId)->count(),
            'appointments' => Booking::where('tenant_id', $tenantId)
                ->where('kind', Booking::KIND_APPOINTMENT)
                ->where('status', '!=', Booking::STATUS_CANCELLED)
                ->count(),
            'services' => PaidService::withoutTenant()->where('tenant_id', $tenantId)->count(),
            'blog_posts' => Post::withoutTenant()->where('tenant_id', $tenantId)->count(),
            'cards' => ActivationCard::withoutTenant()
                ->where('tenant_id', $tenantId)
                // Only usable (generated) cards consume quota — cancelled
                // and expired batches would otherwise burn capacity forever.
                ->where('status', 'generated')
                ->count(),
            'storage' => $this->storageBytes($tenantId),
            'reports' => 0,
            'branding' => 0,
            default => 0,
        };
    }

    public function storageBytes(string $tenantId): int
    {
        return (int) FileContent::withoutTenant()->where('tenant_id', $tenantId)->sum('size_bytes')
            + (int) VideoContent::withoutTenant()->where('tenant_id', $tenantId)->sum('file_size_bytes')
            + (int) Assignment::withoutTenant()->where('tenant_id', $tenantId)->sum('file_size_bytes')
            + (int) AssignmentSubmission::withoutTenant()->where('tenant_id', $tenantId)->sum('file_size_bytes');
    }

    /**
     * All counts for a tenant (short Redis cache — used by dashboards).
     */
    public function all(string $tenantId): array
    {
        return Cache::remember('plan:usage:'.$tenantId, self::CACHE_TTL, function () use ($tenantId) {
            $usage = [];

            foreach (array_keys($this->registry()) as $resource) {
                $usage[$resource] = $this->usageOf($tenantId, $resource);
            }

            return $usage;
        });
    }

    /**
     * Lessons completed (progress) — used for per-course progress stats.
     */
    public function completedLessons(string $tenantId): int
    {
        return LessonProgress::withoutTenant()->where('tenant_id', $tenantId)->where('status', 'completed')->count();
    }
}
