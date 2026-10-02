<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivationCard;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\LessonProgress;
use App\Models\User;
use App\Plans\QuotaService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class TeacherAnalyticsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeView($request);

        return response()->json($this->gatherData($request));
    }

    public function reportPdf(Request $request): Response
    {
        app(QuotaService::class)->assertCan($request->current_tenant->id, 'reports');
        $this->authorizeView($request);

        $data = $this->gatherData($request, false);

        $pdf = Pdf::loadView('reports.analytics', [
            'data' => $data,
            'generated_at' => now(),
        ])->setPaper('a4', 'portrait');

        return $pdf->download('analytics-report-'.now()->format('Y-m-d').'.pdf');
    }

    public function reportExcel(Request $request): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        app(QuotaService::class)->assertCan($request->current_tenant->id, 'reports');
        $this->authorizeView($request);

        $data = $this->gatherData($request, false);

        $spreadsheet = new Spreadsheet;
        $this->writeOverviewSheet($spreadsheet, $data);
        $this->writeRevenueSheet($spreadsheet, $data);
        $this->writeCoursesSheet($spreadsheet, $data);
        $this->writeExamsSheet($spreadsheet, $data);
        $this->writeTrafficSheet($spreadsheet, $data);

        $temp = tempnam(sys_get_temp_dir(), 'analytics').'.xlsx';
        (new Xlsx($spreadsheet))->save($temp);

        return response()->download($temp, 'analytics-report-'.now()->format('Y-m-d').'.xlsx')
            ->deleteFileAfterSend(true);
    }

    private function authorizeView(Request $request): void
    {
        $tenantId = $request->current_tenant->id;

        if ($request->user()->is_super_admin) {
            return;
        }

        $pivot = $request->user()->tenantPivots()
            ->where('tenant_id', $tenantId)
            ->first();

        abort_if(! $pivot || ! in_array($pivot->role, ['teacher', 'owner', 'admin'], true), 403, 'Teacher access required.');
    }

    private function gatherData(Request $request, bool $includeActivity = true): array
    {
        $tenantId = $request->current_tenant->id;

        $courseIds = Course::where('tenant_id', $tenantId)->pluck('id');

        // ── Overview ────────────────────────────────
        $totalStudents = Enrollment::where('tenant_id', $tenantId)->distinct('user_id')->count('user_id');
        $totalCourses = count($courseIds);
        $totalEnrollments = Enrollment::where('tenant_id', $tenantId)->count();
        $totalExams = Exam::where('tenant_id', $tenantId)->count();
        $totalAttempts = ExamAttempt::where('tenant_id', $tenantId)->count();

        $submitted = ExamAttempt::where('tenant_id', $tenantId)->where('status', 'submitted');
        $avgScore = round((float) $submitted->avg('percentage'), 2);
        $passed = (clone $submitted)->where('passed', true)->count();
        $submittedCount = (clone $submitted)->count();
        $passRate = $submittedCount > 0 ? round(($passed / $submittedCount) * 100, 2) : 0;

        $completed = Enrollment::where('tenant_id', $tenantId)->where('status', 'completed')->count();
        $completionRate = $totalEnrollments > 0 ? round(($completed / $totalEnrollments) * 100, 2) : 0;

        $cardsRedeemed = ActivationCard::where('tenant_id', $tenantId)->where('status', 'redeemed')->count();
        $cardsTotal = ActivationCard::where('tenant_id', $tenantId)->count();

        // ── Revenue (المدفوعات الناجحة فقط — مصدر الحقيقة) ──
        $paidBase = fn ($query) => $query->where('status', 'completed');

        $totalRevenue = round((float) $paidBase(
            \App\Models\Payment::withoutTenant()->where('tenant_id', $tenantId)
        )->sum('amount'), 2);

        $days = min(max($request->integer('days', 30), 1), 90);
        $since = now()->subDays($days - 1)->startOfDay();
        $periodRevenue = round((float) $paidBase(
            \App\Models\Payment::withoutTenant()->where('tenant_id', $tenantId)->where('paid_at', '>=', $since)
        )->sum('amount'), 2);

        // الربح الحقيقي = الإيراد − تكلفة البنية الفعلية (من Ledger) إن كانت متوفرة.
        $profit = 0.0;
        $periodProfit = 0.0;
        try {
            // تكلفة البنية الفعلية للشهر من محرك الوسائط (بني/كلاودفلير).
            $costService = app(\App\Media\MediaCostService::class);
            $monthCost = (float) ($costService->tenantCost($tenantId)['total'] ?? 0);
            $profit = round($totalRevenue - $monthCost, 2);
            $periodProfit = $profit;
        } catch (\Throwable) {
            $profit = 0.0;
            $periodProfit = 0.0;
        }

        // الإيراد حسب الكورس — من مدفوعات الاشتراكات المرتبطة بالكورسات (محاولة)
        $revenueByCourse = collect([]);

        // ── Watch time ──────────────────────────────
        $totalWatchSeconds = (float) LessonProgress::where('tenant_id', $tenantId)->sum('watch_time_seconds');
        $activeStudents = LessonProgress::where('tenant_id', $tenantId)->distinct('user_id')->count('user_id');

        $watchByCourse = LessonProgress::where('lesson_progress.tenant_id', $tenantId)
            ->join('lessons', 'lessons.id', '=', 'lesson_progress.lesson_id')
            ->join('sections', 'sections.id', '=', 'lessons.section_id')
            ->selectRaw('sections.course_id, sum(lesson_progress.watch_time_seconds) as seconds')
            ->groupBy('sections.course_id')
            ->get()
            ->keyBy('course_id');

        // ── Traffic sources (التسجيلات فقط — الإيراد غير مرتبط بالمصدر) ──
        $trafficSources = Enrollment::where('enrollments.tenant_id', $tenantId)
            ->selectRaw("coalesce(nullif(enrollments.source, ''), 'direct') as source, count(*) as total")
            ->groupBy('source')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($r) => [
                'source' => $r->source,
                'total' => (int) $r->total,
                'percentage' => $totalEnrollments > 0 ? round(((int) $r->total / $totalEnrollments) * 100, 1) : 0,
                'revenue' => 0.0,
            ])
            ->values();

        $result = [
            'overview' => [
                'total_students' => $totalStudents,
                'total_courses' => $totalCourses,
                'total_enrollments' => $totalEnrollments,
                'total_exams' => $totalExams,
                'total_attempts' => $totalAttempts,
                'avg_score' => $avgScore,
                'pass_rate' => $passRate,
                'passed' => $passed,
                'completion_rate' => $completionRate,
                'completed' => $completed,
                'cards_redeemed' => $cardsRedeemed,
                'cards_total' => $cardsTotal,
                'revenue' => $totalRevenue,
                'period_revenue' => $periodRevenue,
                'profit' => $profit,
                'period_profit' => $periodProfit,
                'watch_seconds' => (int) $totalWatchSeconds,
                'watch_hours' => round($totalWatchSeconds / 3600, 1),
                'active_students' => $activeStudents,
            ],
            'revenue' => [
                'total' => $totalRevenue,
                'period' => $periodRevenue,
                'profit' => $profit,
                'by_course' => $revenueByCourse,
            ],
            'best_selling' => $revenueByCourse->take(10)->values()->all(),
            'traffic_sources' => $trafficSources->all(),
        ];

        // ── Daily series (last N days) ─────────────
        $dates = [];
        for ($i = 0; $i < $days; $i++) {
            $dates[now()->subDays($days - 1 - $i)->format('Y-m-d')] = [
                'enrollments' => 0,
                'attempts' => 0,
                'revenue' => 0,
                'watch_seconds' => 0,
            ];
        }

        Enrollment::where('tenant_id', $tenantId)
            ->where('enrolled_at', '>=', $since)
            ->selectRaw("to_char(enrolled_at, 'YYYY-MM-DD') as d, count(*) as c")
            ->groupBy('d')
            ->get()
            ->each(fn ($r) => $dates[$r->d]['enrollments'] = (int) $r->c);

        \App\Models\Payment::withoutTenant()
            ->where('tenant_id', $tenantId)
            ->where('status', 'completed')
            ->where('paid_at', '>=', $since)
            ->selectRaw("to_char(paid_at, 'YYYY-MM-DD') as d, sum(amount) as revenue")
            ->groupBy('d')
            ->get()
            ->each(fn ($r) => $dates[$r->d]['revenue'] = round((float) $r->revenue, 2));

        ExamAttempt::where('tenant_id', $tenantId)
            ->where('submitted_at', '>=', $since)
            ->selectRaw("to_char(submitted_at, 'YYYY-MM-DD') as d, count(*) as c")
            ->groupBy('d')
            ->get()
            ->each(fn ($r) => $dates[$r->d]['attempts'] = (int) $r->c);

        LessonProgress::where('tenant_id', $tenantId)
            ->where('updated_at', '>=', $since)
            ->selectRaw("to_char(updated_at, 'YYYY-MM-DD') as d, sum(watch_time_seconds) as s")
            ->groupBy('d')
            ->get()
            ->each(fn ($r) => $dates[$r->d]['watch_seconds'] = (int) $r->s);

        $result['daily'] = collect($dates)->map(fn ($v, $k) => ['date' => $k, ...$v])->values();

        // ── Course performance ──────────────────────
        $enrollmentsByCourse = Enrollment::where('tenant_id', $tenantId)
            ->selectRaw("course_id, count(*) as total, sum(case when status = 'completed' then 1 else 0 end) as completed, avg(progress_percent) as avg_progress")
            ->groupBy('course_id')
            ->get()
            ->keyBy('course_id');

        $attemptsByCourse = ExamAttempt::where('exam_attempts.tenant_id', $tenantId)
            ->join('exams', 'exams.id', '=', 'exam_attempts.exam_id')
            ->selectRaw('exams.course_id, count(*) as total')
            ->groupBy('exams.course_id')
            ->get()
            ->keyBy('course_id');

        $result['courses'] = Course::where('tenant_id', $tenantId)
            ->get()
            ->map(function (Course $course) use ($watchByCourse, $enrollmentsByCourse, $attemptsByCourse) {
                $stats = $enrollmentsByCourse[$course->id] ?? null;
                $total = (int) ($stats->total ?? 0);
                $completed = (int) ($stats->completed ?? 0);
                $avgProgress = round((float) ($stats->avg_progress ?? 0), 1);
                $attempts = (int) ($attemptsByCourse[$course->id]->total ?? 0);
                $price = (float) $course->price;

                return [
                    'id' => $course->id,
                    'title' => $course->title,
                    'enrollments' => $total,
                    'completed' => $completed,
                    'completion_rate' => $total > 0 ? round(($completed / $total) * 100, 1) : 0,
                    'avg_progress' => $avgProgress,
                    'attempts' => $attempts,
                    'price' => $price,
                    'revenue' => $price > 0 ? round($price * $total, 2) : 0,
                    'watch_seconds' => (int) ($watchByCourse[$course->id]->seconds ?? 0),
                ];
            })
            ->sortByDesc('enrollments')
            ->values();

        // ── Exam performance ────────────────────────
        $examStats = ExamAttempt::where('tenant_id', $tenantId)
            ->where('status', 'submitted')
            ->selectRaw('exam_id, count(*) as total, sum(case when passed then 1 else 0 end) as passed, avg(percentage) as avg_score, max(percentage) as highest, min(percentage) as lowest')
            ->groupBy('exam_id')
            ->get()
            ->keyBy('exam_id');

        $result['exams'] = Exam::where('tenant_id', $tenantId)
            ->get()
            ->map(function (Exam $exam) use ($examStats) {
                $stats = $examStats[$exam->id] ?? null;
                $total = (int) ($stats->total ?? 0);
                $passedCount = (int) ($stats->passed ?? 0);
                $avg = $stats?->avg_score;
                $max = $stats?->highest;
                $min = $stats?->lowest;

                return [
                    'id' => $exam->id,
                    'title' => $exam->title,
                    'attempts' => $total,
                    'avg_score' => round((float) ($avg ?? 0), 2),
                    'pass_rate' => $total > 0 ? round(($passedCount / $total) * 100, 1) : 0,
                    'highest' => $max !== null ? round((float) $max, 2) : null,
                    'lowest' => $min !== null ? round((float) $min, 2) : null,
                    'passed' => $passedCount,
                ];
            })
            ->sortByDesc('attempts')
            ->values();

        if (! $includeActivity) {
            return $result;
        }

        // ── Top students ────────────────────────────
        $studentIds = Enrollment::where('tenant_id', $tenantId)->distinct('user_id')->pluck('user_id');

        $studentAgg = ExamAttempt::where('tenant_id', $tenantId)
            ->whereIn('user_id', $studentIds)
            ->selectRaw('user_id, count(*) as attempts, avg(percentage) as avg_score, sum(case when passed then 1 else 0 end) as passed')
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');

        $result['top_students'] = User::whereIn('id', $studentIds)
            ->get()
            ->map(function ($student) use ($studentAgg) {
                $agg = $studentAgg[$student->id] ?? null;

                return [
                    'id' => $student->id,
                    'name' => $student->name,
                    'email' => $student->email,
                    'attempts' => (int) ($agg->attempts ?? 0),
                    'avg_score' => round((float) ($agg->avg_score ?? 0), 1),
                    'passed' => (int) ($agg->passed ?? 0),
                ];
            })
            ->filter(fn ($s) => $s['attempts'] > 0)
            ->sortByDesc('avg_score')
            ->values()
            ->take(10);

        // ── Recent activity ─────────────────────────
        $result['recent_attempts'] = ExamAttempt::with(['exam', 'student'])
            ->where('tenant_id', $tenantId)
            ->where('status', 'submitted')
            ->orderByDesc('submitted_at')
            ->take(8)
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'student_name' => $a->student?->name,
                'exam_title' => $a->exam?->title,
                'score' => (float) $a->score,
                'percentage' => (float) $a->percentage,
                'passed' => (bool) $a->passed,
                'submitted_at' => $a->submitted_at?->toIso8601String(),
            ]);

        $result['recent_enrollments'] = Enrollment::with(['student', 'course'])
            ->where('tenant_id', $tenantId)
            ->orderByDesc('enrolled_at')
            ->take(8)
            ->get()
            ->map(fn ($e) => [
                'id' => $e->id,
                'student_name' => $e->student?->name,
                'course_title' => $e->course?->title,
                'enrolled_at' => $e->enrolled_at?->toIso8601String(),
            ]);

        return $result;
    }

    private function writeOverviewSheet(Spreadsheet $spreadsheet, array $data): void
    {
        $ov = $data['overview'];
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Overview');

        $rows = [
            ['Metric', 'Value'],
            ['Students', $ov['total_students']],
            ['Enrollments', $ov['total_enrollments']],
            ['Courses', $ov['total_courses']],
            ['Exams', $ov['total_exams']],
            ['Attempts', $ov['total_attempts']],
            ['Pass rate (%)', $ov['pass_rate']],
            ['Completion rate (%)', $ov['completion_rate']],
            ['Revenue', $ov['revenue']],
            ['Profit', $ov['profit']],
            ['Watch hours', $ov['watch_hours']],
            ['Active students (watch)', $ov['active_students']],
            ['Cards redeemed', $ov['cards_redeemed']],
        ];

        $sheet->fromArray($rows, null, 'A1');
        $this->styleSheet($sheet, count($rows));
    }

    private function writeRevenueSheet(Spreadsheet $spreadsheet, array $data): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Revenue');
        $sheet->fromArray([['Date', 'Revenue', 'Enrollments', 'Attempts', 'Watch seconds']], null, 'A1');
        $row = 2;
        foreach ($data['daily'] as $d) {
            $sheet->fromArray([
                $d['date'],
                $d['revenue'],
                $d['enrollments'],
                $d['attempts'],
                $d['watch_seconds'],
            ], null, "A{$row}");
            $this->protectCell($sheet, "A{$row}");
            $row++;
        }
        $this->styleSheet($sheet, $row - 1);
    }

    private function writeCoursesSheet(Spreadsheet $spreadsheet, array $data): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Courses');
        $sheet->fromArray([
            ['Course', 'Enrollments', 'Completed', 'Completion %', 'Avg progress %', 'Attempts', 'Price', 'Revenue', 'Watch seconds'],
        ], null, 'A1');
        $row = 2;
        foreach ($data['courses'] as $c) {
            $sheet->fromArray([
                $c['title'],
                $c['enrollments'],
                $c['completed'],
                $c['completion_rate'],
                $c['avg_progress'],
                $c['attempts'],
                $c['price'],
                $c['revenue'],
                $c['watch_seconds'],
            ], null, "A{$row}");
            $this->protectCell($sheet, "A{$row}");
            $row++;
        }
        $this->styleSheet($sheet, $row - 1);
    }

    private function writeExamsSheet(Spreadsheet $spreadsheet, array $data): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Exams');
        $sheet->fromArray([
            ['Exam', 'Attempts', 'Avg score %', 'Pass rate %', 'Highest %', 'Lowest %', 'Passed'],
        ], null, 'A1');
        $row = 2;
        foreach ($data['exams'] as $e) {
            $sheet->fromArray([
                $e['title'],
                $e['attempts'],
                $e['avg_score'],
                $e['pass_rate'],
                $e['highest'],
                $e['lowest'],
                $e['passed'],
            ], null, "A{$row}");
            $this->protectCell($sheet, "A{$row}");
            $row++;
        }
        $this->styleSheet($sheet, $row - 1);
    }

    private function writeTrafficSheet(Spreadsheet $spreadsheet, array $data): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Traffic Sources');
        $sheet->fromArray([
            ['Source', 'Enrollments', 'Share %', 'Revenue'],
        ], null, 'A1');
        $row = 2;
        foreach ($data['traffic_sources'] as $s) {
            $sheet->fromArray([
                $s['source'],
                $s['total'],
                $s['percentage'],
                $s['revenue'],
            ], null, "A{$row}");
            $this->protectCell($sheet, "A{$row}");
            $row++;
        }
        $this->styleSheet($sheet, $row - 1);
    }

    /**
     * Excel formula injection guard: user-controlled strings beginning with
     * =, +, -, @ or a tab must be written as literal strings — otherwise
     * Excel evaluates them as formulas when the report is opened.
     */
    private function protectCell(Worksheet $sheet, string $cell): void
    {
        $value = (string) $sheet->getCell($cell)->getValue();

        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            $sheet->setCellValueExplicit(
                $cell,
                $value,
                DataType::TYPE_STRING
            );
        }
    }

    private function styleSheet(Worksheet $sheet, int $lastRow): void
    {
        foreach (range('A', 'I') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        $sheet->getStyle("A1:{$sheet->getHighestColumn()}1")->getFont()->setBold(true);
        if ($lastRow > 0) {
            $sheet->getStyle("A1:{$sheet->getHighestColumn()}{$lastRow}")->getAlignment()->setWrapText(true);
        }
    }
}
