<?php

namespace App\Services;

use App\Models\Course;
use App\Models\DailyStat;
use App\Models\Enrollment;
use App\Models\ExamAttempt;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

class AnalyticsService
{
    public function superAdmin(): array
    {
        return Cache::remember('analytics.super_admin', 3600, function () {
            return [
                'total_tenants' => Tenant::count(),
                'total_users' => User::count(),
                'total_students' => Enrollment::distinct('user_id')->count('user_id'),
                'total_courses' => Course::count(),
                'total_revenue' => Payment::where('status', 'completed')->sum('amount'),
                'active_subscriptions' => TenantSubscription::whereIn('status', ['active', 'trialing'])->count(),
                'recent_payments' => Payment::with('subscription')
                    ->orderByDesc('created_at')
                    ->take(10)
                    ->get()
                    ->map(fn ($p) => [
                        'id' => $p->id,
                        'amount' => $p->amount,
                        'currency' => $p->currency,
                        'status' => $p->status,
                        'created_at' => $p->created_at,
                    ]),
            ];
        });
    }

    public function teacher(string $tenantId): array
    {
        return Cache::remember("analytics.teacher.{$tenantId}", 1800, function () use ($tenantId) {
            $courseIds = Course::byTenant($tenantId)->pluck('id');

            $totalStudents = Enrollment::byTenant($tenantId)
                ->distinct('user_id')
                ->count('user_id');

            $completedEnrollments = Enrollment::byTenant($tenantId)
                ->where('status', 'completed')
                ->count();

            $completionRate = $totalStudents > 0
                ? round(($completedEnrollments / $totalStudents) * 100, 2)
                : 0;

            $avgScore = ExamAttempt::query()
                ->whereHas('exam', fn ($q) => $q->whereIn('course_id', $courseIds))
                ->where('status', 'submitted')
                ->avg('percentage');

            $totalAttempts = ExamAttempt::query()
                ->whereHas('exam', fn ($q) => $q->whereIn('course_id', $courseIds))
                ->count();

            return [
                'total_courses' => count($courseIds),
                'total_students' => $totalStudents,
                'completion_rate' => $completionRate,
                'avg_exam_score' => round($avgScore ?: 0, 2),
                'total_exam_attempts' => $totalAttempts,
                'recent_enrollments' => Enrollment::byTenant($tenantId)
                    ->with(['student', 'course'])
                    ->orderByDesc('enrolled_at')
                    ->take(5)
                    ->get()
                    ->map(fn ($e) => [
                        'student_name' => $e->student?->name,
                        'course_title' => $e->course?->title,
                        'enrolled_at' => $e->enrolled_at,
                    ]),
            ];
        });
    }

    public function student(string $tenantId, string $userId): array
    {
        return [
            'enrolled_courses' => Enrollment::byTenant($tenantId)
                ->where('user_id', $userId)
                ->count(),
            'completed_courses' => Enrollment::byTenant($tenantId)
                ->where('user_id', $userId)
                ->where('status', 'completed')
                ->count(),
            'exam_attempts' => ExamAttempt::byTenant($tenantId)
                ->where('user_id', $userId)
                ->count(),
            'avg_score' => ExamAttempt::byTenant($tenantId)
                ->where('user_id', $userId)
                ->where('status', 'submitted')
                ->avg('percentage'),
        ];
    }

    public function getDailyStats(string $tenantId, string $statType, int $days = 30): array
    {
        $stats = DailyStat::byTenant($tenantId)
            ->where('stat_type', $statType)
            ->where('stat_date', '>=', now()->subDays($days))
            ->orderBy('stat_date')
            ->get()
            ->map(fn ($s) => [
                'date' => $s->stat_date,
                'data' => $s->data,
            ]);

        return $stats->toArray();
    }
}
