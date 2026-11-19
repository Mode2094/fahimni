<?php

namespace Tests\Feature;

use App\Models\StudentActivity;
use App\Models\StudentPoint;
use App\Models\Tenant;
use App\Models\User;
use Symfony\Component\Uid\UuidV7;

/**
 * Regression: /student/leaderboard and /student/activity used to throw
 * "Call to undefined method StudentProfileController::clampLimit()" (HTTP 500)
 * for every authenticated user — including the shared /profile page, which
 * always calls both endpoints.
 */
class StudentProfileApiTest extends TenantTestCase
{
    private function studentIn(Tenant $tenant): User
    {
        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');

        return $student;
    }

    public function test_leaderboard_returns_200_and_shape(): void
    {
        $tenant = $this->makeTenant();
        $student = $this->studentIn($tenant);

        StudentPoint::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenant->id,
            'user_id' => $student->id,
            'points' => 25,
            'reason' => 'lesson',
        ]);

        $response = $this->getJson('/api/v1/student/leaderboard', $this->authHeaders($student, $tenant));

        $response->assertOk()
            ->assertJsonStructure(['leaderboard']);

        $this->assertSame($student->id, $response->json('leaderboard.0.user_id'));
    }

    public function test_activity_returns_200_and_shape(): void
    {
        $tenant = $this->makeTenant();
        $student = $this->studentIn($tenant);

        StudentActivity::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenant->id,
            'user_id' => $student->id,
            'type' => 'lesson_completed',
            'description' => 'Completed a lesson',
            'metadata' => [],
            'created_at' => now(),
        ]);

        $response = $this->getJson('/api/v1/student/activity', $this->authHeaders($student, $tenant));

        $response->assertOk()
            ->assertJsonStructure(['activity'])
            ->assertJsonPath('activity.0.type', 'lesson_completed');
    }

    public function test_limit_is_clamped(): void
    {
        $tenant = $this->makeTenant();
        $student = $this->studentIn($tenant);

        // Absurd values must not error — they are clamped to a sane range.
        $this->getJson('/api/v1/student/activity?limit=999999', $this->authHeaders($student, $tenant))->assertOk();
        $this->getJson('/api/v1/student/activity?limit=0', $this->authHeaders($student, $tenant))->assertOk();
        $this->getJson('/api/v1/student/activity?limit=-5', $this->authHeaders($student, $tenant))->assertOk();
    }

    public function test_teacher_role_can_open_shared_profile_endpoints(): void
    {
        // /profile is a shared page; a teacher must not get a 500 from the
        // student endpoints it calls unconditionally.
        $tenant = $this->makeTenant();
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');

        $this->getJson('/api/v1/student/leaderboard', $this->authHeaders($teacher, $tenant))->assertOk();
        $this->getJson('/api/v1/student/activity', $this->authHeaders($teacher, $tenant))->assertOk();
    }
}
