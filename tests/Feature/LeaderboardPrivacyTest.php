<?php

namespace Tests\Feature;

use App\Models\StudentPoint;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use Symfony\Component\Uid\UuidV7;

/**
 * Student leaderboard privacy.
 *
 * The board used to expose every student's real name to every other student.
 * These tests lock in the privacy contract:
 *   - the default is anonymous (identities of others are never leaked),
 *   - a teacher still sees identifiable results,
 *   - `disabled` hides the board from students but keeps their own rank,
 *   - `named` is an explicit, deliberate opt-in.
 * No response ever carries another student's email, phone or internal id.
 */
class LeaderboardPrivacyTest extends TenantTestCase
{
    private function studentIn(Tenant $tenant, int $points): User
    {
        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');

        StudentPoint::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenant->id,
            'user_id' => $student->id,
            'points' => $points,
            'reason' => 'lesson',
        ]);

        return $student;
    }

    private function setMode(Tenant $tenant, string $mode): void
    {
        TenantSetting::updateOrCreate(
            ['tenant_id' => $tenant->id],
            ['id' => UuidV7::generate(), 'tenant_id' => $tenant->id, 'metadata' => ['leaderboard_mode' => $mode]],
        );
    }

    public function test_default_mode_is_anonymous_and_never_leaks_other_identities(): void
    {
        $tenant = $this->makeTenant();
        $me = $this->studentIn($tenant, 10);
        $other = $this->studentIn($tenant, 99);

        $response = $this->getJson('/api/v1/student/leaderboard', $this->authHeaders($me, $tenant));

        $response->assertOk()
            ->assertJsonPath('mode', 'anonymous')
            ->assertJsonPath('enabled', true);

        $board = $response->json('leaderboard');
        $mine = collect($board)->firstWhere('is_me', true);
        $theirs = collect($board)->firstWhere('is_me', false);

        // My own row is identifiable and carries my id.
        $this->assertSame($me->id, $mine['user_id']);

        // The other student is masked: no name, no avatar, no internal id.
        $this->assertNull($theirs['user_id']);
        $this->assertNull($theirs['avatar_url']);
        $this->assertSame('طالب', $theirs['name']);
        $this->assertSame(99, $theirs['points']);

        // No PII keys anywhere in the payload.
        $raw = $response->getContent();
        $this->assertStringNotContainsString('email', $raw);
        $this->assertStringNotContainsString($other->email, $raw);
    }

    public function test_named_mode_is_an_explicit_opt_in_that_shows_names(): void
    {
        $tenant = $this->makeTenant();
        $me = $this->studentIn($tenant, 10);
        $other = $this->studentIn($tenant, 99);
        $this->setMode($tenant, 'named');

        $response = $this->getJson('/api/v1/student/leaderboard', $this->authHeaders($me, $tenant));

        $response->assertOk()->assertJsonPath('mode', 'named');
        $name = collect($response->json('leaderboard'))->firstWhere('user_id', $other->id)['name'] ?? null;
        $this->assertSame($other->name, $name);
    }

    public function test_teacher_sees_identifiable_names_regardless_of_mode(): void
    {
        $tenant = $this->makeTenant();
        $me = $this->studentIn($tenant, 10);
        $other = $this->studentIn($tenant, 99);

        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');

        $response = $this->getJson('/api/v1/student/leaderboard', $this->authHeaders($teacher, $tenant));

        $response->assertOk();
        $names = collect($response->json('leaderboard'))->pluck('name');
        $this->assertTrue($names->contains($other->name));
        $this->assertTrue($names->contains($me->name));
    }

    public function test_disabled_hides_board_from_students_but_keeps_their_own_rank(): void
    {
        $tenant = $this->makeTenant();
        $me = $this->studentIn($tenant, 10);
        $this->studentIn($tenant, 99);
        $this->setMode($tenant, 'disabled');

        $response = $this->getJson('/api/v1/student/leaderboard', $this->authHeaders($me, $tenant));

        $response->assertOk()
            ->assertJsonPath('enabled', false)
            ->assertJsonPath('leaderboard', []);

        // My own position is still available.
        $this->assertNotNull($response->json('my_rank'));
    }

    public function test_teacher_policy_endpoint_reads_and_writes_mode(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');

        $default = $this->getJson('/api/v1/teacher/leaderboard-policy', $this->authHeaders($teacher, $tenant));
        $default->assertOk()->assertJsonPath('policy.mode', 'anonymous');

        $put = $this->putJson('/api/v1/teacher/leaderboard-policy', ['mode' => 'named'], $this->authHeaders($teacher, $tenant));
        $put->assertOk()->assertJsonPath('policy.mode', 'named');

        $this->getJson('/api/v1/teacher/leaderboard-policy', $this->authHeaders($teacher, $tenant))
            ->assertOk()->assertJsonPath('policy.mode', 'named');
    }

    public function test_teacher_policy_endpoint_rejects_invalid_mode(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');

        $this->putJson('/api/v1/teacher/leaderboard-policy', ['mode' => 'public-names'], $this->authHeaders($teacher, $tenant))
            ->assertStatus(422);
    }

    public function test_student_cannot_change_the_leaderboard_policy(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');

        $this->putJson('/api/v1/teacher/leaderboard-policy', ['mode' => 'disabled'], $this->authHeaders($student, $tenant))
            ->assertStatus(403);
    }
}
