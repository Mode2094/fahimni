<?php

namespace Tests\Feature;

/**
 * Regression guard for the analytics SQL portability defect (audit 2026-11-25).
 *
 * The daily series used the PostgreSQL-only `to_char(ts, 'YYYY-MM-DD')`. SQLite
 * — the driver every test run uses — does not implement `to_char`, so
 * `GET /api/v1/teacher/analytics` answered 500 with "no such function: to_char".
 *
 * The query now picks a driver-appropriate day expression
 * (`strftime` / `DATE_FORMAT` / `to_char`), so the endpoint is exercised on the
 * test driver and stays honest in production.
 */
class TeacherAnalyticsPortabilityTest extends TenantTestCase
{
    public function test_analytics_endpoint_builds_the_daily_series_on_sqlite(): void
    {
        $tenant = $this->makeTenant();
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');
        $this->attachSubscription($tenant);

        $response = $this->getJson(
            '/api/v1/teacher/analytics?days=30',
            $this->authHeaders($teacher, $tenant),
        );

        $response->assertOk();
        $response->assertJsonStructure([
            'overview',
            'revenue',
            'daily' => [['date', 'enrollments', 'attempts', 'revenue', 'watch_seconds']],
        ]);

        // The requested window must be fully materialised (30 day buckets).
        $this->assertCount(30, $response->json('daily'));
    }
}
