<?php

namespace Tests\Feature;

use App\Models\ActivationCard;
use App\Models\Course;
use Illuminate\Support\Str;
use Symfony\Component\Uid\UuidV7;

/**
 * Phase 4 — activation-card redemption hardening:
 * code normalisation, the staff-cannot-redeem guard, distinct
 * cancelled/expired messages and the redeem rate limit.
 */
class ActivationCardRedeemTest extends TenantTestCase
{
    private function makeCourse(string $tenantId): Course
    {
        return Course::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenantId,
            'title' => 'Card Course',
            'slug' => 'cc-'.Str::random(6),
            'is_published' => true,
            'sort_order' => 0,
        ]);
    }

    private function makeCard(string $tenantId, string $courseId, array $attrs = []): ActivationCard
    {
        return ActivationCard::create(array_merge([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenantId,
            'course_id' => $courseId,
            'code' => strtoupper(bin2hex(random_bytes(6))),
            'status' => 'generated',
            'metadata' => [],
        ], $attrs));
    }

    public function test_a_lowercase_code_still_redeems(): void
    {
        $tenant = $this->makeTenant();
        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');

        $course = $this->makeCourse($tenant->id);
        $card = $this->makeCard($tenant->id, $course->id, ['code' => 'ABCDEF123456']);

        $response = $this->postJson(
            '/api/v1/cards/redeem',
            ['code' => 'abcdef123456'],
            $this->authHeaders($student, $tenant)
        );

        $response->assertOk();
        $this->assertDatabaseHas('activation_cards', ['id' => $card->id, 'status' => 'redeemed']);
        $this->assertDatabaseHas('enrollments', [
            'tenant_id' => $tenant->id,
            'course_id' => $course->id,
            'user_id' => $student->id,
        ]);
    }

    public function test_a_valid_card_resolves_tenant_and_adds_a_new_student_membership(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $student = $this->makeUser();
        $course = $this->makeCourse($tenant->id);
        $card = $this->makeCard($tenant->id, $course->id);
        $headers = [
            'Authorization' => 'Bearer '.$student->createToken('test')->plainTextToken,
            'Accept' => 'application/json',
        ];

        $response = $this->postJson('/api/v1/cards/redeem', ['code' => $card->code], $headers);

        $response->assertOk();
        $this->assertDatabaseHas('tenant_user', [
            'tenant_id' => $tenant->id,
            'user_id' => $student->id,
            'role' => 'student',
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('activation_cards', ['id' => $card->id, 'status' => 'redeemed']);
        $this->assertDatabaseHas('enrollments', [
            'tenant_id' => $tenant->id,
            'course_id' => $course->id,
            'user_id' => $student->id,
        ]);

        // The relation established by redemption restores tenant context when
        // a fresh tab, private window, or PWA request has no local tenant id.
        $this->app['auth']->forgetGuards();
        $this->withHeaders($headers)->getJson('/api/v1/dashboard')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withHeaders($headers)->getJson('/api/v1/assignments?course_id='.$course->id)->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withHeaders($headers)->getJson('/api/v1/scheduled-lessons')->assertOk();
    }

    public function test_a_teacher_cannot_redeem_their_own_card(): void
    {
        $tenant = $this->makeTenant();
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');
        $this->attachSubscription($tenant);

        $course = $this->makeCourse($tenant->id);
        $card = $this->makeCard($tenant->id, $course->id);

        $response = $this->postJson(
            '/api/v1/cards/redeem',
            ['code' => $card->code],
            $this->authHeaders($teacher, $tenant)
        );

        $response->assertStatus(403);
        $this->assertDatabaseHas('activation_cards', ['id' => $card->id, 'status' => 'generated']);
        $this->assertDatabaseMissing('enrollments', [
            'course_id' => $course->id,
            'user_id' => $teacher->id,
        ]);
    }

    public function test_a_cancelled_card_reports_its_own_message(): void
    {
        $tenant = $this->makeTenant();
        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');

        $course = $this->makeCourse($tenant->id);
        $card = $this->makeCard($tenant->id, $course->id, [
            'status' => 'cancelled',
            'cancelled_at' => now(),
        ]);

        $response = $this->postJson(
            '/api/v1/cards/redeem',
            ['code' => $card->code],
            $this->authHeaders($student, $tenant)
        );

        $response->assertStatus(410);
        $response->assertJsonPath('message', 'This card has been cancelled.');
        $this->assertDatabaseHas('activation_cards', ['id' => $card->id, 'status' => 'cancelled']);
    }

    public function test_an_expired_card_is_marked_and_rejected(): void
    {
        $tenant = $this->makeTenant();
        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');

        $course = $this->makeCourse($tenant->id);
        $card = $this->makeCard($tenant->id, $course->id, [
            'expires_at' => now()->subDay(),
        ]);

        $response = $this->postJson(
            '/api/v1/cards/redeem',
            ['code' => $card->code],
            $this->authHeaders($student, $tenant)
        );

        $response->assertStatus(410);
        $response->assertJsonPath('message', 'Card has expired.');
        $this->assertDatabaseHas('activation_cards', ['id' => $card->id, 'status' => 'expired']);
    }

    public function test_the_redeem_endpoint_is_rate_limited(): void
    {
        $tenant = $this->makeTenant();
        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');

        $headers = $this->authHeaders($student, $tenant);

        $statuses = [];
        for ($i = 0; $i < 11; $i++) {
            $statuses[] = $this->postJson('/api/v1/cards/redeem', ['code' => 'NOPE00000000'], $headers)->status();
        }

        // The limiter allows 10 per minute per user; the 11th call is throttled.
        $this->assertSame(404, $statuses[0]);
        $this->assertSame(429, end($statuses));
    }
}
