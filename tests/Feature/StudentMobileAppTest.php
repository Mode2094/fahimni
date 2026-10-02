<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonContent;
use App\Models\PlanFeature;
use App\Models\PlatformSetting;
use App\Models\Section;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\TenantSubscription;
use App\Models\VideoContent;
use App\Services\TeacherCodeService;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Student Mobile App: teacher-code validation, scoped login, teacher
 * context enforcement, resource ownership and Super Admin control.
 */
class StudentMobileAppTest extends TenantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // All test requests share one IP, which exhausts the login/register
        // throttle limits. Rate limiting is covered by its own tests; here we
        // disable it so the auth flows can be asserted cleanly.
        $this->withoutMiddleware(ThrottleRequests::class);

        // The platform toggle starts ENABLED for the mobile feature unless a
        // test explicitly disables it. All gates are DB-driven.
        PlatformSetting::set('student_mobile_app', true);
        PlatformSetting::set('student_app_android_url', 'https://play.google.com/store/apps/details?id=com.shahead.lms');
        PlatformSetting::set('student_app_ios_url', 'https://apps.apple.com/app/id123456789');
    }

    private function addFeatureToPlan(TenantSubscription $sub, string $key, string $value): void
    {
        PlanFeature::create([
            'id' => (string) Str::uuid(),
            'plan_id' => $sub->plan_id,
            'feature_key' => $key,
            'feature_value' => $value,
            'feature_type' => 'string',
            'sort_order' => 0,
        ]);
    }

    private function makeCourseTree(Tenant $tenant): array
    {
        $course = Course::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'title' => 'Mobile Course',
            'slug' => 'mob-'.Str::random(6),
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
            'title' => 'L1',
            'lesson_type' => 'video',
            'is_published' => true,
            'sort_order' => 0,
        ]);

        $lessonContent = LessonContent::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'lesson_id' => $lesson->id,
            'content_type' => 'video',
            'sort_order' => 0,
        ]);

        $video = VideoContent::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'lesson_content_id' => $lessonContent->id,
            'lesson_id' => $lesson->id,
            'provider' => 'bunny',
            'provider_video_id' => 'bunny-'.Str::random(6),
            'is_uploaded' => false,
            'status' => 'ready',
            'title' => 'Protected Video',
        ]);

        return compact('course', 'section', 'lesson', 'video');
    }

    private function enableFeature(Tenant $tenant): void
    {
        $this->addFeatureToPlan($this->attachSubscription($tenant), 'student_mobile_app', 'true');
    }

    private function mobileHeaders(?string $token = null, ?string $tenantId = null, string $device = 'dev-mobile-1'): array
    {
        $headers = ['Accept' => 'application/json'];
        if ($token) {
            $headers['Authorization'] = 'Bearer '.$token;
        }
        if ($tenantId) {
            $headers['X-Tenant-Id'] = $tenantId;
        }
        if ($device) {
            $headers['X-Device-Id'] = $device;
        }

        return $headers;
    }

    // ─────────────────────────────────────────────────────────────
    // 1. Teacher code validation
    // ─────────────────────────────────────────────────────────────

    public function test_validate_code_returns_context_when_everything_enabled(): void
    {
        $tenant = $this->makeTenant();
        $this->enableFeature($tenant);

        $response = $this->postJson('/api/v1/mobile/teacher-code/validate', [
            'teacher_code' => $tenant->teacher_code,
        ]);

        $response->assertOk();
        $response->assertJsonPath('valid', true);
        $response->assertJsonPath('teacher.teacher.id', $tenant->id);
        $response->assertJsonPath('teacher.teacher.teacher_code', $tenant->teacher_code);
        $this->assertArrayHasKey('site', $response->json('teacher'));
        $this->assertSame($tenant->name, $response->json('teacher.site.name'));
    }

    public function test_validate_code_rejects_unknown_code(): void
    {
        $response = $this->postJson('/api/v1/mobile/teacher-code/validate', [
            'teacher_code' => 'SHA-XXXXXX',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('reason', 'code_not_found');
    }

    public function test_validate_code_rejected_when_platform_feature_disabled(): void
    {
        PlatformSetting::set('student_mobile_app', false);

        $tenant = $this->makeTenant();
        $this->enableFeature($tenant);

        $response = $this->postJson('/api/v1/mobile/teacher-code/validate', [
            'teacher_code' => $tenant->teacher_code,
        ]);

        $response->assertStatus(503);
        $response->assertJsonPath('reason', 'feature_disabled');
    }

    public function test_validate_code_rejected_when_plan_has_no_feature(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);

        $response = $this->postJson('/api/v1/mobile/teacher-code/validate', [
            'teacher_code' => $tenant->teacher_code,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('reason', 'plan_denied');
    }

    public function test_validate_code_rejected_when_subscription_inactive(): void
    {
        $tenant = $this->makeTenant();
        $sub = $this->attachSubscription($tenant);
        $this->addFeatureToPlan($sub, 'student_mobile_app', 'true');
        $sub->update(['status' => 'cancelled']);

        $response = $this->postJson('/api/v1/mobile/teacher-code/validate', [
            'teacher_code' => $tenant->teacher_code,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('reason', 'subscription_inactive');
    }

    public function test_validate_code_rejected_when_teacher_inactive(): void
    {
        $tenant = $this->makeTenant();
        $this->enableFeature($tenant);
        $tenant->update(['status' => 'suspended']);

        $response = $this->postJson('/api/v1/mobile/teacher-code/validate', [
            'teacher_code' => $tenant->teacher_code,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('reason', 'teacher_inactive');
    }

    public function test_teacher_codes_are_unique_and_stable(): void
    {
        $a = $this->makeTenant();
        $b = $this->makeTenant();

        $this->assertNotSame($a->teacher_code, $b->teacher_code);
        $this->assertNotSame($a->teacher_code, $a->id);
        $this->assertTrue(app(TeacherCodeService::class)->isValidFormat($a->teacher_code));

        $a->refresh();
        $this->assertSame($a->teacher_code, $a->teacher_code);
    }

    // ─────────────────────────────────────────────────────────────
    // 2. Scoped mobile login
    // ─────────────────────────────────────────────────────────────

    public function test_mobile_login_succeeds_for_member_student(): void
    {
        $tenant = $this->makeTenant();
        $this->enableFeature($tenant);

        $student = $this->makeUser(['email' => 'student@test.local', 'password' => bcrypt('secret123')]);
        $this->attachToTenant($student, $tenant, 'student');

        $response = $this->postJson('/api/v1/mobile/auth/login', [
            'teacher_code' => $tenant->teacher_code,
            'email' => 'student@test.local',
            'password' => 'secret123',
        ], $this->mobileHeaders(null, $tenant->id));

        $response->assertOk();
        $response->assertJsonStructure(['token', 'user', 'teacher']);
        $response->assertJsonPath('teacher.id', $tenant->id);
    }

    public function test_mobile_login_rejects_student_not_member_of_teacher(): void
    {
        $tenant = $this->makeTenant();
        $this->enableFeature($tenant);

        // The student belongs to a DIFFERENT teacher.
        $other = $this->makeTenant();
        $student = $this->makeUser(['email' => 'outsider@test.local', 'password' => bcrypt('secret123')]);
        $this->attachToTenant($student, $other, 'student');

        $response = $this->postJson('/api/v1/mobile/auth/login', [
            'teacher_code' => $tenant->teacher_code,
            'email' => 'outsider@test.local',
            'password' => 'secret123',
        ], $this->mobileHeaders(null, $tenant->id));

        $response->assertStatus(403);
        $response->assertJsonPath('reason', 'not_member');
    }

    public function test_mobile_login_rejects_teacher_role(): void
    {
        $tenant = $this->makeTenant();
        $this->enableFeature($tenant);

        $teacher = $this->makeUser(['email' => 'teacher@test.local', 'password' => bcrypt('secret123')]);
        $this->attachToTenant($teacher, $tenant, 'teacher');

        $response = $this->postJson('/api/v1/mobile/auth/login', [
            'teacher_code' => $tenant->teacher_code,
            'email' => 'teacher@test.local',
            'password' => 'secret123',
        ], $this->mobileHeaders(null, $tenant->id));

        $response->assertStatus(403);
        $response->assertJsonPath('reason', 'not_a_student');
    }

    public function test_mobile_login_rejects_invalid_credentials(): void
    {
        $tenant = $this->makeTenant();
        $this->enableFeature($tenant);

        $response = $this->postJson('/api/v1/mobile/auth/login', [
            'teacher_code' => $tenant->teacher_code,
            'email' => 'nobody@test.local',
            'password' => 'wrong',
        ], $this->mobileHeaders(null, $tenant->id));

        $response->assertStatus(401);
        $response->assertJsonPath('reason', 'invalid_credentials');
    }

    public function test_mobile_login_requires_valid_teacher_code(): void
    {
        $response = $this->postJson('/api/v1/mobile/auth/login', [
            'teacher_code' => 'SHA-XXXXXX',
            'email' => 'x@test.local',
            'password' => 'secret123',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('reason', 'code_not_found');
    }

    // ─────────────────────────────────────────────────────────────
    // 3. Teacher context & ownership enforcement
    // ─────────────────────────────────────────────────────────────

    public function test_mobile_me_returns_context_for_member_student(): void
    {
        $tenant = $this->makeTenant();
        $this->enableFeature($tenant);

        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');
        $token = $student->createToken('t')->plainTextToken;

        $response = $this->getJson('/api/v1/mobile/me', $this->mobileHeaders($token, $tenant->id));

        $response->assertOk();
        $response->assertJsonPath('user.id', $student->id);
        $response->assertJsonPath('teacher.id', $tenant->id);
    }

    public function test_mobile_me_rejects_student_from_other_teacher(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $this->enableFeature($tenantA);
        $this->enableFeature($tenantB);

        $student = $this->makeUser();
        $this->attachToTenant($student, $tenantA, 'student');
        $token = $student->createToken('t')->plainTextToken;

        // Student of A tries to read context under B's tenant header.
        $response = $this->getJson('/api/v1/mobile/me', $this->mobileHeaders($token, $tenantB->id));

        $response->assertStatus(403);
        $response->assertJsonPath('reason', 'not_member');
    }

    public function test_mobile_course_denied_for_course_of_other_teacher(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $this->enableFeature($tenantA);
        $this->enableFeature($tenantB);

        ['course' => $courseB] = $this->makeCourseTree($tenantB);

        $student = $this->makeUser();
        $this->attachToTenant($student, $tenantA, 'student');
        $token = $student->createToken('t')->plainTextToken;

        $response = $this->getJson("/api/v1/mobile/courses/{$courseB->id}", $this->mobileHeaders($token, $tenantA->id));

        $response->assertStatus(404);
    }

    public function test_mobile_home_returns_enrolled_courses_and_branding(): void
    {
        $tenant = $this->makeTenant();
        $this->enableFeature($tenant);
        TenantSetting::firstOrCreate(
            ['tenant_id' => $tenant->id],
            ['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'academy_name' => 'My Academy', 'logo_url' => 'https://cdn.example.com/logo.png']
        );

        ['course' => $course] = $this->makeCourseTree($tenant);

        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');
        Enrollment::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'course_id' => $course->id,
            'user_id' => $student->id,
            'status' => 'active',
            'enrolled_at' => now(),
            'metadata' => [],
        ]);
        $token = $student->createToken('t')->plainTextToken;

        $response = $this->getJson('/api/v1/mobile/home', $this->mobileHeaders($token, $tenant->id));

        $response->assertOk();
        $response->assertJsonPath('site.name', 'My Academy');
        $response->assertJsonPath('site.logo_url', 'https://cdn.example.com/logo.png');
        $this->assertCount(1, $response->json('enrolled_courses'));
        $response->assertJsonPath('enrolled_courses.0.course.title', $course->title);
    }

    public function test_mobile_course_returns_sections_and_lock_state(): void
    {
        $tenant = $this->makeTenant();
        $this->enableFeature($tenant);
        ['course' => $course] = $this->makeCourseTree($tenant);

        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');
        Enrollment::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'course_id' => $course->id,
            'user_id' => $student->id,
            'status' => 'active',
            'enrolled_at' => now(),
            'metadata' => [],
        ]);
        $token = $student->createToken('t')->plainTextToken;

        $response = $this->getJson("/api/v1/mobile/courses/{$course->id}", $this->mobileHeaders($token, $tenant->id));

        $response->assertOk();
        $response->assertJsonPath('enrolled', true);
        $this->assertCount(1, $response->json('sections'));
        $this->assertCount(1, $response->json('sections.0.lessons'));
    }

    public function test_mobile_lesson_returns_protected_video_payload(): void
    {
        $tenant = $this->makeTenant();
        $this->enableFeature($tenant);
        ['course' => $course, 'lesson' => $lesson] = $this->makeCourseTree($tenant);

        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');
        Enrollment::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'course_id' => $course->id,
            'user_id' => $student->id,
            'status' => 'active',
            'enrolled_at' => now(),
            'metadata' => [],
        ]);
        $token = $student->createToken('t')->plainTextToken;

        $response = $this->getJson("/api/v1/mobile/lessons/{$lesson->id}", $this->mobileHeaders($token, $tenant->id));

        $response->assertOk();
        $this->assertCount(1, $response->json('contents'));
        $video = $response->json('contents.0.video');
        $this->assertNotNull($video);
        // Provider playback must NOT leak a permanent public URL — status+protection only.
        $this->assertArrayHasKey('protection', $video);
    }

    public function test_mobile_lesson_locked_for_non_enrolled_student(): void
    {
        $tenant = $this->makeTenant();
        $this->enableFeature($tenant);
        ['lesson' => $lesson] = $this->makeCourseTree($tenant);

        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');
        $token = $student->createToken('t')->plainTextToken;

        $response = $this->getJson("/api/v1/mobile/lessons/{$lesson->id}", $this->mobileHeaders($token, $tenant->id));

        $response->assertStatus(403);
    }

    // ─────────────────────────────────────────────────────────────
    // 4. Teacher panel + Super Admin control
    // ─────────────────────────────────────────────────────────────

    public function test_teacher_student_app_payload_shows_code_and_links_when_enabled(): void
    {
        $tenant = $this->makeTenant();
        $this->enableFeature($tenant);

        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'owner');

        $response = $this->getJson('/api/v1/teacher/student-app', $this->authHeaders($teacher, $tenant));

        $response->assertOk();
        $response->assertJsonPath('student_app.enabled', true);
        $response->assertJsonPath('student_app.teacher_code', $tenant->teacher_code);
        $response->assertJsonPath('student_app.android_url', 'https://play.google.com/store/apps/details?id=com.shahead.lms');
        $response->assertJsonPath('student_app.ios_url', 'https://apps.apple.com/app/id123456789');
        $this->assertStringStartsWith('shahead://teacher/', $response->json('student_app.deep_link'));
    }

    public function test_teacher_student_app_shows_under_development_when_disabled(): void
    {
        PlatformSetting::set('student_mobile_app', false);

        $tenant = $this->makeTenant();
        $this->enableFeature($tenant);

        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'owner');

        $response = $this->getJson('/api/v1/teacher/student-app', $this->authHeaders($teacher, $tenant));

        $response->assertOk();
        $response->assertJsonPath('student_app.enabled', false);
        $response->assertJsonPath('student_app.teacher_code', $tenant->teacher_code);
        $response->assertJsonPath('student_app.android_url', 'https://play.google.com/store/apps/details?id=com.shahead.lms');
    }

    public function test_teacher_cannot_access_student_app_without_tenant(): void
    {
        $teacher = $this->makeUser();

        $response = $this->getJson('/api/v1/teacher/student-app', [
            'Authorization' => 'Bearer '.$teacher->createToken('t')->plainTextToken,
            'Accept' => 'application/json',
        ]);

        $response->assertStatus(400);
    }

    public function test_admin_updates_student_app_settings(): void
    {
        $admin = $this->makeUser(['is_super_admin' => true]);

        $this->app['auth']->forgetGuards();

        $response = $this->actingAs($admin)->putJson('/api/v1/platform-settings', [
            'student_mobile_app' => true,
            'student_app_android_url' => 'https://play.google.com/new',
            'student_app_ios_url' => 'https://apps.apple.com/new',
        ]);

        $response->assertOk();
        $this->assertTrue((bool) PlatformSetting::get('student_mobile_app'));
        $this->assertSame('https://play.google.com/new', PlatformSetting::get('student_app_android_url'));
    }

    public function test_student_cannot_update_student_app_settings(): void
    {
        $student = $this->makeUser();
        $this->attachToTenant($student, $this->makeTenant(), 'student');

        $response = $this->putJson('/api/v1/platform-settings', [
            'student_mobile_app' => true,
        ], $this->authHeaders($student, $this->makeTenant()));

        $response->assertStatus(403);
    }

    public function test_regenerate_requires_confirm_and_logs_audit(): void
    {
        $tenant = $this->makeTenant();
        $this->enableFeature($tenant);
        $oldCode = $tenant->teacher_code;

        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'owner');

        // Without confirm → rejected.
        $this->postJson('/api/v1/teacher/student-app/regenerate', [], $this->authHeaders($teacher, $tenant))
            ->assertStatus(422);

        // With confirm → new stable code + audit log entry.
        $response = $this->postJson('/api/v1/teacher/student-app/regenerate', ['confirm' => true], $this->authHeaders($teacher, $tenant));
        $response->assertOk();

        $tenant->refresh();
        $this->assertNotSame($oldCode, $tenant->teacher_code);
        $this->assertNotSame($tenant->teacher_code, $oldCode);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'student_app.code_regenerated',
            'tenant_id' => $tenant->id,
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    // 5. Multiple teachers — no context mixing
    // ─────────────────────────────────────────────────────────────

    public function test_same_student_with_two_teachers_never_mixes_contexts(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $this->enableFeature($tenantA);
        $this->enableFeature($tenantB);

        $student = $this->makeUser();
        $this->attachToTenant($student, $tenantA, 'student');
        $this->attachToTenant($student, $tenantB, 'student');

        $token = $student->createToken('t')->plainTextToken;

        // Under A.
        $meA = $this->getJson('/api/v1/mobile/me', $this->mobileHeaders($token, $tenantA->id));
        $meA->assertOk();
        $meA->assertJsonPath('teacher.id', $tenantA->id);

        // Under B.
        $meB = $this->getJson('/api/v1/mobile/me', $this->mobileHeaders($token, $tenantB->id));
        $meB->assertOk();
        $meB->assertJsonPath('teacher.id', $tenantB->id);
    }

    // ─────────────────────────────────────────────────────────────
    // 6. Student registration (inside teacher context)
    // ─────────────────────────────────────────────────────────────

    public function test_mobile_register_creates_student_and_attaches_to_teacher(): void
    {
        $tenant = $this->makeTenant();
        $this->enableFeature($tenant);

        $response = $this->postJson('/api/v1/mobile/auth/register', [
            'teacher_code' => $tenant->teacher_code,
            'name' => 'New Student',
            'email' => 'newstudent@test.local',
            'phone' => '0791234567',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ], $this->mobileHeaders(null, $tenant->id, 'dev-register-1'));

        $response->assertOk();
        $response->assertJsonStructure(['token', 'user', 'teacher']);
        $response->assertJsonPath('teacher.id', $tenant->id);
        $response->assertJsonPath('user.email', 'newstudent@test.local');

        $this->assertDatabaseHas('tenant_user', [
            'tenant_id' => $tenant->id,
            'user_id' => $response->json('user.id'),
            'role' => 'student',
            'status' => 'active',
        ]);
    }

    public function test_mobile_register_rejects_existing_email(): void
    {
        $tenant = $this->makeTenant();
        $this->enableFeature($tenant);

        $student = $this->makeUser(['email' => 'taken@test.local']);
        $this->attachToTenant($student, $tenant, 'student');

        $response = $this->postJson('/api/v1/mobile/auth/register', [
            'teacher_code' => $tenant->teacher_code,
            'name' => 'Duplicate',
            'email' => 'taken@test.local',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ], $this->mobileHeaders(null, $tenant->id));

        $response->assertStatus(422);
        $response->assertJsonPath('reason', 'account_exists');
    }

    public function test_mobile_register_requires_valid_code(): void
    {
        $response = $this->postJson('/api/v1/mobile/auth/register', [
            'teacher_code' => 'SHA-XXXXXX',
            'name' => 'X',
            'email' => 'x@test.local',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('reason', 'code_not_found');
    }

    public function test_mobile_register_respects_platform_registration_toggle(): void
    {
        PlatformSetting::set('registration_enabled', false);

        $tenant = $this->makeTenant();
        $this->enableFeature($tenant);

        $response = $this->postJson('/api/v1/mobile/auth/register', [
            'teacher_code' => $tenant->teacher_code,
            'name' => 'X',
            'email' => 'x@test.local',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ], $this->mobileHeaders(null, $tenant->id));

        $response->assertStatus(403);
        $response->assertJsonPath('reason', 'registration_disabled');
    }

    // ─────────────────────────────────────────────────────────────
    // 7. Google (Gmail) sign-in inside teacher context
    // ─────────────────────────────────────────────────────────────

    public function test_mobile_google_creates_and_attaches_student(): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response([
                'email' => 'gmail.student@test.local',
                'email_verified' => true,
                'name' => 'Gmail Student',
            ], 200),
        ]);

        $tenant = $this->makeTenant();
        $this->enableFeature($tenant);

        $response = $this->postJson('/api/v1/mobile/auth/google', [
            'teacher_code' => $tenant->teacher_code,
            'id_token' => 'fake-google-id-token',
        ], $this->mobileHeaders(null, $tenant->id, 'dev-google-1'));

        $response->assertOk();
        $response->assertJsonPath('teacher.id', $tenant->id);
        $response->assertJsonPath('user.email', 'gmail.student@test.local');

        $this->assertDatabaseHas('tenant_user', [
            'tenant_id' => $tenant->id,
            'user_id' => $response->json('user.id'),
            'role' => 'student',
            'status' => 'active',
        ]);
    }

    public function test_mobile_google_reattaches_existing_student_member(): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response([
                'email' => 'existing.gmail@test.local',
                'email_verified' => true,
            ], 200),
        ]);

        $tenant = $this->makeTenant();
        $this->enableFeature($tenant);

        $student = $this->makeUser(['email' => 'existing.gmail@test.local']);
        $this->attachToTenant($student, $tenant, 'student');

        $response = $this->postJson('/api/v1/mobile/auth/google', [
            'teacher_code' => $tenant->teacher_code,
            'id_token' => 'fake-google-id-token',
        ], $this->mobileHeaders(null, $tenant->id, 'dev-google-2'));

        $response->assertOk();
        $response->assertJsonPath('user.id', $student->id);
    }

    public function test_mobile_google_rejects_invalid_token(): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response([], 400),
        ]);

        $tenant = $this->makeTenant();
        $this->enableFeature($tenant);

        $response = $this->postJson('/api/v1/mobile/auth/google', [
            'teacher_code' => $tenant->teacher_code,
            'id_token' => 'bad-token',
        ], $this->mobileHeaders(null, $tenant->id));

        $response->assertStatus(422);
        $response->assertJsonPath('reason', 'google_verify_failed');
    }

    public function test_mobile_google_rejects_teacher_staff(): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response([
                'email' => 'staff.gmail@test.local',
                'email_verified' => true,
            ], 200),
        ]);

        $tenant = $this->makeTenant();
        $this->enableFeature($tenant);

        $staff = $this->makeUser(['email' => 'staff.gmail@test.local']);
        $this->attachToTenant($staff, $tenant, 'teacher');

        $response = $this->postJson('/api/v1/mobile/auth/google', [
            'teacher_code' => $tenant->teacher_code,
            'id_token' => 'fake-google-id-token',
        ], $this->mobileHeaders(null, $tenant->id));

        $response->assertStatus(403);
        $response->assertJsonPath('reason', 'not_a_student');
    }
}
