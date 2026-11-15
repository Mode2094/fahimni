<?php

namespace Tests\Feature;

use App\Jobs\ExpireActivationCardsJob;
use App\Models\ActivationCard;
use App\Models\Course;
use Illuminate\Support\Str;
use Symfony\Component\Uid\UuidV7;

/**
 * Phase 4 — the activation-card expiry sweep: issued-but-unused cards past
 * their expiry date are marked expired, while redeemed/cancelled cards are
 * never touched.
 */
class ActivationCardExpiryTest extends TenantTestCase
{
    private function makeCourse(string $tenantId): Course
    {
        return Course::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenantId,
            'title' => 'Expiry Course',
            'slug' => 'ec-'.Str::random(6),
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

    public function test_expired_unused_cards_become_expired_and_others_are_untouched(): void
    {
        $tenant = $this->makeTenant();
        $course = $this->makeCourse($tenant->id);

        $expired = $this->makeCard($tenant->id, $course->id, ['expires_at' => now()->subDay()]);
        $future = $this->makeCard($tenant->id, $course->id, ['expires_at' => now()->addWeek()]);
        $noExpiry = $this->makeCard($tenant->id, $course->id, ['expires_at' => null]);
        $redeemed = $this->makeCard($tenant->id, $course->id, [
            'status' => 'redeemed', 'expires_at' => now()->subDay(), 'used_at' => now()->subDays(2),
        ]);
        $cancelled = $this->makeCard($tenant->id, $course->id, [
            'status' => 'cancelled', 'expires_at' => now()->subDay(), 'cancelled_at' => now()->subDays(2),
        ]);

        (new ExpireActivationCardsJob)->handle();

        $this->assertSame('expired', $expired->fresh()->status);
        $this->assertSame('generated', $future->fresh()->status);
        $this->assertSame('generated', $noExpiry->fresh()->status);
        $this->assertSame('redeemed', $redeemed->fresh()->status, 'a redeemed card must keep the student’s access');
        $this->assertSame('cancelled', $cancelled->fresh()->status);
    }
}
