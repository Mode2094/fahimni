<?php

namespace Tests\Feature;

use App\Models\ActivationCard;
use App\Models\CardRedemption;
use App\Models\Course;
use App\Models\Enrollment;
use Illuminate\Support\Str;
use Symfony\Component\Uid\UuidV7;

/**
 * وصول البطاقات: انتهاء الصلاحية الزمنية + الإبطال.
 *
 * A card can grant a fixed access window (access_days → the enrollment carries
 * expires_at, so `effective()` revokes access automatically when it lapses), and
 * a redeemed card can be revoked, which removes the access it granted. Existing
 * cards without access_days keep lifetime access — nothing changes for them.
 */
class ActivationCardAccessTest extends TenantTestCase
{
    private function makeCourse(string $tenantId): Course
    {
        return Course::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenantId,
            'title' => 'Card Course', 'slug' => 'cc-'.Str::random(6),
            'is_published' => true, 'sort_order' => 0,
        ]);
    }

    private function makeCard(string $tenantId, string $courseId, array $attrs = []): ActivationCard
    {
        return ActivationCard::create(array_merge([
            'id' => UuidV7::generate(), 'tenant_id' => $tenantId, 'course_id' => $courseId,
            'code' => strtoupper(bin2hex(random_bytes(6))), 'status' => 'generated', 'metadata' => [],
        ], $attrs));
    }

    public function test_a_card_with_access_days_grants_a_time_limited_enrollment(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');

        $course = $this->makeCourse($tenant->id);
        $card = $this->makeCard($tenant->id, $course->id, ['metadata' => ['access_days' => 30]]);

        $this->postJson('/api/v1/cards/redeem', ['code' => $card->code], $this->authHeaders($student, $tenant))
            ->assertOk();

        $enrollment = Enrollment::withoutTenant()
            ->where('tenant_id', $tenant->id)
            ->where('course_id', $course->id)
            ->where('user_id', $student->id)
            ->firstOrFail();

        $this->assertNotNull($enrollment->expires_at, 'the granted window is enforced');
        $this->assertEqualsWithDelta(now()->addDays(30)->timestamp, $enrollment->expires_at->timestamp, 5);
        $this->assertSame(30, (int) $enrollment->metadata['access_days']);
    }

    public function test_a_card_without_access_days_keeps_lifetime_access(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');

        $course = $this->makeCourse($tenant->id);
        $card = $this->makeCard($tenant->id, $course->id);

        $this->postJson('/api/v1/cards/redeem', ['code' => $card->code], $this->authHeaders($student, $tenant))
            ->assertOk();

        $enrollment = Enrollment::withoutTenant()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $student->id)
            ->firstOrFail();

        $this->assertNull($enrollment->expires_at, 'legacy cards still grant lifetime access');
    }

    public function test_an_expired_card_grant_loses_access_through_the_effective_scope(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');

        $course = $this->makeCourse($tenant->id);
        $card = $this->makeCard($tenant->id, $course->id, ['metadata' => ['access_days' => 1]]);

        $this->postJson('/api/v1/cards/redeem', ['code' => $card->code], $this->authHeaders($student, $tenant))
            ->assertOk();

        // Push the expiry into the past: the enrollment is no longer effective.
        Enrollment::withoutTenant()
            ->where('tenant_id', $tenant->id)->where('user_id', $student->id)
            ->update(['expires_at' => now()->subMinute()]);

        $this->assertSame(0, Enrollment::withoutTenant()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $student->id)
            ->effective()
            ->count());
    }

    public function test_a_teacher_can_revoke_a_redeemed_card_and_remove_access(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $owner = $this->makeUser();
        $this->attachToTenant($owner, $tenant, 'owner');
        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');

        $course = $this->makeCourse($tenant->id);
        $card = $this->makeCard($tenant->id, $course->id);

        $this->postJson('/api/v1/cards/redeem', ['code' => $card->code], $this->authHeaders($student, $tenant))
            ->assertOk();

        $enrollment = Enrollment::withoutTenant()
            ->where('tenant_id', $tenant->id)->where('user_id', $student->id)->firstOrFail();

        $this->withHeaders($this->authHeaders($owner, $tenant))
            ->postJson("/api/v1/cards/{$card->id}/revoke")
            ->assertOk()
            ->assertJsonPath('enrollment_archived', true);

        $this->assertSame('cancelled', $card->fresh()->status);
        $this->assertNotNull($card->fresh()->metadata['revoked_at']);

        // Access removed: the enrollment is archived and no longer effective.
        $this->assertSame('archived', $enrollment->fresh()->status);
        $this->assertSame(0, Enrollment::withoutTenant()
            ->where('tenant_id', $tenant->id)->where('user_id', $student->id)->effective()->count());

        // History is preserved for audit.
        $this->assertSame(1, CardRedemption::withoutTenant()->where('card_id', $card->id)->count());
    }

    public function test_revoking_a_not_yet_redeemed_card_is_rejected(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $owner = $this->makeUser();
        $this->attachToTenant($owner, $tenant, 'owner');

        $course = $this->makeCourse($tenant->id);
        $card = $this->makeCard($tenant->id, $course->id);

        $this->withHeaders($this->authHeaders($owner, $tenant))
            ->postJson("/api/v1/cards/{$card->id}/revoke")
            ->assertStatus(422);

        $this->assertSame('generated', $card->fresh()->status);
    }

    public function test_a_student_cannot_revoke_a_card(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');

        $course = $this->makeCourse($tenant->id);
        $card = $this->makeCard($tenant->id, $course->id, ['status' => 'redeemed', 'student_id' => $student->id]);

        $this->withHeaders($this->authHeaders($student, $tenant))
            ->postJson("/api/v1/cards/{$card->id}/revoke")
            ->assertStatus(403);
    }
}
