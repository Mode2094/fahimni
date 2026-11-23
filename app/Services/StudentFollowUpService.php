<?php

namespace App\Services;

use App\Models\AssignmentSubmission;
use App\Models\CourseGroup;
use App\Models\Enrollment;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\LessonProgress;
use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * متابعة الطلاب — تحليل قواعدي (وليس ذكاءً اصطناعياً) لبيانات النشاط
 * والتقدم والاختبارات الموجودة أصلاً في المنصة.
 *
 * المصادر الموثوقة الوحيدة المستخدمة لكل مؤشر (لا استنتاج ولا اختراع):
 *   • آخر تسجيل دخول        → users.last_login_at
 *   • آخر نشاط تقدّم/فيديو  → lesson_progress.updated_at   (التقدّم + موضع الفيديو)
 *   • آخر نشاط اختبار       → exam_attempts.updated_at
 *   • آخر تسليم واجب        → assignment_submissions.updated_at
 *   • آخر ظهور لجهاز        → user_devices.last_seen_at
 *   • نسبة إكمال الدروس     → enrollments.progress_percent (محسوبة خادمياً)
 *   • التأخر عن المواعيد    → assignments.due_at / exams.ends_at مع غياب التسليم
 *
 * قاعدة أساسية (منعاً للفبركة): «غياب السجل» لا يعني «غياب الطالب». إذا لم
 * يتوفر أي إشارة نشاط يُصنّف الطالب «لا توجد بيانات نشاط» أو «جديد» — ولا
 * تُحسب له «أيام غياب» وهمية.
 */
class StudentFollowUpService
{
    public const DEFAULT_INACTIVE_DAYS = 7;

    public const DEFAULT_LOW_PROGRESS_PERCENT = 30;

    public const DEFAULT_MIN_ENROLLED_DAYS = 3;

    /** إشارات النشاط المتاحة في المنصة (تُعرض للمعلم شفافيةً). */
    public const SIGNALS = [
        'login' => 'تسجيل الدخول',
        'lesson_progress' => 'تقدّم الدروس والفيديو',
        'exam_attempt' => 'الاختبارات',
        'assignment_submission' => 'تسليم الواجبات',
        'device' => 'ظهور الجهاز',
    ];

    /** شدّة الحالة — الأعلى يعني أولوية متابعة أكبر. */
    private const SEVERITY = [
        'overdue' => 5,
        'inactive' => 4,
        'no_activity_data' => 3,
        'low_progress' => 2,
        'new' => 1,
        'active' => 0,
    ];

    /**
     * يحلّل طلاب المستأجر وفق المرشّحات ويعيد الصفوف كاملة (غير مُصفّحة).
     *
     * @param  array<string,mixed>  $filters
     * @return array{students: array<int,array<string,mixed>>, meta: array<string,mixed>}
     */
    public function build(string $tenantId, array $filters): array
    {
        $now = Carbon::now();
        $inactiveDays = $this->clampInt($filters['inactive_days'] ?? null, 1, 365, self::DEFAULT_INACTIVE_DAYS);
        $progressBelow = $this->clampInt($filters['progress_below'] ?? null, 1, 100, self::DEFAULT_LOW_PROGRESS_PERCENT);
        $minEnrolledDays = $this->clampInt($filters['min_enrolled_days'] ?? null, 0, 365, self::DEFAULT_MIN_ENROLLED_DAYS);

        $groupFilterId = $filters['group_id'] ?? null;
        $courseId = $filters['course_id'] ?? null;
        $search = trim((string) ($filters['q'] ?? ''));
        $statusFilter = (string) ($filters['status'] ?? 'all');
        $sort = (string) ($filters['sort'] ?? 'risk');

        // إذا حُدّدت مجموعة دون كورس، نحصر المقاييس في كورس المجموعة.
        $group = null;
        if ($groupFilterId) {
            $group = CourseGroup::withoutTenant()
                ->where('tenant_id', $tenantId)
                ->find($groupFilterId);
            if ($group && ! $courseId) {
                $courseId = $group->course_id;
            }
        }

        // ── 1. قاعدة الطلاب: التسجيلات السارية فقط ─────────────
        $enrollments = Enrollment::withoutTenant()
            ->where('tenant_id', $tenantId)
            ->effective()
            ->when($courseId, fn ($q, $c) => $q->where('course_id', $c))
            ->get(['user_id', 'course_id', 'status', 'progress_percent', 'enrolled_at']);

        /** @var Collection<string, Collection<int,Enrollment>> $byUser */
        $byUser = $enrollments->groupBy('user_id');

        if ($group) {
            $memberIds = DB::table('course_group_members')
                ->where('course_group_id', $group->id)
                ->pluck('user_id')
                ->all();
            $byUser = $byUser->filter(fn ($rows, $userId) => in_array($userId, $memberIds, true));
        }

        // ── 2. بحث الاسم/الإيميل يقصر المجموعة ─────────────────
        $population = $byUser->keys()->all();
        $like = $this->likeOperator();

        $users = User::whereIn('id', $population)
            ->when($search !== '', fn ($q) => $q->where(function ($w) use ($search, $like) {
                $w->where('name', $like, "%{$search}%")
                    ->orWhere('email', $like, "%{$search}%");
            }))
            ->get(['id', 'name', 'email', 'avatar_url', 'created_at', 'last_login_at'])
            ->keyBy('id');

        $population = $users->keys()->all();
        $byUser = $byUser->filter(fn ($rows, $userId) => in_array($userId, $population, true));

        $meta = $this->meta($tenantId, $now, $inactiveDays, $progressBelow, $minEnrolledDays);

        if (empty($population)) {
            return ['students' => [], 'meta' => $meta];
        }

        // ── 3. التجميعات (عدد استعلامات ثابت — بلا N+1) ─────────
        $lessonAgg = LessonProgress::withoutTenant()
            ->where('tenant_id', $tenantId)
            ->whereIn('user_id', $population)
            ->groupBy('user_id')
            ->selectRaw("user_id, max(updated_at) as last_at, sum(case when status = 'completed' then 1 else 0 end) as completed")
            ->get()
            ->keyBy('user_id');

        $attemptAgg = ExamAttempt::withoutTenant()
            ->where('tenant_id', $tenantId)
            ->whereIn('user_id', $population)
            ->groupBy('user_id')
            ->selectRaw('user_id, max(updated_at) as last_at, count(*) as attempts, sum(case when passed then 1 else 0 end) as passed')
            ->get()
            ->keyBy('user_id');

        $submissionAgg = AssignmentSubmission::withoutTenant()
            ->where('tenant_id', $tenantId)
            ->whereIn('user_id', $population)
            ->groupBy('user_id')
            ->selectRaw('user_id, max(updated_at) as last_at, count(*) as submissions')
            ->get()
            ->keyBy('user_id');

        $deviceAgg = UserDevice::withoutTenant()
            ->where('tenant_id', $tenantId)
            ->whereIn('user_id', $population)
            ->groupBy('user_id')
            ->selectRaw('user_id, max(last_seen_at) as last_at')
            ->get()
            ->keyBy('user_id');

        // ── 4. التأخر عن الاستحقاقات ────────────────────────────
        $overdueAssignments = $this->overdueAssignments($tenantId, $population, $now);
        $overdueExams = $this->overdueExams($tenantId, $population, $byUser, $now);

        // ── 5. بناء صفوف الطلاب ────────────────────────────────
        $rows = [];
        foreach ($population as $userId) {
            /** @var User $user */
            $user = $users[$userId];
            $userEnrollments = $byUser[$userId];
            $lp = $lessonAgg[$userId] ?? null;
            $ea = $attemptAgg[$userId] ?? null;
            $as = $submissionAgg[$userId] ?? null;
            $dv = $deviceAgg[$userId] ?? null;

            $signals = array_filter([
                'login' => $user->last_login_at,
                'lesson_progress' => $lp?->last_at,
                'exam_attempt' => $ea?->last_at,
                'assignment_submission' => $as?->last_at,
                'device' => $dv?->last_at,
            ], fn ($v) => $v !== null);

            $lastActivityAt = null;
            $lastActivitySource = null;
            foreach ($signals as $key => $value) {
                $moment = Carbon::parse($value);
                if ($lastActivityAt === null || $moment->greaterThan($lastActivityAt)) {
                    $lastActivityAt = $moment;
                    $lastActivitySource = $key;
                }
            }

            $daysInactive = $lastActivityAt
                ? max(0, (int) floor(($now->getTimestamp() - $lastActivityAt->getTimestamp()) / 86400))
                : null;

            $earliestEnrolledAt = $userEnrollments->pluck('enrolled_at')->filter()->min();
            $enrolledDays = $earliestEnrolledAt
                ? max(0, (int) floor(($now->getTimestamp() - Carbon::parse($earliestEnrolledAt)->getTimestamp()) / 86400))
                : 0;

            $progress = round((float) $userEnrollments->avg(fn ($e) => (float) $e->progress_percent), 2);
            $overdueA = (int) ($overdueAssignments[$userId] ?? 0);
            $overdueE = (int) ($overdueExams[$userId] ?? 0);

            $reasons = $this->reasons(
                $user,
                $lastActivityAt,
                $daysInactive,
                $inactiveDays,
                $progress,
                $progressBelow,
                $enrolledDays,
                $minEnrolledDays,
                $overdueA,
                $overdueE,
            );

            $status = $this->status($reasons, $lastActivityAt, $enrolledDays, $minEnrolledDays);

            $rows[] = [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'avatar_url' => $user->avatar_url,
                'joined_at' => $user->created_at?->toIso8601String(),
                'last_login_at' => $user->last_login_at?->toIso8601String(),
                'last_activity_at' => $lastActivityAt?->toIso8601String(),
                'last_activity_source' => $lastActivitySource,
                'last_activity_sources' => array_keys($signals),
                'days_inactive' => $daysInactive,
                'data_available' => $lastActivityAt !== null,
                'status' => $status,
                'at_risk' => in_array($status, ['overdue', 'inactive', 'no_activity_data', 'low_progress'], true),
                'reasons' => $reasons,
                'progress_percent' => $progress,
                'courses_count' => $userEnrollments->count(),
                'courses_completed' => $userEnrollments->where('status', 'completed')->count(),
                'lessons_completed' => (int) ($lp->completed ?? 0),
                'exam_attempts' => (int) ($ea->attempts ?? 0),
                'exams_passed' => (int) ($ea->passed ?? 0),
                'assignment_submissions' => (int) ($as->submissions ?? 0),
                'overdue_assignments' => $overdueA,
                'overdue_exams' => $overdueE,
            ];
        }

        $meta['counts'] = $this->counts($rows);

        $rows = $this->applyStatusFilter($rows, $statusFilter);
        $rows = $this->sort($rows, $sort);

        return ['students' => $rows, 'meta' => $meta];
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function counts(array $rows): array
    {
        $counts = [
            'total' => count($rows),
            'at_risk' => 0,
            'active' => 0,
            'new' => 0,
            'inactive' => 0,
            'inactive_7' => 0,
            'inactive_14' => 0,
            'low_progress' => 0,
            'overdue' => 0,
            'no_activity_data' => 0,
            'never_signed_in' => 0,
        ];

        foreach ($rows as $row) {
            if ($row['at_risk']) {
                $counts['at_risk']++;
            }
            if (isset($counts[$row['status']])) {
                $counts[$row['status']]++;
            }

            $codes = array_column($row['reasons'], 'code');

            if (in_array('inactive', $codes, true)) {
                $counts['inactive']++;
            }
            if (in_array('low_progress', $codes, true)) {
                $counts['low_progress']++;
            }
            if (in_array('overdue_assignments', $codes, true) || in_array('overdue_exams', $codes, true)) {
                $counts['overdue']++;
            }
            if (in_array('no_activity_record', $codes, true)) {
                $counts['no_activity_data']++;
            }
            if ($row['last_login_at'] === null) {
                $counts['never_signed_in']++;
            }
            if ($row['days_inactive'] !== null) {
                if ($row['days_inactive'] >= 7) {
                    $counts['inactive_7']++;
                }
                if ($row['days_inactive'] >= 14) {
                    $counts['inactive_14']++;
                }
            }
        }

        return $counts;
    }

    /**
     * أسباب استحقاق المتابعة — كل سبب مصحوب بدليله الرقمي.
     *
     * @return array<int,array<string,mixed>>
     */
    private function reasons(
        User $user,
        ?Carbon $lastActivityAt,
        ?int $daysInactive,
        int $inactiveDays,
        float $progress,
        int $progressBelow,
        int $enrolledDays,
        int $minEnrolledDays,
        int $overdueAssignments,
        int $overdueExams,
    ): array {
        $reasons = [];

        if ($lastActivityAt === null) {
            $reasons[] = ['code' => 'no_activity_record'];
        } elseif ($daysInactive !== null && $daysInactive >= $inactiveDays) {
            $reasons[] = ['code' => 'inactive', 'days' => $daysInactive];
        }

        if ($progress < $progressBelow && $enrolledDays >= $minEnrolledDays) {
            $reasons[] = ['code' => 'low_progress', 'percent' => $progress];
        }

        if ($overdueAssignments > 0) {
            $reasons[] = ['code' => 'overdue_assignments', 'count' => $overdueAssignments];
        }

        if ($overdueExams > 0) {
            $reasons[] = ['code' => 'overdue_exams', 'count' => $overdueExams];
        }

        if ($user->last_login_at === null) {
            $reasons[] = ['code' => 'never_signed_in'];
        }

        return $reasons;
    }

    private function status(array $reasons, ?Carbon $lastActivityAt, int $enrolledDays, int $minEnrolledDays): string
    {
        $codes = array_column($reasons, 'code');

        if (in_array('overdue_assignments', $codes, true) || in_array('overdue_exams', $codes, true)) {
            return 'overdue';
        }
        if (in_array('inactive', $codes, true)) {
            return 'inactive';
        }
        if (in_array('no_activity_record', $codes, true)) {
            // طالب التحق للتو ولم تظهر منه أي إشارة: «جديد» لا «متغيّب».
            return $enrolledDays < $minEnrolledDays ? 'new' : 'no_activity_data';
        }
        if (in_array('low_progress', $codes, true)) {
            return 'low_progress';
        }

        return 'active';
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function applyStatusFilter(array $rows, string $status): array
    {
        if ($status === 'all' || $status === '') {
            return $rows;
        }

        return array_values(array_filter($rows, function (array $row) use ($status) {
            $codes = array_column($row['reasons'], 'code');

            return match ($status) {
                'at_risk' => $row['at_risk'],
                'inactive' => in_array('inactive', $codes, true),
                'low_progress' => in_array('low_progress', $codes, true),
                'overdue' => in_array('overdue_assignments', $codes, true) || in_array('overdue_exams', $codes, true),
                'no_activity_data' => in_array('no_activity_record', $codes, true),
                'never_signed_in' => $row['last_login_at'] === null,
                'active', 'new' => $row['status'] === $status,
                default => true,
            };
        }));
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function sort(array $rows, string $sort): array
    {
        usort($rows, function (array $a, array $b) use ($sort) {
            if ($sort === 'name') {
                return strcmp((string) $a['name'], (string) $b['name']);
            }
            if ($sort === 'progress') {
                return $a['progress_percent'] <=> $b['progress_percent'];
            }
            if ($sort === 'inactive') {
                return $this->compareActivity($a, $b);
            }

            // افتراضي: الأخطر أولاً ثم الأقدم نشاطاً ثم الاسم.
            $severity = (self::SEVERITY[$b['status']] ?? 0) <=> (self::SEVERITY[$a['status']] ?? 0);
            if ($severity !== 0) {
                return $severity;
            }

            return $this->compareActivity($a, $b) ?: strcmp((string) $a['name'], (string) $b['name']);
        });

        return $rows;
    }

    /** الأقدم نشاطاً أولاً، ومن لا سجل له في النهاية. */
    private function compareActivity(array $a, array $b): int
    {
        $aHas = $a['last_activity_at'] !== null;
        $bHas = $b['last_activity_at'] !== null;

        if ($aHas !== $bHas) {
            return $aHas ? 1 : -1;
        }
        if (! $aHas) {
            return 0;
        }

        return strcmp((string) $a['last_activity_at'], (string) $b['last_activity_at']);
    }

    /**
     * واجبات مستحقة انتهى موعدها ولا يوجد تسليم للطالب.
     *
     * @param  array<int,string>  $population
     * @return array<string,int> user_id => count
     */
    private function overdueAssignments(string $tenantId, array $population, Carbon $now): array
    {
        return DB::table('assignments')
            ->join('enrollments', function ($j) use ($tenantId) {
                $j->on('enrollments.course_id', '=', 'assignments.course_id')
                    ->where('enrollments.tenant_id', '=', $tenantId)
                    ->whereIn('enrollments.status', Enrollment::EFFECTIVE_STATUSES);
            })
            ->leftJoin('assignment_submissions', function ($j) {
                $j->on('assignment_submissions.assignment_id', '=', 'assignments.id')
                    ->on('assignment_submissions.user_id', '=', 'enrollments.user_id')
                    ->whereNull('assignment_submissions.deleted_at');
            })
            ->where('assignments.tenant_id', $tenantId)
            ->whereNull('assignments.deleted_at')
            ->where('assignments.is_published', true)
            ->whereNotNull('assignments.due_at')
            ->where('assignments.due_at', '<', $now)
            ->whereNull('assignment_submissions.id')
            ->whereIn('enrollments.user_id', $population)
            ->groupBy('enrollments.user_id')
            ->selectRaw('enrollments.user_id, count(distinct assignments.id) as overdue')
            ->pluck('overdue', 'enrollments.user_id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /**
     * اختبارات انتهت نافذتها ولم يسلّمها الطالب — مع احترام تقييد المجموعات
     * (اختبار مقصور على مجموعة لا يُحتسب على غير أعضائها).
     *
     * @param  array<int,string>  $population
     * @param  Collection<string, Collection<int,Enrollment>>  $byUser
     * @return array<string,int>
     */
    private function overdueExams(string $tenantId, array $population, Collection $byUser, Carbon $now): array
    {
        $exams = Exam::withoutTenant()
            ->where('tenant_id', $tenantId)
            ->where('is_published', true)
            ->whereNotNull('ends_at')
            ->where('ends_at', '<', $now)
            ->get(['id', 'course_id']);

        if ($exams->isEmpty()) {
            return [];
        }

        $examGroupMap = DB::table('course_group_exam')
            ->whereIn('exam_id', $exams->pluck('id'))
            ->get()
            ->groupBy('exam_id')
            ->map(fn ($rows) => $rows->pluck('course_group_id')->all());

        $groupIds = collect($examGroupMap)->flatten()->unique()->values()->all();
        $memberMap = empty($groupIds)
            ? collect()
            : DB::table('course_group_members')
                ->whereIn('course_group_id', $groupIds)
                ->whereIn('user_id', $population)
                ->get()
                ->groupBy('user_id')
                ->map(fn ($rows) => $rows->pluck('course_group_id')->all());

        $submitted = DB::table('exam_attempts')
            ->where('tenant_id', $tenantId)
            ->where('status', 'submitted')
            ->whereIn('user_id', $population)
            ->get(['user_id', 'exam_id'])
            ->mapWithKeys(fn ($r) => [$r->user_id.'|'.$r->exam_id => true])
            ->all();

        $examsByCourse = $exams->groupBy('course_id');

        $result = [];
        foreach ($population as $userId) {
            $courseIds = $byUser[$userId]?->pluck('course_id')->unique()->all() ?? [];
            $memberships = $memberMap[$userId] ?? [];
            $count = 0;

            foreach ($courseIds as $courseKey) {
                foreach ($examsByCourse[$courseKey] ?? [] as $exam) {
                    if (isset($submitted[$userId.'|'.$exam->id])) {
                        continue;
                    }
                    $restricted = $examGroupMap[$exam->id] ?? [];
                    if ($restricted && ! array_intersect($restricted, $memberships)) {
                        continue;
                    }
                    $count++;
                }
            }

            if ($count > 0) {
                $result[$userId] = $count;
            }
        }

        return $result;
    }

    private function meta(
        string $tenantId,
        Carbon $now,
        int $inactiveDays,
        int $progressBelow,
        int $minEnrolledDays,
    ): array {
        $courses = DB::table('courses')
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->orderBy('title')
            ->get(['id', 'title'])
            ->map(fn ($c) => ['id' => $c->id, 'title' => $c->title])
            ->all();

        $groups = DB::table('course_groups')
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get(['id', 'name', 'course_id'])
            ->map(fn ($g) => ['id' => $g->id, 'name' => $g->name, 'course_id' => $g->course_id])
            ->all();

        return [
            'generated_at' => $now->toIso8601String(),
            'thresholds' => [
                'inactive_days' => $inactiveDays,
                'low_progress_percent' => $progressBelow,
                'min_enrolled_days' => $minEnrolledDays,
            ],
            'signals' => self::SIGNALS,
            'counts' => [],
            'courses' => $courses,
            'groups' => $groups,
            'data_quality' => [
                // شفافية: ما لا نملكه لا نخترعه.
                'note' => 'المؤشرات مبنية على سجلات حقيقية فقط. غياب أي سجل نشاط لا يعني أن الطالب متغيّب.',
                'missing_activity_is_not_inactivity' => true,
                'sources' => [
                    'last_login_at' => 'users.last_login_at',
                    'lesson_progress' => 'lesson_progress.updated_at',
                    'exam_attempt' => 'exam_attempts.updated_at',
                    'assignment_submission' => 'assignment_submissions.updated_at',
                    'device' => 'user_devices.last_seen_at',
                    'progress_percent' => 'enrollments.progress_percent',
                    'overdue' => 'assignments.due_at / exams.ends_at بلا تسليم',
                ],
            ],
        ];
    }

    private function likeOperator(): string
    {
        return DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
    }

    private function clampInt(mixed $value, int $min, int $max, int $default): int
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return $default;
        }

        return max($min, min($max, (int) $value));
    }
}
