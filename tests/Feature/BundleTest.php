<?php

namespace Tests\Feature;

use App\Models\Bundle;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Tenant;
use Illuminate\Support\Str;
use Symfony\Component\Uid\UuidV7;

/**
 * Course bundles — a package of existing courses.
 *
 * Guarantees: only tenant staff may manage a bundle, courses from another
 * academy can never be attached, students only see published bundles, and
 * enrolling a student in a bundle fans out to real enrollments without ever
 * hinting at fabricated payments.
 */
class BundleTest extends TenantTestCase
{
    private function makeCourse(Tenant $tenant, array $attrs = []): Course
    {
        return Course::create(array_merge([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenant->id,
            'title' => 'Course '.Str::random(5),
            'slug' => 'c-'.Str::random(8),
            'is_published' => true,
            'price' => 30,
            'sort_order' => 0,
        ], $attrs));
    }

    /** @test */
    public function test_teacher_can_create_a_bundle_with_courses(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');

        $c1 = $this->makeCourse($tenant);
        $c2 = $this->makeCourse($tenant);

        $res = $this->postJson('/api/v1/bundles', [
            'title' => 'حزمة الرياضيات',
            'price' => 79,
            'is_published' => true,
            'course_ids' => [$c1->id, $c2->id],
        ], $this->authHeaders($teacher, $tenant));

        $res->assertStatus(201);
        $this->assertSame(2, $res->json('bundle.courses_count'));

        $bundle = Bundle::withoutTenant()->findOrFail($res->json('bundle.id'));
        $this->assertSame($tenant->id, $bundle->tenant_id);
        $this->assertEqualsCanonicalizing([$c1->id, $c2->id], $bundle->courses()->pluck('courses.id')->all());
    }

    /** @test */
    public function test_student_cannot_create_a_bundle(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');

        $this->postJson('/api/v1/bundles', ['title' => 'X'], $this->authHeaders($student, $tenant))
            ->assertStatus(403);
    }

    /** @test */
    public function test_a_course_from_another_tenant_cannot_be_attached(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $this->attachSubscription($tenantA);
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenantA, 'teacher');

        $foreign = $this->makeCourse($tenantB);

        $this->postJson('/api/v1/bundles', [
            'title' => 'Cross tenant',
            'course_ids' => [$foreign->id],
        ], $this->authHeaders($teacher, $tenantA))->assertStatus(422);
    }

    /** @test */
    public function test_student_index_only_shows_published_bundles(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');

        Bundle::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id,
            'title' => 'Published', 'slug' => 'pub', 'is_published' => true,
        ]);
        Bundle::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id,
            'title' => 'Draft', 'slug' => 'draft', 'is_published' => false,
        ]);

        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');

        $res = $this->getJson('/api/v1/bundles', $this->authHeaders($student, $tenant));
        $res->assertOk();
        $titles = collect($res->json('bundles'))->pluck('title')->all();
        $this->assertContains('Published', $titles);
        $this->assertNotContains('Draft', $titles);
    }

    /** @test */
    public function test_enrolling_a_student_in_a_bundle_enrolls_every_course(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');

        $c1 = $this->makeCourse($tenant);
        $c2 = $this->makeCourse($tenant);

        $bundle = Bundle::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id,
            'title' => 'Bundle', 'slug' => 'bundle', 'is_published' => true,
        ]);
        $bundle->courses()->sync([$c1->id, $c2->id]);

        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');

        $res = $this->postJson("/api/v1/bundles/{$bundle->id}/enroll", [
            'user_id' => $student->id,
        ], $this->authHeaders($teacher, $tenant));

        $res->assertStatus(201);
        $this->assertCount(2, $res->json('enrolled'));

        $this->assertSame(2, Enrollment::where('user_id', $student->id)->count());
        $this->assertTrue(Enrollment::where('user_id', $student->id)->where('course_id', $c1->id)->exists());
        $this->assertTrue(Enrollment::where('user_id', $student->id)->where('course_id', $c2->id)->exists());
    }

    /** @test */
    public function test_deleting_a_bundle_never_deletes_its_courses(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');

        $course = $this->makeCourse($tenant);
        $bundle = Bundle::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id,
            'title' => 'Temp', 'slug' => 'temp', 'is_published' => true,
        ]);
        $bundle->courses()->sync([$course->id]);

        $this->deleteJson("/api/v1/bundles/{$bundle->id}", [], $this->authHeaders($teacher, $tenant))
            ->assertOk();

        $this->assertNull(Bundle::withoutTenant()->find($bundle->id));
        $this->assertNotNull(Course::withoutTenant()->find($course->id));
    }

    /** @test */
    public function test_public_bundle_listing_only_exposes_published_courses(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);

        $published = $this->makeCourse($tenant, ['is_published' => true]);
        $draft = $this->makeCourse($tenant, ['is_published' => false]);

        $bundle = Bundle::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id,
            'title' => 'Public Bundle', 'slug' => 'public-bundle',
            'is_published' => true, 'price' => 99,
        ]);
        $bundle->courses()->sync([$published->id, $draft->id]);

        $res = $this->getJson("/api/v1/public/{$tenant->slug}/bundles");

        $res->assertOk();
        $this->assertCount(1, $res->json('bundles'));
        $this->assertSame(1, $res->json('bundles.0.courses_count'));
        $this->assertSame($published->id, $res->json('bundles.0.courses.0.id'));
    }
}
