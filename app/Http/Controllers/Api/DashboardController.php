<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\Booking;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\User;
use App\Services\AdminNotificationService;
use App\Services\SuperAdminStatisticsService;
use App\Traits\AuthorizesTenantAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    use AuthorizesTenantAccess;

    public function __construct(
        private readonly AdminNotificationService $adminNotifications,
        private readonly SuperAdminStatisticsService $superAdminStatistics,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->header('X-Tenant-Id');
        $user = $request->user();

        if ($user->is_super_admin) {
            return $this->superAdminDashboard();
        }

        $pivot = $user->tenantPivots()
            ->where('tenant_id', $tenantId)
            ->first();

        if (! $pivot) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        return $this->isTenantStaff($request, $tenantId)
            ? $this->teacherDashboard($tenantId, $user, (string) $pivot->role)
            : $this->studentDashboard($tenantId, $user);
    }

    private function superAdminDashboard(): JsonResponse
    {
        $snapshot = $this->superAdminStatistics->snapshot();
        $metrics = $snapshot['subscription_metrics'];
        $financials = $metrics['financials'];
        $grossByCurrency = $financials['gross_collected_by_currency'];

        // Keep the old scalar for older clients only when it has one known
        // currency. Never sum unlike currencies into a misleading total.
        $legacyRevenue = is_array($grossByCurrency) && count($grossByCurrency) === 1
            ? (float) reset($grossByCurrency)
            : null;
        $legacyCurrency = is_array($grossByCurrency) && count($grossByCurrency) === 1
            ? (string) array_key_first($grossByCurrency)
            : null;

        return response()->json(array_merge($snapshot['overview'], [
            // Backward-compatible aliases now use explicit billing semantics.
            'active_subscriptions' => $metrics['paid_subscribers'],
            'total_revenue' => $legacyRevenue,
            'total_revenue_currency' => $legacyCurrency,
            'total_revenue_by_currency' => $grossByCurrency,
            'subscription_metrics' => $metrics,
            // Provider transactions are represented by the financial summary;
            // do not expose the separate student-to-teacher payments here.
            'recent_payments' => [],
        ]));
    }

    private function teacherDashboard(string $tenantId, User $user, string $tenantRole): JsonResponse
    {
        $bookings = Booking::where('tenant_id', $tenantId);

        // Match the teacher booking screen: individual team teachers see their
        // own requests, while owners/admins see the whole workspace.
        if ($tenantRole === 'teacher') {
            $bookings->where('teacher_id', $user->id);
        }

        $activeBookings = (clone $bookings)
            ->whereIn('status', [Booking::STATUS_PENDING, Booking::STATUS_CONFIRMED])
            ->where('ends_at', '>', now());
        $pendingAssignmentSubmissions = AssignmentSubmission::byTenant($tenantId)
            ->whereIn('status', ['submitted', 'late'])
            ->whereHas('assignment')
            ->count();
        $pendingExamReviews = ExamAttempt::byTenant($tenantId)
            ->where('status', 'submitted')
            ->where('needs_review', true)
            ->count();

        return response()->json([
            'total_courses' => Course::byTenant($tenantId)->count(),
            'total_students' => Enrollment::byTenant($tenantId)->count(),
            'total_exams' => Exam::byTenant($tenantId)->count(),
            'total_attempts' => ExamAttempt::byTenant($tenantId)->count(),
            'pending_assignment_submissions' => $pendingAssignmentSubmissions,
            'pending_exam_reviews' => $pendingExamReviews,
            'unread_admin_notifications' => $this->adminNotifications->teacherUnreadCount($user),
            'pending_bookings' => (clone $activeBookings)->where('status', Booking::STATUS_PENDING)->count(),
            'upcoming_bookings' => (clone $activeBookings)->count(),
            'recent_bookings' => (clone $bookings)
                ->with('student')
                ->orderByDesc('starts_at')
                ->take(5)
                ->get()
                ->map(fn (Booking $b) => [
                    'id' => $b->id,
                    'kind' => $b->kind,
                    'status' => $b->status,
                    'starts_at' => $b->starts_at?->toIso8601String(),
                    'student' => $b->student ? ['id' => $b->student->id, 'name' => $b->student->name] : null,
                ]),
        ]);
    }

    private function studentDashboard(string $tenantId, $user): JsonResponse
    {
        $now = now();
        $taskWindowEnd = $now->copy()->addDays(7);
        $courseIds = Enrollment::byTenant($tenantId)
            ->where('user_id', $user->id)
            ->effective()
            ->pluck('course_id');

        $dueAssignments = collect();
        $upcomingExams = collect();

        if ($courseIds->isNotEmpty()) {
            $dueAssignments = Assignment::byTenant($tenantId)
                ->whereIn('course_id', $courseIds)
                ->where('is_published', true)
                ->whereNotNull('due_at')
                ->where(function ($query) use ($now, $taskWindowEnd) {
                    $query->whereBetween('due_at', [$now, $taskWindowEnd])
                        ->orWhere(function ($overdue) use ($now) {
                            $overdue->where('due_at', '<', $now)
                                ->where('allow_late', true);
                        });
                })
                ->whereDoesntHave('submissions', fn ($query) => $query
                    ->where('user_id', $user->id)
                    ->whereIn('status', ['submitted', 'late', 'graded']))
                ->with('course:id,title')
                ->orderBy('due_at')
                ->limit(5)
                ->get(['id', 'course_id', 'title', 'due_at', 'allow_late'])
                ->map(fn (Assignment $assignment) => [
                    'id' => $assignment->id,
                    'course_id' => $assignment->course_id,
                    'course_title' => $assignment->course?->title,
                    'title' => $assignment->title,
                    'due_at' => $assignment->due_at?->toIso8601String(),
                    'allow_late' => (bool) $assignment->allow_late,
                    'is_overdue' => $assignment->due_at?->lt($now) ?? false,
                ])
                ->values();

            $upcomingExams = Exam::byTenant($tenantId)
                ->whereIn('course_id', $courseIds)
                ->where('is_published', true)
                ->visibleToStudent($user->id)
                ->whereNotNull('ends_at')
                ->whereBetween('ends_at', [$now, $taskWindowEnd])
                ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', $taskWindowEnd))
                ->with('course:id,title')
                ->orderBy('ends_at')
                ->limit(5)
                ->get(['id', 'course_id', 'title', 'starts_at', 'ends_at'])
                ->map(fn (Exam $exam) => [
                    'id' => $exam->id,
                    'course_id' => $exam->course_id,
                    'course_title' => $exam->course?->title,
                    'title' => $exam->title,
                    'starts_at' => $exam->starts_at?->toIso8601String(),
                    'ends_at' => $exam->ends_at?->toIso8601String(),
                ])
                ->values();
        }

        return response()->json([
            'enrolled_courses' => Enrollment::byTenant($tenantId)
                ->where('user_id', $user->id)
                ->count(),
            'completed_courses' => Enrollment::byTenant($tenantId)
                ->where('user_id', $user->id)
                ->where('status', 'completed')
                ->count(),
            'upcoming_exams' => Exam::byTenant($tenantId)
                ->where('is_published', true)
                ->count(),
            'learning_tasks' => [
                'due_assignments' => $dueAssignments,
                'upcoming_exams' => $upcomingExams,
            ],
        ]);
    }
}
