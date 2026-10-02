<?php

namespace App\Jobs;

use App\Models\Course;
use App\Models\DailyStat;
use App\Models\Enrollment;
use App\Models\ExamAttempt;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Uid\UuidV7;

class GenerateDailyStatsJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(): void
    {
        $today = now()->startOfDay();

        Tenant::query()
            ->select('id')
            ->chunkById(100, function ($tenants) use ($today) {
                foreach ($tenants as $tenant) {
                    $this->generateForTenant($tenant->id, $today);
                }
            });

        $this->invalidateAnalyticsCaches();
    }

    private function generateForTenant(string $tenantId, Carbon $today): void
    {
        $this->storeStat($tenantId, $today, 'tenant.overview', null, [
            'total_courses' => Course::byTenant($tenantId)->count(),
            'total_enrollments' => Enrollment::byTenant($tenantId)->count(),
            'active_enrollments' => Enrollment::byTenant($tenantId)->where('status', 'active')->count(),
            'total_payments' => Payment::where('tenant_id', $tenantId)->count(),
            'revenue' => Payment::where('tenant_id', $tenantId)->where('status', 'completed')->sum('amount'),
            'active_subscriptions' => TenantSubscription::where('tenant_id', $tenantId)
                ->whereIn('status', ['active', 'trialing'])->count(),
        ]);

        $this->storeStat($tenantId, $today, 'tenant.exams', null, [
            'total_attempts' => ExamAttempt::byTenant($tenantId)->count(),
            'submitted' => ExamAttempt::byTenant($tenantId)->where('status', 'submitted')->count(),
            'avg_score' => ExamAttempt::byTenant($tenantId)->where('status', 'submitted')->avg('percentage') ?: 0,
        ]);

        $courseStats = Enrollment::byTenant($tenantId)
            ->selectRaw("course_id, count(*) as enroll_count, sum(case when status = 'completed' then 1 else 0 end) as completed_count")
            ->groupBy('course_id')
            ->get()
            ->keyBy('course_id');

        Course::byTenant($tenantId)
            ->get()
            ->each(function (Course $course) use ($tenantId, $today, $courseStats) {
                $stats = $courseStats[$course->id] ?? null;
                $enrollCount = (int) ($stats->enroll_count ?? 0);
                $completedCount = (int) ($stats->completed_count ?? 0);

                $this->storeStat($tenantId, $today, 'course.overview', $course, [
                    'enrollments' => $enrollCount,
                    'completed' => $completedCount,
                    'completion_rate' => $enrollCount > 0 ? round(($completedCount / $enrollCount) * 100, 2) : 0,
                ]);
            });
    }

    private function invalidateAnalyticsCaches(): void
    {
        Cache::forget('analytics.super_admin');

        Tenant::query()->select('id')->chunkById(100, function ($tenants) {
            foreach ($tenants as $tenant) {
                Cache::forget("analytics.teacher.{$tenant->id}");
            }
        });
    }

    private function storeStat(string $tenantId, Carbon $date, string $statType, $statable, array $data): void
    {
        DailyStat::updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'stat_date' => $date,
                'stat_type' => $statType,
                'statable_type' => $statable ? get_class($statable) : null,
                'statable_id' => $statable?->id,
            ],
            [
                'id' => UuidV7::generate(),
                'data' => $data,
            ]
        );
    }
}
