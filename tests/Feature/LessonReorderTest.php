<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\Section;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Str;
use Symfony\Component\Uid\UuidV7;

class LessonReorderTest extends TenantTestCase
{
    private function sectionWithLessons(Tenant $tenant, User $teacher, int $count = 3): array
    {
        $course = Course::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenant->id,
            'teacher_id' => $teacher->id,
            'title' => 'Reorder course',
            'slug' => 'reorder-'.Str::random(8),
            'is_published' => true,
            'sort_order' => 0,
        ]);
        $section = Section::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenant->id,
            'course_id' => $course->id,
            'title' => 'Reorder section',
            'sort_order' => 1,
            'is_published' => true,
        ]);

        $lessons = collect(range(1, $count))->map(fn ($order) => Lesson::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenant->id,
            'section_id' => $section->id,
            'title' => 'Lesson '.$order,
            'sort_order' => $order,
            'is_published' => true,
            'is_free_preview' => false,
            'lesson_type' => 'video',
        ]));

        return [$course, $section, $lessons];
    }

    public function test_reorder_saves_the_complete_order_and_reads_it_back(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');
        [, $section, $lessons] = $this->sectionWithLessons($tenant, $teacher);
        $orderedIds = [$lessons[2]->id, $lessons[0]->id, $lessons[1]->id];

        $this->postJson('/api/v1/lessons/reorder', [
            'section_id' => $section->id,
            'lesson_ids' => $orderedIds,
            'expected_lesson_ids' => [$lessons[0]->id, $lessons[1]->id, $lessons[2]->id],
        ], $this->authHeaders($teacher, $tenant))->assertOk();

        $response = $this->getJson('/api/v1/lessons?section_id='.$section->id, $this->authHeaders($teacher, $tenant));
        $response->assertOk();
        $this->assertSame($orderedIds, collect($response->json('lessons'))->pluck('id')->all());
        foreach ($orderedIds as $index => $lessonId) {
            $this->assertDatabaseHas('lessons', ['id' => $lessonId, 'sort_order' => $index + 1]);
        }
    }

    public function test_stale_partial_order_is_rejected_without_changing_database_order(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');
        [, $section, $lessons] = $this->sectionWithLessons($tenant, $teacher);

        $this->postJson('/api/v1/lessons/reorder', [
            'section_id' => $section->id,
            'lesson_ids' => [$lessons[1]->id],
        ], $this->authHeaders($teacher, $tenant))
            ->assertStatus(409);

        foreach ($lessons as $index => $lesson) {
            $this->assertDatabaseHas('lessons', ['id' => $lesson->id, 'sort_order' => $index + 1]);
        }
    }

    public function test_stale_reorder_cannot_overwrite_a_newer_order(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');
        [, $section, $lessons] = $this->sectionWithLessons($tenant, $teacher);
        $initialOrder = [$lessons[0]->id, $lessons[1]->id, $lessons[2]->id];
        $savedOrder = [$lessons[2]->id, $lessons[1]->id, $lessons[0]->id];
        $headers = $this->authHeaders($teacher, $tenant);

        $this->postJson('/api/v1/lessons/reorder', [
            'section_id' => $section->id,
            'lesson_ids' => $savedOrder,
            'expected_lesson_ids' => $initialOrder,
        ], $headers)->assertOk();

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/lessons/reorder', [
            'section_id' => $section->id,
            'lesson_ids' => [$lessons[1]->id, $lessons[0]->id, $lessons[2]->id],
            'expected_lesson_ids' => $initialOrder,
        ], $headers)->assertStatus(409);

        $response = $this->getJson('/api/v1/lessons?section_id='.$section->id, $headers);
        $this->assertSame($savedOrder, collect($response->json('lessons'))->pluck('id')->all());
    }
}
