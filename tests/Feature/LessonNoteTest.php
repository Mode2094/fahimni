<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseGroup;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonContent;
use App\Models\LessonNote;
use App\Models\Section;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VideoContent;
use Illuminate\Support\Str;

/**
 * ملاحظات الطالب والعلامات المرجعية داخل الدروس:
 * الحفظ/التعديل/الحذف، العزل بين الطلاب، الصلاحيات (تسجيل/فتح/مجموعة)،
 * الحماية من المحتوى الضار، حدود الطول، الفيديو المحذوف/المستبدل، والبحث.
 */
class LessonNoteTest extends TenantTestCase
{
    private function makeCourseTree(Tenant $tenant, int $duration = 600, bool $published = true): array
    {
        $course = Course::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'title' => 'Algebra',
            'slug' => 'alg-'.Str::random(6),
            'is_published' => true,
        ]);

        $section = Section::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'course_id' => $course->id,
            'title' => 'S1',
            'is_published' => true,
            'sort_order' => 0,
        ]);

        $lesson = Lesson::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'section_id' => $section->id,
            'title' => 'Intro',
            'lesson_type' => 'video',
            'is_published' => $published,
            'sort_order' => 0,
        ]);

        $content = LessonContent::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'lesson_id' => $lesson->id,
            'content_type' => 'video',
            'sort_order' => 0,
        ]);

        $video = VideoContent::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'lesson_content_id' => $content->id,
            'lesson_id' => $lesson->id,
            'provider' => 'bunny',
            'provider_video_id' => 'bunny-'.Str::random(6),
            'is_uploaded' => false,
            'status' => 'ready',
            'title' => 'Protected',
            'duration' => $duration,
        ]);

        return compact('course', 'section', 'lesson', 'content', 'video');
    }

    private function enroll(Tenant $tenant, User $student, Course $course, string $status = 'active', array $extra = []): Enrollment
    {
        return Enrollment::create(array_merge([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'course_id' => $course->id,
            'user_id' => $student->id,
            'status' => $status,
            'enrolled_at' => now()->subDays(10),
            'metadata' => [],
        ], $extra));
    }

    /** @test */
    public function test_student_saves_a_note_and_it_persists_with_course_and_lesson()
    {
        $tenant = $this->makeTenant();
        ['course' => $course, 'lesson' => $lesson] = $this->makeCourseTree($tenant);
        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');
        $this->enroll($tenant, $student, $course);

        $res = $this->postJson('/api/v1/student/notes', [
            'lesson_id' => $lesson->id,
            'type' => 'note',
            'body' => 'راجع القانون قبل الاختبار',
        ], $this->authHeaders($student, $tenant));

        $res->assertCreated()
            ->assertJsonPath('note.type', 'note')
            ->assertJsonPath('note.body', 'راجع القانون قبل الاختبار')
            ->assertJsonPath('note.lesson_id', $lesson->id)
            ->assertJsonPath('note.course_id', $course->id)
            ->assertJsonPath('note.lesson_title', 'Intro');

        $this->assertDatabaseHas('lesson_notes', [
            'user_id' => $student->id,
            'lesson_id' => $lesson->id,
            'course_id' => $course->id,
            'type' => 'note',
        ]);
    }

    /** @test */
    public function test_student_saves_a_bookmark_at_a_video_timestamp_and_position_is_valid()
    {
        $tenant = $this->makeTenant();
        ['course' => $course, 'lesson' => $lesson, 'video' => $video] = $this->makeCourseTree($tenant, 600);
        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');
        $this->enroll($tenant, $student, $course);

        $res = $this->postJson('/api/v1/student/notes', [
            'lesson_id' => $lesson->id,
            'video_id' => $video->id,
            'type' => 'bookmark',
            'body' => 'المعادلة المهمة',
            'position_seconds' => 142,
            'duration_seconds' => 600,
        ], $this->authHeaders($student, $tenant));

        $res->assertCreated()
            ->assertJsonPath('note.type', 'bookmark')
            ->assertJsonPath('note.position_seconds', 142)
            ->assertJsonPath('note.has_position', true)
            ->assertJsonPath('note.position_valid', true)
            ->assertJsonPath('note.position_note', null);
    }

    /** @test */
    public function test_note_body_strips_control_characters()
    {
        $tenant = $this->makeTenant();
        ['course' => $course, 'lesson' => $lesson] = $this->makeCourseTree($tenant);
        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');
        $this->enroll($tenant, $student, $course);

        $res = $this->postJson('/api/v1/student/notes', [
            'lesson_id' => $lesson->id,
            'body' => "\x00\x07راجع القانون\x1F",
        ], $this->authHeaders($student, $tenant));

        $res->assertCreated();
        $this->assertSame('راجع القانون', $res->json('note.body'));
    }

    /** @test */
    public function test_over_long_body_is_rejected_by_validation()
    {
        $tenant = $this->makeTenant();
        ['course' => $course, 'lesson' => $lesson] = $this->makeCourseTree($tenant);
        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');
        $this->enroll($tenant, $student, $course);

        $this->postJson('/api/v1/student/notes', [
            'lesson_id' => $lesson->id,
            'body' => str_repeat('x', LessonNote::MAX_BODY_LENGTH + 1),
        ], $this->authHeaders($student, $tenant))->assertStatus(422)
            ->assertJsonValidationErrors('body');
    }

    /** @test */
    public function test_a_student_cannot_read_another_students_note()
    {
        $tenant = $this->makeTenant();
        ['course' => $course, 'lesson' => $lesson] = $this->makeCourseTree($tenant);
        $alice = $this->makeUser(['name' => 'Alice']);
        $bob = $this->makeUser(['name' => 'Bob']);
        $this->attachToTenant($alice, $tenant, 'student');
        $this->attachToTenant($bob, $tenant, 'student');
        $this->enroll($tenant, $alice, $course);
        $this->enroll($tenant, $bob, $course);

        $this->postJson('/api/v1/student/notes', [
            'lesson_id' => $lesson->id, 'body' => 'سر أليس',
        ], $this->authHeaders($alice, $tenant))->assertCreated();

        // بوب لا يرى ملاحظات أليس في قائمته إطلاقاً.
        $list = $this->getJson('/api/v1/student/notes', $this->authHeaders($bob, $tenant));
        $list->assertOk()->assertJsonPath('meta.total', 0);
        $this->assertSame([], $list->json('data'));
    }

    /** @test */
    public function test_a_student_cannot_update_or_delete_another_students_note()
    {
        $tenant = $this->makeTenant();
        ['course' => $course, 'lesson' => $lesson] = $this->makeCourseTree($tenant);
        $alice = $this->makeUser();
        $bob = $this->makeUser();
        $this->attachToTenant($alice, $tenant, 'student');
        $this->attachToTenant($bob, $tenant, 'student');
        $this->enroll($tenant, $alice, $course);
        $this->enroll($tenant, $bob, $course);

        $noteId = $this->postJson('/api/v1/student/notes', [
            'lesson_id' => $lesson->id, 'body' => 'خاص',
        ], $this->authHeaders($alice, $tenant))->json('note.id');

        // بوب لا يستطيع تعديلها أو حذفها (لا يُكشف وجودها أصلاً → 404).
        $this->putJson("/api/v1/student/notes/{$noteId}", ['body' => 'اختراق'], $this->authHeaders($bob, $tenant))
            ->assertStatus(404);
        $this->deleteJson("/api/v1/student/notes/{$noteId}", [], $this->authHeaders($bob, $tenant))
            ->assertStatus(404);

        $this->assertDatabaseHas('lesson_notes', ['id' => $noteId, 'body' => 'خاص']);
    }

    /** @test */
    public function test_student_updates_and_deletes_own_note()
    {
        $tenant = $this->makeTenant();
        ['course' => $course, 'lesson' => $lesson] = $this->makeCourseTree($tenant);
        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');
        $this->enroll($tenant, $student, $course);

        $noteId = $this->postJson('/api/v1/student/notes', [
            'lesson_id' => $lesson->id, 'body' => 'قديم',
        ], $this->authHeaders($student, $tenant))->json('note.id');

        $this->putJson("/api/v1/student/notes/{$noteId}", ['body' => 'جديد ومحدّث'], $this->authHeaders($student, $tenant))
            ->assertOk()->assertJsonPath('note.body', 'جديد ومحدّث');

        $this->assertDatabaseHas('lesson_notes', ['id' => $noteId, 'body' => 'جديد ومحدّث']);

        $this->deleteJson("/api/v1/student/notes/{$noteId}", [], $this->authHeaders($student, $tenant))
            ->assertOk()->assertJsonPath('ok', true);

        $this->assertDatabaseMissing('lesson_notes', ['id' => $noteId]);
    }

    /** @test */
    public function test_an_empty_note_is_rejected()
    {
        $tenant = $this->makeTenant();
        ['course' => $course, 'lesson' => $lesson] = $this->makeCourseTree($tenant);
        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');
        $this->enroll($tenant, $student, $course);

        $this->postJson('/api/v1/student/notes', [
            'lesson_id' => $lesson->id, 'body' => '   ',
        ], $this->authHeaders($student, $tenant))->assertStatus(422);
    }

    /** @test */
    public function test_a_non_enrolled_user_cannot_write_notes()
    {
        $tenant = $this->makeTenant();
        ['lesson' => $lesson] = $this->makeCourseTree($tenant);
        $outsider = $this->makeUser();
        $this->attachToTenant($outsider, $tenant, 'student');
        // بلا تسجيل في الكورس.

        $this->postJson('/api/v1/student/notes', [
            'lesson_id' => $lesson->id, 'body' => 'حاول',
        ], $this->authHeaders($outsider, $tenant))->assertStatus(403);
    }

    /** @test */
    public function test_an_expired_enrollment_cannot_write_notes()
    {
        $tenant = $this->makeTenant();
        ['course' => $course, 'lesson' => $lesson] = $this->makeCourseTree($tenant);
        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');
        $this->enroll($tenant, $student, $course, 'active', ['expires_at' => now()->subDay()]);

        $this->postJson('/api/v1/student/notes', [
            'lesson_id' => $lesson->id, 'body' => 'متأخر',
        ], $this->authHeaders($student, $tenant))->assertStatus(403);
    }

    /** @test */
    public function test_a_group_restricted_lesson_is_blocked_for_non_members_and_allowed_for_members()
    {
        $tenant = $this->makeTenant();
        ['course' => $course, 'lesson' => $lesson] = $this->makeCourseTree($tenant);

        $group = CourseGroup::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'course_id' => $course->id,
            'name' => 'A',
            'sort_order' => 0,
            'is_active' => true,
        ]);
        $lesson->groups()->attach($group->id);

        $member = $this->makeUser();
        $outsider = $this->makeUser();
        $this->attachToTenant($member, $tenant, 'student');
        $this->attachToTenant($outsider, $tenant, 'student');
        $this->enroll($tenant, $member, $course);
        $this->enroll($tenant, $outsider, $course);
        $group->members()->attach($member->id);

        $this->postJson('/api/v1/student/notes', [
            'lesson_id' => $lesson->id, 'body' => 'للعضو',
        ], $this->authHeaders($outsider, $tenant))->assertStatus(403);

        $this->postJson('/api/v1/student/notes', [
            'lesson_id' => $lesson->id, 'body' => 'للعضو',
        ], $this->authHeaders($member, $tenant))->assertCreated();
    }

    /** @test */
    public function test_notes_on_a_soft_deleted_lesson_are_not_writable()
    {
        $tenant = $this->makeTenant();
        ['course' => $course, 'lesson' => $lesson] = $this->makeCourseTree($tenant);
        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');
        $this->enroll($tenant, $student, $course);

        $lesson->delete(); // حذف ناعم

        $this->postJson('/api/v1/student/notes', [
            'lesson_id' => $lesson->id, 'body' => 'x',
        ], $this->authHeaders($student, $tenant))->assertStatus(404);
    }

    /** @test */
    public function test_an_unauthenticated_request_is_rejected()
    {
        $this->getJson('/api/v1/student/notes')->assertStatus(401);
        $this->postJson('/api/v1/student/notes', ['lesson_id' => 'x'])->assertStatus(401);
    }

    /** @test */
    public function test_a_video_from_another_lesson_is_rejected()
    {
        $tenant = $this->makeTenant();
        ['course' => $course, 'lesson' => $lesson] = $this->makeCourseTree($tenant);
        ['video' => $otherVideo] = $this->makeCourseTree($tenant, 300);
        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');
        $this->enroll($tenant, $student, $course);

        $this->postJson('/api/v1/student/notes', [
            'lesson_id' => $lesson->id,
            'video_id' => $otherVideo->id,
            'type' => 'bookmark',
            'position_seconds' => 10,
        ], $this->authHeaders($student, $tenant))->assertStatus(422);
    }

    /** @test */
    public function test_a_bookmark_reports_a_changed_video_timestamp_as_invalid()
    {
        $tenant = $this->makeTenant();
        ['course' => $course, 'lesson' => $lesson, 'video' => $video] = $this->makeCourseTree($tenant, 600);
        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');
        $this->enroll($tenant, $student, $course);

        $this->postJson('/api/v1/student/notes', [
            'lesson_id' => $lesson->id,
            'video_id' => $video->id,
            'type' => 'bookmark',
            'position_seconds' => 100,
            'duration_seconds' => 600,
        ], $this->authHeaders($student, $tenant))->assertCreated();

        // الفيديو استُبدل بمدة مختلفة تماماً → المرجع الزمني لم يعد صالحاً.
        $video->duration = 1800;
        $video->save();

        $this->getJson('/api/v1/student/notes?lesson_id='.$lesson->id, $this->authHeaders($student, $tenant))
            ->assertOk()
            ->assertJsonPath('data.0.position_valid', false)
            ->assertJsonPath('data.0.position_note', 'video_changed');
    }

    /** @test */
    public function test_a_bookmark_whose_video_was_deleted_is_flagged_but_kept()
    {
        $tenant = $this->makeTenant();
        ['course' => $course, 'lesson' => $lesson, 'video' => $video] = $this->makeCourseTree($tenant, 600);
        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');
        $this->enroll($tenant, $student, $course);

        $noteId = $this->postJson('/api/v1/student/notes', [
            'lesson_id' => $lesson->id,
            'video_id' => $video->id,
            'type' => 'bookmark',
            'position_seconds' => 30,
            'duration_seconds' => 600,
        ], $this->authHeaders($student, $tenant))->json('note.id');

        // حذف الفيديو نهائياً: الملاحظة تبقى (لا تُحذف)، ويُعلَم أنها بلا مرجع.
        LessonContent::withoutTenant()->where('id', $video->lesson_content_id)->delete();
        VideoContent::withoutTenant()->where('id', $video->id)->delete();

        $this->assertDatabaseHas('lesson_notes', ['id' => $noteId]);

        $this->getJson('/api/v1/student/notes?lesson_id='.$lesson->id, $this->authHeaders($student, $tenant))
            ->assertOk()
            ->assertJsonPath('data.0.position_valid', false)
            ->assertJsonPath('data.0.position_note', 'video_missing');
    }

    /** @test */
    public function test_notes_can_be_filtered_searched_and_paginated()
    {
        $tenant = $this->makeTenant();
        ['course' => $course, 'lesson' => $lesson] = $this->makeCourseTree($tenant);
        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');
        $this->enroll($tenant, $student, $course);

        foreach (['ألف قانون', 'باء قاعدة', 'جيم قانون ثانٍ'] as $body) {
            $this->postJson('/api/v1/student/notes', [
                'lesson_id' => $lesson->id, 'body' => $body,
            ], $this->authHeaders($student, $tenant))->assertCreated();
        }

        // بحث بسيط في النص.
        $this->getJson('/api/v1/student/notes?q='.urlencode('قانون'), $this->authHeaders($student, $tenant))
            ->assertOk()->assertJsonPath('meta.total', 2);

        // تصفية بالدرس.
        $this->getJson('/api/v1/student/notes?lesson_id='.$lesson->id, $this->authHeaders($student, $tenant))
            ->assertOk()->assertJsonPath('meta.total', 3);

        // ترقيم.
        $this->getJson('/api/v1/student/notes?per_page=2&page=1', $this->authHeaders($student, $tenant))
            ->assertOk()->assertJsonPath('meta.last_page', 2)->assertJsonCount(2, 'data');
    }

    /** @test */
    public function test_summary_groups_notes_by_course_then_lesson()
    {
        $tenant = $this->makeTenant();
        ['course' => $course, 'lesson' => $lesson] = $this->makeCourseTree($tenant);
        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');
        $this->enroll($tenant, $student, $course);

        $this->postJson('/api/v1/student/notes', [
            'lesson_id' => $lesson->id, 'body' => 'a',
        ], $this->authHeaders($student, $tenant))->assertCreated();
        $this->postJson('/api/v1/student/notes', [
            'lesson_id' => $lesson->id, 'body' => 'b',
        ], $this->authHeaders($student, $tenant))->assertCreated();

        $this->getJson('/api/v1/student/notes/summary', $this->authHeaders($student, $tenant))
            ->assertOk()
            ->assertJsonPath('courses.0.course_id', $course->id)
            ->assertJsonPath('courses.0.notes_count', 2)
            ->assertJsonPath('courses.0.lessons.0.lesson_id', $lesson->id)
            ->assertJsonPath('courses.0.lessons.0.notes_count', 2);
    }

    /** @test */
    public function test_a_note_saved_on_one_device_is_available_on_another()
    {
        $tenant = $this->makeTenant();
        ['course' => $course, 'lesson' => $lesson] = $this->makeCourseTree($tenant);
        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');
        $this->enroll($tenant, $student, $course);

        // الجهاز الأول يكتب الملاحظة.
        $this->postJson('/api/v1/student/notes', [
            'lesson_id' => $lesson->id, 'body' => 'دوّنتها من الجوال',
        ], array_merge($this->authHeaders($student, $tenant), ['X-Device-Id' => 'device-one']))->assertCreated();

        // الجهاز الثاني (معرّف مختلف تماماً) يرى نفس الملاحظة: التخزين على الخادم
        // لا على الجهاز، فالملاحظة مرتبطة بحساب الطالب لا بجهازه.
        $list = $this->getJson(
            '/api/v1/student/notes?lesson_id='.$lesson->id,
            array_merge($this->authHeaders($student, $tenant), ['X-Device-Id' => 'device-two']),
        );

        $list->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.body', 'دوّنتها من الجوال')
            ->assertJsonPath('data.0.lesson_id', $lesson->id);
    }

    /** @test */
    public function test_malicious_html_in_a_note_is_stored_as_inert_text()
    {
        $tenant = $this->makeTenant();
        ['course' => $course, 'lesson' => $lesson] = $this->makeCourseTree($tenant);
        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');
        $this->enroll($tenant, $student, $course);

        $payload = '<script>alert(document.cookie)</script><img src=x onerror=steal()>';
        $res = $this->postJson('/api/v1/student/notes', [
            'lesson_id' => $lesson->id, 'body' => $payload,
        ], $this->authHeaders($student, $tenant))->assertCreated();

        // يُخزَّن ويُعاد كنصّ خام كما هو — لا تنفيذ ولا HTML فعّال. الطبقة الآمنة
        // هي إخراج React الذي يُهرّب النص تلقائياً (لا dangerouslySetInnerHTML).
        $this->assertSame($payload, $res->json('note.body'));
        $this->assertDatabaseHas('lesson_notes', ['body' => $payload]);

        $list = $this->getJson('/api/v1/student/notes?lesson_id='.$lesson->id, $this->authHeaders($student, $tenant));
        $list->assertOk()->assertJsonPath('data.0.body', $payload);
    }

    /** @test */
    public function test_notes_are_isolated_between_tenants()
    {
        $tenantA = $this->makeTenant();
        ['lesson' => $lessonA] = $this->makeCourseTree($tenantA);

        $tenantB = $this->makeTenant();
        $studentB = $this->makeUser();
        $this->attachToTenant($studentB, $tenantB, 'student');

        // طالب من مؤسسة أخرى لا يملك وصولاً لدرس المؤسسة الأولى.
        $this->postJson('/api/v1/student/notes', [
            'lesson_id' => $lessonA->id, 'body' => 'تسلل',
        ], $this->authHeaders($studentB, $tenantB))->assertStatus(403);
    }
}
