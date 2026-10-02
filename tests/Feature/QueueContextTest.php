<?php

namespace Tests\Feature;

use App\Jobs\GenerateDailyStatsJob;
use App\Models\Course;
use App\Models\DailyStat;
use App\Models\Enrollment;
use Illuminate\Support\Str;
use Symfony\Component\Uid\UuidV7;

class QueueContextTest extends TenantTestCase
{
    public function test_daily_stats_job_runs_in_queue_context_without_a_request(): void
    {
        $tenant = $this->makeTenant();

        $course = Course::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenant->id,
            'title' => 'Course',
            'slug' => 'c-'.Str::random(6),
            'is_published' => true,
            'sort_order' => 0,
        ]);

        Enrollment::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenant->id,
            'course_id' => $course->id,
            'user_id' => $this->makeUser()->id,
            'status' => 'active',
            'enrolled_at' => now(),
        ]);

        (new GenerateDailyStatsJob)->handle();

        $this->assertDatabaseHas('daily_stats', [
            'tenant_id' => $tenant->id,
            'stat_type' => 'tenant.overview',
        ]);

        $stat = DailyStat::where('tenant_id', $tenant->id)->where('stat_type', 'tenant.overview')->first();
        $this->assertSame(1, (int) ($stat->data['total_courses'] ?? 0));
        $this->assertSame(1, (int) ($stat->data['total_enrollments'] ?? 0));
    }
}
