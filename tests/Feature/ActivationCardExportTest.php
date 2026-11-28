<?php

namespace Tests\Feature;

use App\Models\ActivationCard;
use App\Models\Course;
use Illuminate\Support\Str;
use Symfony\Component\Uid\UuidV7;

/**
 * Phase 4 — activation-card CSV export (the import path existed, export did
 * not). Teachers can export their cards; students cannot.
 */
class ActivationCardExportTest extends TenantTestCase
{
    private function makeCourse(string $tenantId): Course
    {
        return Course::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenantId,
            'title' => 'Export Course',
            'slug' => 'xc-'.Str::random(6),
            'is_published' => true,
            'sort_order' => 0,
        ]);
    }

    private function makeCard(string $tenantId, string $courseId, string $code, array $attrs = []): ActivationCard
    {
        return ActivationCard::create(array_merge([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenantId,
            'course_id' => $courseId,
            'code' => $code,
            'status' => 'generated',
            'batch_id' => 'batch-1',
            'metadata' => [],
        ], $attrs));
    }

    public function test_a_teacher_can_export_cards_as_csv(): void
    {
        $tenant = $this->makeTenant();
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');
        $this->attachSubscription($tenant);

        $course = $this->makeCourse($tenant->id);
        $this->makeCard($tenant->id, $course->id, 'EXPORTAAA111');
        $this->makeCard($tenant->id, $course->id, 'EXPORTBBB222', ['status' => 'redeemed', 'used_at' => now()]);

        $response = $this->get('/api/v1/cards/export', $this->authHeaders($teacher, $tenant));

        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));

        $csv = $response->streamedContent();
        $this->assertStringContainsString('code,status,course', $csv);
        $this->assertStringContainsString('EXPORTAAA111', $csv);
        $this->assertStringContainsString('EXPORTBBB222', $csv);
    }

    public function test_the_export_honours_a_status_filter(): void
    {
        $tenant = $this->makeTenant();
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');
        $this->attachSubscription($tenant);

        $course = $this->makeCourse($tenant->id);
        $this->makeCard($tenant->id, $course->id, 'KEEPONLYAAA1');
        $this->makeCard($tenant->id, $course->id, 'SKIPMEBBB222', ['status' => 'cancelled', 'cancelled_at' => now()]);

        $csv = $this->get('/api/v1/cards/export?status=generated', $this->authHeaders($teacher, $tenant))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('KEEPONLYAAA1', $csv);
        $this->assertStringNotContainsString('SKIPMEBBB222', $csv);
    }

    public function test_a_student_cannot_export_cards(): void
    {
        $tenant = $this->makeTenant();
        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');

        $this->get('/api/v1/cards/export', $this->authHeaders($student, $tenant))->assertStatus(403);
    }
}
