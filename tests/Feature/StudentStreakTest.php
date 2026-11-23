<?php

namespace Tests\Feature;

use App\Models\StudentStreak;
use App\Services\StudentGamificationService;
use Illuminate\Support\Carbon;

/**
 * Daily streak: consecutive calendar days of student activity, per tenant.
 *
 * Rules under test: same-day activity is idempotent, a next-day activity
 * extends the streak, a skipped day restarts it at 1, a stale streak reads as
 * zero without a write, and any logged activity (not just login) feeds it.
 */
class StudentStreakTest extends TenantTestCase
{
    public function test_consecutive_days_extend_the_streak(): void
    {
        $tenant = $this->makeTenant();
        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');
        $service = app(StudentGamificationService::class);

        $service->touchStreak($student, $tenant->id, Carbon::parse('2026-01-01 09:00'));
        $service->touchStreak($student, $tenant->id, Carbon::parse('2026-01-02 22:00'));
        $streak = $service->touchStreak($student, $tenant->id, Carbon::parse('2026-01-03 07:00'));

        $this->assertSame(3, $streak->current_streak);
        $this->assertSame(3, $streak->longest_streak);
    }

    public function test_repeated_activity_on_the_same_day_is_idempotent(): void
    {
        $tenant = $this->makeTenant();
        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');
        $service = app(StudentGamificationService::class);

        $service->touchStreak($student, $tenant->id, Carbon::parse('2026-02-01 08:00'));
        $service->touchStreak($student, $tenant->id, Carbon::parse('2026-02-01 13:00'));
        $streak = $service->touchStreak($student, $tenant->id, Carbon::parse('2026-02-01 23:30'));

        $this->assertSame(1, $streak->current_streak);
        $this->assertSame(1, StudentStreak::withoutTenant()->where('user_id', $student->id)->count());
    }

    public function test_a_skipped_day_restarts_the_streak_but_keeps_the_longest(): void
    {
        $tenant = $this->makeTenant();
        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');
        $service = app(StudentGamificationService::class);

        $service->touchStreak($student, $tenant->id, Carbon::parse('2026-03-01'));
        $service->touchStreak($student, $tenant->id, Carbon::parse('2026-03-02'));
        $service->touchStreak($student, $tenant->id, Carbon::parse('2026-03-03'));
        // Gap: 2026-03-04 skipped.
        $streak = $service->touchStreak($student, $tenant->id, Carbon::parse('2026-03-05'));

        $this->assertSame(1, $streak->current_streak);
        $this->assertSame(3, $streak->longest_streak);
    }

    public function test_a_stale_streak_reads_as_zero_without_writing(): void
    {
        $tenant = $this->makeTenant();
        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');
        $service = app(StudentGamificationService::class);

        $service->touchStreak($student, $tenant->id, Carbon::parse('2026-04-01'));
        $service->touchStreak($student, $tenant->id, Carbon::parse('2026-04-02'));

        $this->travelTo(Carbon::parse('2026-04-10 10:00'));
        $read = $service->streakFor($student, $tenant->id);

        $this->assertSame(0, $read['current']);
        $this->assertSame(2, $read['longest']);
        $this->assertFalse($read['active_today']);

        // Reading must not mutate the stored row.
        $this->assertSame(2, StudentStreak::withoutTenant()->where('user_id', $student->id)->value('current_streak'));
    }

    public function test_yesterdays_activity_still_counts_as_alive_today(): void
    {
        $tenant = $this->makeTenant();
        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');
        $service = app(StudentGamificationService::class);

        $this->travelTo(Carbon::parse('2026-05-01 09:00'));
        $service->touchStreak($student, $tenant->id);

        $this->travelTo(Carbon::parse('2026-05-02 09:00'));
        $read = $service->streakFor($student, $tenant->id);

        $this->assertSame(1, $read['current']);
        $this->assertFalse($read['active_today']);
    }

    public function test_logging_any_activity_feeds_the_streak(): void
    {
        $tenant = $this->makeTenant();
        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');

        app(StudentGamificationService::class)->logActivity($student, $tenant->id, 'lesson_completed', 'أكمل درساً');

        $read = app(StudentGamificationService::class)->streakFor($student, $tenant->id);
        $this->assertSame(1, $read['current']);
        $this->assertTrue($read['active_today']);
    }

    public function test_streak_endpoint_returns_the_shape_and_appears_in_stats(): void
    {
        $tenant = $this->makeTenant();
        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');

        app(StudentGamificationService::class)->touchStreak($student, $tenant->id);

        $res = $this->getJson('/api/v1/student/streak', $this->authHeaders($student, $tenant));
        $res->assertOk()->assertJsonStructure(['streak' => ['current', 'longest', 'last_activity_date', 'active_today']]);
        $this->assertSame(1, $res->json('streak.current'));
        $this->assertTrue($res->json('streak.active_today'));

        $stats = $this->getJson('/api/v1/student/stats', $this->authHeaders($student, $tenant));
        $stats->assertOk();
        $this->assertSame(1, $stats->json('stats.streak.current'));
    }
}
