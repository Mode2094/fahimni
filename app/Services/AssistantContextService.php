<?php

namespace App\Services;

use App\Media\MediaQuotaService;
use App\Models\ActivationCard;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\LessonProgress;
use App\Models\LiveSession;
use App\Models\MediaAsset;
use App\Models\Payment;
use App\Models\ScheduledLesson;
use App\Plans\PlanEngine;
use App\Plans\QuotaService;
use App\Plans\UsageService;
use App\Support\AssistantKnowledge;
use App\Support\FeatureCatalog;
use App\Support\PlatformIndex;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * AssistantContextService — مُنشئ السياق الذكي.
 *
 * يبني شريحة بيانات مصغّرة ومحددة حسب موضوع السؤال فقط (لا يُرسل قاعدة
 * البيانات كاملة إلى الـ LLM). كل البيانات مقصورة على المستأجر الحالي
 * (current_tenant) — عزل كامل، ولا يُمرَّر أي سر/اعتماد.
 */
class AssistantContextService
{
    public function __construct(
        private readonly PlanEngine $planEngine,
        private readonly QuotaService $quota,
        private readonly UsageService $usage,
        private readonly MediaQuotaService $mediaQuota,
        private readonly SaasCostService $saasCost,
    ) {}

    /**
     * الأساس الثابت لكل طلب.
     */
    public function base(Request $request, string $page = ''): array
    {
        $tenant = $request->attributes->get('current_tenant');

        if (! $tenant) {
            return ['available' => false];
        }

        $tenantId = $tenant->getKey();
        $snapshot = $this->planEngine->snapshot($tenantId);

        $gates = [];
        foreach (FeatureCatalog::all() as $meta) {
            if (($meta['type'] ?? '') === 'boolean') {
                $gates[$meta['key']] = $this->planEngine->hasFeature($tenantId, $meta['key']);
            }
        }

        return [
            'available' => true,
            'tenant_id' => $tenantId,
            'user' => [
                'name' => $request->user()?->name,
            ],
            'page' => [
                'path' => $page,
                'label_ar' => AssistantKnowledge::pageLabel($page),
                'course_id' => $this->extractCourseId($page),
            ],
            'plan' => $snapshot['subscription'],
            'plan_active' => $snapshot['active'],
            'gates' => $gates,
            'expiring' => $this->expiring($snapshot['subscription']),
        ];
    }

    /**
     * بناء شريحة سياق حسب موضوع مطابق — يرسل فقط ما يلزم للإجابة.
     */
    public function sliceForTopic(array $base, ?array $topic, string $question): array
    {
        if (empty($base['available'])) {
            return $base;
        }

        // الاستخدام المنسّق (used/limit/remaining/pct/unlimited) — وليس العد الخام.
        $base['usage'] = $this->quota->status($base['tenant_id']);

        if (! $topic) {
            return $this->withCommon($base, $base['usage']);
        }

        $kind = $topic['kind'] ?? '';
        $key = $topic['key'] ?? '';

        switch ($kind) {
            case 'resource':
                $resource = $topic['data']['resource'] ?? null;
                $base['slice'] = $this->resourceSlice($base, $resource);

                break;

            case 'feature':
                $featureKey = $topic['data']['key'] ?? null;
                $base['slice'] = $this->featureSlice($base, $featureKey);

                break;

            case 'page':
                $base['slice'] = $this->pageSlice($base, $key);

                break;

            case 'workflow':
                $base['slice'] = $this->workflowSlice($base, $key);

                break;

            case 'diag':
                $base['slice'] = $this->diagSlice($base, $key);

                break;
        }

        return $this->withCommon($base, $base['usage']);
    }

    // ─── الشرائح ────────────────────────────────────

    private function resourceSlice(array $base, ?string $resource): array
    {
        if (! $resource || ! isset($base['usage'][$resource])) {
            return ['resource' => $resource, 'entry' => null];
        }

        return [
            'resource' => $resource,
            'label_ar' => AssistantKnowledge::resourceLabels()[$resource] ?? $resource,
            'entry' => $base['usage'][$resource],
        ];
    }

    private function featureSlice(array $base, ?string $featureKey): array
    {
        $tenantId = $base['tenant_id'];

        return [
            'feature' => $featureKey,
            'enabled' => $featureKey ? $this->planEngine->hasFeature($tenantId, $featureKey) : false,
            'limit' => $featureKey ? $this->planEngine->value($tenantId, $featureKey) : null,
        ];
    }

    private function pageSlice(array $base, string $pageKey): array
    {
        $tenantId = $base['tenant_id'];
        $slice = [];

        switch ($pageKey) {
            case 'page:courses':
                $slice['courses'] = $base['usage']['courses'] ?? null;
                $slice['published'] = Course::withoutTenant()->where('tenant_id', $tenantId)->where('is_published', true)->count();

                break;

            case 'page:students':
                $slice['students'] = $base['usage']['students'] ?? null;

                break;

            case 'page:cards':
                $slice['cards'] = $base['usage']['cards'] ?? null;
                $slice['gate'] = $base['gates']['cards_enabled'] ?? false;
                $slice['card_statuses'] = ActivationCard::withoutTenant()
                    ->where('tenant_id', $tenantId)
                    ->select('status', DB::raw('count(*) as total'))
                    ->groupBy('status')
                    ->pluck('total', 'status')
                    ->all();

                break;

            case 'page:live':
            case 'page:live_studio':
                $slice['live'] = $base['usage']['live_sessions'] ?? null;
                $slice['gate'] = $base['gates']['live_stream_enabled'] ?? false;
                $slice['statuses'] = LiveSession::withoutTenant()
                    ->where('tenant_id', $tenantId)
                    ->select('status', DB::raw('count(*) as total'))
                    ->groupBy('status')
                    ->pluck('total', 'status')
                    ->all();

                break;

            case 'page:analytics':
                $slice['analytics'] = $this->analyticsOverview($tenantId);

                break;

            case 'page:storage':
                $slice['storage'] = $base['usage']['storage'] ?? null;

                break;

            case 'page:subscription':
                $slice['plan'] = $base['plan'];
                $slice['usage'] = $base['usage'];

                break;

            case 'page:team':
                $slice['teachers'] = $base['usage']['teachers'] ?? null;
                $slice['gate'] = $base['gates']['multi_teacher_enabled'] ?? false;

                break;

            case 'page:exams':
                $slice['exams'] = $base['usage']['exams'] ?? null;
                $slice['needs_review'] = ExamAttempt::withoutTenant()
                    ->where('tenant_id', $tenantId)
                    ->where('needs_review', true)
                    ->count();

                break;

            case 'page:questions':
                $slice['questions'] = $base['usage']['questions'] ?? null;
                $slice['gate'] = $base['gates']['question_bank_enabled'] ?? false;

                break;

            case 'page:settings':
                $slice['tabs'] = ['تصميم الصفحة', 'منشئ الصفحة', 'الدومين المخصص', 'سياسة أجهزة الطلاب', 'سياسة ملفات الدروس', 'حماية الفيديو (علامة مائية)', 'مشاركة الصفحة'];
                $slice['video_watermark_gate'] = $base['gates']['video_watermark'] ?? false;
                $slice['custom_domain_gate'] = $base['gates']['custom_domain'] ?? false;

                break;

            case 'page:appointments':
                $slice['appointments'] = $base['usage']['appointments'] ?? null;
                $slice['gate'] = $base['gates']['appointments_enabled'] ?? false;

                break;

            case 'page:services':
                $slice['services'] = $base['usage']['services'] ?? null;
                $slice['gate'] = $base['gates']['paid_services_enabled'] ?? false;

                break;

            case 'page:schedule':
                $slice['gate'] = $base['gates']['scheduled_lessons_enabled'] ?? false;
                $slice['scheduled_lessons'] = ScheduledLesson::withoutTenant()->where('tenant_id', $tenantId)->count();

                break;

            case 'page:blog':
                $slice['blog_posts'] = $base['usage']['blog_posts'] ?? null;
                $slice['gate'] = $base['gates']['blog_enabled'] ?? false;

                break;

            case 'page:community':
                $slice['gate'] = $base['gates']['community_enabled'] ?? false;

                break;

            case 'page:assignments':
                $slice['assignments'] = $base['usage']['assignments'] ?? null;
                $slice['gate'] = $base['gates']['assignments_enabled'] ?? false;

                break;

            default:
                $slice['page'] = $pageKey;

                break;
        }

        return $slice;
    }

    private function workflowSlice(array $base, string $workflowKey): array
    {
        $id = \Illuminate\Support\Str::after($workflowKey, 'workflow:');
        $workflow = collect(AssistantKnowledge::workflows())->firstWhere('key', $id);

        return [
            'workflow' => $workflowKey,
            'title_ar' => $workflow['title_ar'] ?? $id,
            'steps_ar' => $workflow['steps_ar'] ?? [],
        ];
    }

    private function diagSlice(array $base, string $diagKey): array
    {
        $tenantId = $base['tenant_id'];
        $id = \Illuminate\Support\Str::after($diagKey, 'diag:');
        $diag = collect(AssistantKnowledge::troubleshooting())->firstWhere('key', $id);
        $data = [
            'title_ar' => $diag['title_ar'] ?? $id,
            'checks_ar' => $diag['checks_ar'] ?? [],
        ];

        switch ($id) {
            case 'video_not_visible':
            case 'upload_failed':
            case 'media_provider':
                $data['videos'] = $base['usage']['videos'] ?? null;
                $data['storage'] = $base['usage']['storage'] ?? null;
                $data['gate'] = $base['gates']['video_upload_enabled'] ?? false;
                $data['assets'] = $this->videoAssets($tenantId, $base['page']['course_id'] ?? null);

                break;

            case 'live_not_working':
                $data['gate'] = $base['gates']['live_stream_enabled'] ?? false;
                $data['live'] = $base['usage']['live_sessions'] ?? null;
                $data['statuses'] = LiveSession::withoutTenant()
                    ->where('tenant_id', $tenantId)
                    ->select('status', DB::raw('count(*) as total'))
                    ->groupBy('status')
                    ->pluck('total', 'status')
                    ->all();

                break;

            case 'student_cannot_see_lesson':
                $data['enrollments'] = Enrollment::withoutTenant()->where('tenant_id', $tenantId)->count();
                $data['courses'] = Course::withoutTenant()->where('tenant_id', $tenantId)->where('is_published', true)->count();

                break;

            case 'quota_reached':
            case 'plan_upgrade':
                $data['plan'] = $base['plan'];
                $data['usage'] = $base['usage'];

                break;

            case 'feature_hidden':
                $data['gates'] = $base['gates'];

                break;
        }

        return $data;
    }

    private function withCommon(array $base, array $usage): array
    {
        // ملخص صغير شائع مفيد لكل الأسئلة (دون إرسال قاعدة البيانات كاملة).
        $base['media_summary'] = $this->mediaQuota->usageStatus($base['tenant_id']);

        try {
            $cost = $this->saasCost->usageCost($base['tenant_id'], null, cache: true);
            $base['estimated_cost'] = $cost['total'] ?? 0;
        } catch (\Throwable) {
            $base['estimated_cost'] = 0;
        }

        return $base;
    }

    // ─── بيانات حقيقية ──────────────────────────────

    /**
     * ملخص تحليلات حقيقي (نفس مصادر صفحة التحليلات — بدون سلسلة يومية ثقيلة).
     */
    public function analyticsOverview(string $tenantId): array
    {
        $totalStudents = Enrollment::withoutTenant()->where('tenant_id', $tenantId)->distinct('user_id')->count('user_id');
        $totalCourses = Course::withoutTenant()->where('tenant_id', $tenantId)->count();
        $totalEnrollments = Enrollment::withoutTenant()->where('tenant_id', $tenantId)->count();
        $totalExams = Exam::withoutTenant()->where('tenant_id', $tenantId)->count();
        $totalAttempts = ExamAttempt::withoutTenant()->where('tenant_id', $tenantId)->count();

        $submitted = ExamAttempt::withoutTenant()->where('tenant_id', $tenantId)->where('status', 'submitted');
        $submittedCount = (clone $submitted)->count();
        $passed = (clone $submitted)->where('passed', true)->count();
        $avgScore = round((float) (clone $submitted)->avg('percentage'), 2);

        $completed = Enrollment::withoutTenant()->where('tenant_id', $tenantId)->where('status', 'completed')->count();

        $totalRevenue = round((float) Payment::withoutTenant()->where('tenant_id', $tenantId)->where('status', 'completed')->sum('amount'), 2);

        $periodRevenue = round((float) Payment::withoutTenant()
            ->where('tenant_id', $tenantId)
            ->where('status', 'completed')
            ->where('paid_at', '>=', now()->subDays(30)->startOfDay())
            ->sum('amount'), 2);

        $watchSeconds = (float) LessonProgress::withoutTenant()->where('tenant_id', $tenantId)->sum('watch_time_seconds');

        return [
            'students' => $totalStudents,
            'courses' => $totalCourses,
            'enrollments' => $totalEnrollments,
            'exams' => $totalExams,
            'attempts' => $totalAttempts,
            'passed' => $passed,
            'pass_rate' => $submittedCount > 0 ? round(($passed / $submittedCount) * 100, 2) : 0,
            'avg_score' => $avgScore,
            'completion_rate' => $totalEnrollments > 0 ? round(($completed / $totalEnrollments) * 100, 2) : 0,
            'revenue' => $totalRevenue,
            'period_revenue' => $periodRevenue,
            'watch_hours' => round($watchSeconds / 3600, 1),
        ];
    }

    /**
     * حالة أصول الفيديو الحقيقية (للتشخيص) — مؤخراً فقط، ومقيدة بالمستأجر.
     */
    public function videoAssets(string $tenantId, ?string $courseId = null): array
    {
        $query = MediaAsset::withoutTenant()
            ->where('tenant_id', $tenantId)
            ->where('kind', 'video')
            ->orderByDesc('created_at');

        if ($courseId) {
            $query->where('course_id', $courseId);
        }

        $recent = $query->limit(10)->get(['id', 'status', 'title', 'provider', 'created_at']);

        $byStatus = $query->get()->groupBy('status')->map->count()->all();

        return [
            'by_status' => $byStatus,
            'recent' => $recent->map(fn (MediaAsset $a) => [
                'title' => $a->title,
                'status' => $a->status,
                'provider' => $a->provider,
                'created' => $a->created_at?->toDateTimeString(),
            ])->all(),
        ];
    }

    private function extractCourseId(string $page): ?string
    {
        if (preg_match('#^/courses/([^/]+)#', $page, $m)) {
            return $m[1];
        }

        if (preg_match('#^/course/([^/]+)#', $page, $m)) {
            return $m[1];
        }

        return null;
    }

    private function expiring(?array $plan): ?string
    {
        if (! $plan) {
            return null;
        }

        $now = now();

        foreach (['trial_ends_at', 'ends_at'] as $field) {
            $value = $plan[$field] ?? null;

            if (! $value) {
                continue;
            }

            $date = \Illuminate\Support\Carbon::parse($value);

            if ($date->isPast()) {
                return 'منتهي في '.$value;
            }

            if ($date->diffInDays($now) <= 7) {
                return 'ينتهي خلال '.$date->diffInDays($now).' يوم ('.$value.')';
            }
        }

        return null;
    }
}
