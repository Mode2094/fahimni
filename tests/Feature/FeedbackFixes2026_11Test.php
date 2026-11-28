<?php

namespace Tests\Feature;

use App\Media\MediaReconciliationService;
use App\Media\MediaService;
use App\Media\MediaUsageService;
use App\Models\Course;
use App\Models\CourseGroup;
use App\Models\Enrollment;
use App\Models\Exam;
use App\Models\Lesson;
use App\Models\LessonContent;
use App\Models\MediaAsset;
use App\Models\MediaOrphanAsset;
use App\Models\MediaProvider;
use App\Models\Section;
use App\Models\SubscriptionPlan;
use App\Models\VideoContent;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\Uid\UuidV7;

/**
 * Regression coverage for the 2026-11 customer-feedback release.
 *
 * Each test locks in one root-cause fix so it cannot silently return:
 *   - Area 5: a lesson/exam shared to a course group stays invisible on every
 *     surface a student can reach (Q&A, progress completion, search).
 *   - Area 3: exam ordering is per section, reorder is optimistic and
 *     conflicts are reported instead of being silently dropped.
 *   - Area 4: a cancelled live session records *when* it was cancelled.
 *   - Area 7: a library video can be reused in another lesson without a second
 *     provider copy and without double counting storage.
 *   - Area 10: the media reconcile job quarantines owner-less provider videos
 *     instead of inserting them with a fake (zero) tenant.
 *   - Area 9: API validation errors are Arabic.
 */
class FeedbackFixes2026_11Test extends TenantTestCase
{
    private function makeCourse(string $tenantId, string $teacherId, string $title = 'Course'): Course
    {
        return Course::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenantId, 'teacher_id' => $teacherId,
            'title' => $title, 'slug' => 'course-'.Str::random(6),
            'description' => '', 'is_published' => true, 'sort_order' => 0,
        ]);
    }

    private function makeSection(Course $course, string $title = 'Section'): Section
    {
        return Section::create([
            'id' => UuidV7::generate(), 'course_id' => $course->id, 'tenant_id' => $course->tenant_id,
            'title' => $title, 'sort_order' => 0, 'is_published' => true,
        ]);
    }

    private function makeLesson(Course $course, Section $section, string $title = 'Lesson'): Lesson
    {
        return Lesson::create([
            'id' => UuidV7::generate(), 'section_id' => $section->id, 'tenant_id' => $course->tenant_id,
            'title' => $title, 'lesson_type' => 'text', 'is_published' => true,
            'sort_order' => 0, 'is_free_preview' => false,
        ]);
    }

    private function enrol(\App\Models\User $student, Course $course): Enrollment
    {
        return Enrollment::create([
            'id' => UuidV7::generate(), 'tenant_id' => $course->tenant_id, 'course_id' => $course->id,
            'user_id' => $student->id, 'status' => 'active', 'enrolled_at' => now(),
        ]);
    }

    // ─── Area 5: group visibility on Q&A ─────────────────────────────

    /** @test */
    public function test_lesson_qa_is_refused_for_a_non_member_of_a_restricted_lesson(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');
        $course = $this->makeCourse($tenant->id, $teacher->id);
        $section = $this->makeSection($course);
        $shared = $this->makeLesson($course, $section, 'Group only');

        $member = $this->makeUser();
        $this->attachToTenant($member, $tenant, 'student');
        $outsider = $this->makeUser();
        $this->attachToTenant($outsider, $tenant, 'student');
        $this->enrol($member, $course);
        $this->enrol($outsider, $course);

        $group = CourseGroup::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id, 'course_id' => $course->id,
            'name' => 'Group A', 'sort_order' => 0, 'is_active' => true,
        ]);
        $group->lessons()->sync([$shared->id]);
        $group->members()->sync([$member->id]);

        $this->withHeaders($this->authHeaders($member, $tenant))
            ->getJson("/api/v1/lessons/{$shared->id}/questions")
            ->assertOk();

        $this->withHeaders($this->authHeaders($outsider, $tenant))
            ->getJson("/api/v1/lessons/{$shared->id}/questions")
            ->assertStatus(403);
    }

    // ─── Area 5: group visibility on progress completion ─────────────

    /** @test */
    public function test_a_non_member_cannot_complete_a_restricted_lesson(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');
        $course = $this->makeCourse($tenant->id, $teacher->id);
        $section = $this->makeSection($course);
        $shared = $this->makeLesson($course, $section, 'Group only');

        $member = $this->makeUser();
        $this->attachToTenant($member, $tenant, 'student');
        $outsider = $this->makeUser();
        $this->attachToTenant($outsider, $tenant, 'student');
        $memberEnrollment = $this->enrol($member, $course);
        $outsiderEnrollment = $this->enrol($outsider, $course);

        $group = CourseGroup::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id, 'course_id' => $course->id,
            'name' => 'Group A', 'sort_order' => 0, 'is_active' => true,
        ]);
        $group->lessons()->sync([$shared->id]);
        $group->members()->sync([$member->id]);

        $this->withHeaders($this->authHeaders($member, $tenant))
            ->postJson("/api/v1/enrollments/{$memberEnrollment->id}/progress/{$shared->id}/complete")
            ->assertOk();

        $this->withHeaders($this->authHeaders($outsider, $tenant))
            ->postJson("/api/v1/enrollments/{$outsiderEnrollment->id}/progress/{$shared->id}/complete")
            ->assertStatus(403);
    }

    // ─── Area 5: group visibility in search ──────────────────────────

    /** @test */
    public function test_search_never_surfaces_a_group_restricted_lesson_to_a_non_member(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');
        $course = $this->makeCourse($tenant->id, $teacher->id);
        $section = $this->makeSection($course);
        $shared = $this->makeLesson($course, $section, 'Photosynthesis secrets');
        $open = $this->makeLesson($course, $section, 'Photosynthesis basics');

        $member = $this->makeUser();
        $this->attachToTenant($member, $tenant, 'student');
        $outsider = $this->makeUser();
        $this->attachToTenant($outsider, $tenant, 'student');
        $this->enrol($member, $course);
        $this->enrol($outsider, $course);

        $group = CourseGroup::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id, 'course_id' => $course->id,
            'name' => 'Group A', 'sort_order' => 0, 'is_active' => true,
        ]);
        $group->lessons()->sync([$shared->id]);
        $group->members()->sync([$member->id]);

        $memberLessons = collect(
            $this->withHeaders($this->authHeaders($member, $tenant))
                ->getJson('/api/v1/search?q=Photosynthesis')->json('results.lessons')
        )->pluck('id')->all();
        $this->assertContains($shared->id, $memberLessons);
        $this->assertContains($open->id, $memberLessons);

        $outsiderLessons = collect(
            $this->withHeaders($this->authHeaders($outsider, $tenant))
                ->getJson('/api/v1/search?q=Photosynthesis')->json('results.lessons')
        )->pluck('id')->all();
        $this->assertNotContains($shared->id, $outsiderLessons, 'a group lesson must never leak into search');
        $this->assertContains($open->id, $outsiderLessons);
    }

    // ─── Area 3: exam ordering is per section ────────────────────────

    /** @test */
    public function test_exam_reorder_is_scoped_to_one_section_and_reports_conflicts(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');
        $course = $this->makeCourse($tenant->id, $teacher->id);
        $sectionA = $this->makeSection($course, 'A');
        $sectionB = $this->makeSection($course, 'B');

        $a1 = $this->makeExam($course, $sectionA, 'A1', 0);
        $a2 = $this->makeExam($course, $sectionA, 'A2', 0);
        $a3 = $this->makeExam($course, $sectionA, 'A3', 0);
        $b1 = $this->makeExam($course, $sectionB, 'B1', 0);

        $headers = $this->authHeaders($teacher, $tenant);

        $this->withHeaders($headers)->postJson('/api/v1/exams/reorder', [
            'course_id' => $course->id,
            'section_id' => $sectionA->id,
            'exam_ids' => [$a3->id, $a1->id, $a2->id],
            'expected_exam_ids' => [$a1->id, $a2->id, $a3->id],
        ])->assertOk();

        // Per-section sequential renumbering.
        $this->assertSame(1, $a3->fresh()->sort_order);
        $this->assertSame(2, $a1->fresh()->sort_order);
        $this->assertSame(3, $a2->fresh()->sort_order);

        // The other section is untouched (this was the original bug: a
        // course-wide rewrite renumbered every exam in the course).
        $this->assertSame(0, $b1->fresh()->sort_order);

        // A stale expected list is a conflict, never a silent overwrite.
        $this->withHeaders($headers)->postJson('/api/v1/exams/reorder', [
            'course_id' => $course->id,
            'section_id' => $sectionA->id,
            'exam_ids' => [$a1->id, $a2->id, $a3->id],
            'expected_exam_ids' => [$a1->id, $a2->id, $b1->id],
        ])->assertStatus(409)->assertJsonPath('code', 'exam_order_conflict');
    }

    /** @test */
    public function test_creating_an_exam_auto_numbers_it_within_its_section(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');
        $course = $this->makeCourse($tenant->id, $teacher->id);
        $section = $this->makeSection($course);

        $this->makeExam($course, $section, 'One', 1);
        $this->makeExam($course, $section, 'Two', 2);

        $res = $this->withHeaders($this->authHeaders($teacher, $tenant))
            ->postJson('/api/v1/exams', [
                'course_id' => $course->id,
                'section_id' => $section->id,
                'title' => 'Three',
                'pass_mark_percent' => 50,
                'max_attempts' => 1,
                'duration_minutes' => 20,
            ])
            ->assertStatus(201);

        $created = Exam::findOrFail($res->json('exam.id'));
        $this->assertSame(3, $created->sort_order);
        $this->assertSame($section->id, $created->section_id);
    }

    private function makeExam(Course $course, Section $section, string $title, int $sort): Exam
    {
        return Exam::create([
            'id' => UuidV7::generate(), 'tenant_id' => $course->tenant_id, 'course_id' => $course->id,
            'section_id' => $section->id, 'title' => $title, 'pass_mark_percent' => 50,
            'max_attempts' => 3, 'is_published' => true, 'duration_minutes' => 20,
            'sort_order' => $sort, 'metadata' => [],
        ]);
    }

    // ─── Area 4: cancelled live sessions record the time ─────────────

    /** @test */
    public function test_cancelling_a_live_session_persists_when_it_was_cancelled(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');
        $course = $this->makeCourse($tenant->id, $teacher->id);

        $session = \App\Models\LiveSession::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id, 'user_id' => $teacher->id,
            'course_id' => $course->id, 'title' => 'Live', 'provider' => 'google_meet',
            'room_name' => 'room-'.Str::random(8), 'status' => 'scheduled',
            'max_participants' => 100, 'record_enabled' => false, 'config' => [],
            'metadata' => ['meeting_url' => 'https://meet.google.com/abc-defg-hij'],
        ]);

        $this->assertNull($session->fresh()->cancelled_at);

        $this->withHeaders($this->authHeaders($teacher, $tenant))
            ->postJson("/api/v1/teacher/live-sessions/{$session->id}/cancel", [])
            ->assertOk()
            ->assertJsonPath('session.status', 'cancelled');

        // Previously written but silently dropped (no column, not fillable).
        $this->assertNotNull($session->fresh()->cancelled_at);
    }

    /** @test */
    public function test_a_cancelled_live_session_never_exposes_its_join_link_to_a_student(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');
        $course = $this->makeCourse($tenant->id, $teacher->id);
        $section = $this->makeSection($course);
        $lesson = $this->makeLesson($course, $section, 'Live lesson');

        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');
        $this->enrol($student, $course);

        $content = LessonContent::withoutTenant()->create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id, 'lesson_id' => $lesson->id,
            'content_type' => 'live', 'sort_order' => 0,
        ]);
        $live = \App\Models\LiveSession::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id, 'user_id' => $teacher->id,
            'lesson_content_id' => $content->id, 'course_id' => $course->id,
            'title' => 'Weekly live', 'provider' => 'google_meet',
            'room_name' => 'room-'.Str::random(8), 'status' => 'scheduled',
            'max_participants' => 100, 'record_enabled' => false, 'config' => [],
            'metadata' => ['meeting_url' => 'https://meet.google.com/abc-defg-hij'],
        ]);

        // While scheduled the student may join.
        $this->withHeaders($this->authHeaders($student, $tenant))
            ->getJson("/api/v1/public/courses/{$course->id}/lessons/{$lesson->id}")
            ->assertOk()
            ->assertJsonPath('contents.0.live.status', 'scheduled')
            ->assertJsonPath('contents.0.live.meeting_url', 'https://meet.google.com/abc-defg-hij');

        // After cancellation the join link must disappear — the session is dead.
        $live->update(['status' => 'cancelled', 'cancelled_at' => now()]);

        $this->withHeaders($this->authHeaders($student, $tenant))
            ->getJson("/api/v1/public/courses/{$course->id}/lessons/{$lesson->id}")
            ->assertOk()
            ->assertJsonPath('contents.0.live.status', 'cancelled')
            ->assertJsonPath('contents.0.live.meeting_url', null);
    }

    // ─── Area 4: canonical plans carry the live quota keys ───────────

    /**
     * The canonical seeder used to omit `live_minutes_limit` / `recordings_limit`,
     * so a fresh install's plans never closed the quota seam (and the admin plan
     * editor showed the keys as unset). Lock the canonical values in.
     *
     * @test
     */
    public function test_canonical_plans_define_live_minutes_and_recordings_limits(): void
    {
        $this->seed(\Database\Seeders\SubscriptionPlanSeeder::class);

        $professional = SubscriptionPlan::where('slug', 'professional')->firstOrFail();
        $this->assertSame('3000', (string) $professional->featureValue('live_minutes_limit'));
        $this->assertSame('100', (string) $professional->featureValue('recordings_limit'));

        $enterprise = SubscriptionPlan::where('slug', 'enterprise')->firstOrFail();
        $this->assertSame('10000', (string) $enterprise->featureValue('live_minutes_limit'));
        $this->assertSame('unlimited', (string) $enterprise->featureValue('recordings_limit'));

        $starter = SubscriptionPlan::where('slug', 'starter')->firstOrFail();
        $this->assertSame('0', (string) $starter->featureValue('live_minutes_limit'));
    }

    // ─── Area 7: reuse a library video without a second copy ─────────

    /** @test */
    public function test_an_existing_video_can_be_linked_to_another_lesson_without_storing_it_twice(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');
        $course = $this->makeCourse($tenant->id, $teacher->id);
        $section = $this->makeSection($course);
        $sourceLesson = $this->makeLesson($course, $section, 'Source');
        $targetLesson = $this->makeLesson($course, $section, 'Target');

        // Model the asset + its existing provider video row.
        $asset = MediaAsset::withoutTenant()->create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id, 'user_id' => $teacher->id,
            'provider' => 'bunny', 'provider_asset_id' => 'vid-shared-1', 'kind' => 'video',
            'status' => MediaAsset::STATUS_READY, 'title' => 'Shared video',
            'size_bytes' => 1048576, 'storage_bytes' => 1048576, 'metadata' => [],
        ]);
        $sourceContent = LessonContent::withoutTenant()->create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id, 'lesson_id' => $sourceLesson->id,
            'content_type' => 'video', 'sort_order' => 0,
        ]);
        VideoContent::withoutTenant()->create([
            'id' => UuidV7::generate(), 'lesson_content_id' => $sourceContent->id,
            'lesson_id' => $sourceLesson->id, 'tenant_id' => $tenant->id,
            'provider' => 'bunny', 'provider_video_id' => 'vid-shared-1', 'title' => 'Shared video',
            'duration' => 120, 'status' => 'ready', 'file_size_bytes' => 1048576, 'is_uploaded' => true,
        ]);

        $usage = app(MediaUsageService::class)->current($tenant->id);

        $this->withHeaders($this->authHeaders($teacher, $tenant))
            ->postJson("/api/v1/teacher/media/assets/{$asset->id}/attach", ['lesson_id' => $targetLesson->id])
            ->assertStatus(201)
            ->assertJsonPath('video.reused', true);

        $reused = VideoContent::withoutTenant()
            ->where('lesson_id', $targetLesson->id)
            ->where('provider_video_id', 'vid-shared-1')
            ->first();

        $this->assertNotNull($reused, 'the target lesson must play the same provider video');
        $this->assertTrue($reused->is_shared_reference);

        // Same provider object referenced twice — no second asset, no extra
        // storage: the count is unchanged.
        $this->assertSame(1, MediaAsset::withoutTenant()
            ->where('tenant_id', $tenant->id)->where('provider_asset_id', 'vid-shared-1')->count());
        $after = app(MediaUsageService::class)->current($tenant->id);
        $this->assertSame($usage['storage_bytes'], $after['storage_bytes']);
        $this->assertSame($usage['video_count'], $after['video_count']);
        $this->assertSame(2, app(MediaService::class)->referenceCount($asset->fresh()));
    }

    // ─── Area 10: reconcile quarantines owner-less provider videos ───

    /** @test */
    public function test_reconcile_quarantines_unknown_videos_without_a_fake_tenant(): void
    {
        MediaProvider::where('provider', 'bunny')->delete();
        MediaProvider::create([
            'provider' => 'bunny', 'label' => 'Bunny Stream', 'enabled' => true,
            'credentials' => json_encode([
                'library_id' => '112233', 'api_key' => 'test-api-key',
                'security_key' => 'test-security-key', 'hostname' => '',
            ]),
            'webhook_secret' => 'test-bunny-webhook-secret', 'environment' => 'sandbox',
            'connection_status' => 'unknown', 'config' => [], 'metadata' => [],
        ]);

        Http::fake([
            '*bunnycdn.com/*/videos?*' => Http::response([
                'totalItems' => 1,
                'items' => [[
                    'guid' => 'orphan-vid-1', 'title' => 'Lost upload',
                    'length' => 42.0, 'status' => 4,
                ]],
            ], 200),
            '*bunnycdn.com/*' => Http::response([], 200),
        ]);

        $result = app(MediaReconciliationService::class)->reconcile('bunny');

        $this->assertSame(1, $result['orphans_detected']);

        // Parked in the tenant-less quarantine table…
        $this->assertDatabaseHas('media_orphan_assets', [
            'provider' => 'bunny', 'provider_asset_id' => 'orphan-vid-1',
        ]);

        // …never as an asset with a fabricated (zero) tenant, which used to
        // violate media_assets_tenant_id_foreign and fail the whole sync.
        $this->assertSame(0, MediaAsset::withoutTenant()
            ->where('provider_asset_id', 'orphan-vid-1')->count());
        $this->assertSame(0, MediaAsset::withoutTenant()
            ->where('tenant_id', '00000000-0000-0000-0000-000000000000')->count());

        // A second run refreshes the row instead of duplicating it.
        $again = app(MediaReconciliationService::class)->reconcile('bunny');
        $this->assertSame(0, $again['orphans_detected']);
        $this->assertSame(1, MediaOrphanAsset::where('provider_asset_id', 'orphan-vid-1')->count());
    }

    /** @test */
    public function test_reconcile_drops_a_quarantined_video_once_it_has_a_real_owner(): void
    {
        MediaProvider::where('provider', 'bunny')->delete();
        MediaProvider::create([
            'provider' => 'bunny', 'label' => 'Bunny Stream', 'enabled' => true,
            'credentials' => json_encode([
                'library_id' => '112233', 'api_key' => 'test-api-key',
                'security_key' => 'test-security-key', 'hostname' => '',
            ]),
            'webhook_secret' => 'test-bunny-webhook-secret', 'environment' => 'sandbox',
            'connection_status' => 'unknown', 'config' => [], 'metadata' => [],
        ]);

        $tenant = $this->makeTenant();
        MediaOrphanAsset::create([
            'id' => UuidV7::generate(), 'provider' => 'bunny',
            'provider_asset_id' => 'claimed-vid', 'first_seen_at' => now(), 'metadata' => [],
        ]);
        MediaAsset::withoutTenant()->create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id,
            'provider' => 'bunny', 'provider_asset_id' => 'claimed-vid', 'kind' => 'video',
            'status' => MediaAsset::STATUS_READY, 'title' => 'Claimed', 'metadata' => [],
        ]);

        Http::fake([
            '*bunnycdn.com/*/videos?*' => Http::response([
                'items' => [['guid' => 'claimed-vid', 'title' => 'Claimed', 'length' => 10.0, 'status' => 4]],
            ], 200),
            '*bunnycdn.com/*' => Http::response([], 200),
        ]);

        app(MediaReconciliationService::class)->reconcile('bunny');

        $this->assertSame(0, MediaOrphanAsset::where('provider_asset_id', 'claimed-vid')->count());
    }

    // ─── Area 9: Arabic validation ───────────────────────────────────

    /** @test */
    public function test_validation_errors_are_localised_in_arabic(): void
    {
        $message = trans('validation.required', ['attribute' => 'العنوان'], 'ar');

        $this->assertStringContainsString('العنوان', $message);
        $this->assertSame('حقل العنصر مطلوب.', trans('validation.required', ['attribute' => 'العنصر'], 'ar'));
    }
}
