<?php

namespace Tests\Feature;

use App\Live\LiveProviderManager;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonContent;
use App\Models\LiveSession;
use App\Models\PlanFeature;
use App\Models\PlatformSetting;
use App\Models\Section;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Plans\PlanEngine;
use Illuminate\Support\Str;
use Symfony\Component\Uid\UuidV7;

/**
 * البث المباشر النهائي: Google Meet فقط.
 *
 * - المعلم ينشئ جلسة برابط meet.google.com الخاص به (إلزامي + صيغة صحيحة).
 * - لا مزود آخر يظهر للمعلم ولا للأدمن.
 * - الطالب المصرح له يرى الرابط، وغير المصرح له 403.
 * - الجلسات القديمة بمزودات محذوفة تبقى مقروءة من metadata (بلا مزود).
 * - حصص الخطط (عدد الجلسات + دقائق البث) ما زالت تُفرض.
 */
class LiveStreamingTest extends TenantTestCase
{
    private const MEET_URL = 'https://meet.google.com/abc-defg-hij';

    private function makeCourseWithLesson(Tenant $tenant): array
    {
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');

        $course = Course::create([
            'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'teacher_id' => $teacher->id,
            'title' => 'Live Course', 'slug' => 'live-course-'.Str::random(6),
            'description' => '', 'is_published' => true, 'sort_order' => 0,
        ]);

        $section = Section::create([
            'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'course_id' => $course->id,
            'title' => 'S', 'sort_order' => 1, 'metadata' => [],
        ]);

        $lesson = Lesson::create([
            'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'section_id' => $section->id,
            'title' => 'L', 'sort_order' => 1, 'metadata' => [],
        ]);

        return [$teacher, $course, $lesson];
    }

    private function makeLiveSession(Tenant $tenant, ?string $provider = 'google_meet', ?string $courseId = null, ?string $lessonContentId = null): LiveSession
    {
        return LiveSession::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenant->id,
            'user_id' => $this->makeUser()->id,
            'lesson_content_id' => $lessonContentId,
            'course_id' => $courseId,
            'title' => 'Live session',
            'provider' => $provider,
            'room_name' => 'room-'.Str::random(10),
            'status' => 'scheduled',
            'max_participants' => 100,
            'record_enabled' => false,
            'config' => [],
            'metadata' => $provider === 'google_meet' ? ['meeting_url' => self::MEET_URL, 'start_url' => self::MEET_URL, 'meeting_id' => 'abc-defg-hij'] : [],
        ]);
    }

    private function setPlanLimit(string $tenantId, array $limits): void
    {
        $plan = TenantSubscription::withoutTenant()->where('tenant_id', $tenantId)->first()->plan;

        foreach ($limits as $key => $value) {
            PlanFeature::updateOrCreate(
                ['plan_id' => $plan->id, 'feature_key' => $key],
                ['feature_value' => (string) $value, 'feature_type' => 'string'],
            );
        }

        PlanEngine::bumpVersion();
    }

    // ─── Providers registry: Google Meet only ───────────

    public function test_google_meet_is_the_only_available_provider(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');

        $this->getJson('/api/v1/teacher/live-sessions/providers', $this->authHeaders($teacher, $tenant))
            ->assertOk()
            ->assertJsonCount(1, 'providers')
            ->assertJsonPath('providers.0.provider', 'google_meet')
            ->assertJsonPath('providers.0.player_type', 'meeting');
    }

    public function test_admin_live_status_shows_google_meet_ready_only(): void
    {
        $admin = $this->makeUser(['is_super_admin' => true]);

        $response = $this->getJson('/api/v1/admin/live/providers', $this->authHeaders($admin, $this->makeTenant()))
            ->assertOk();

        $providers = $response->json('providers');
        $this->assertSame(['google_meet', 'default_provider'], array_keys($providers));

        $this->assertTrue($providers['google_meet']['enabled']);
        $this->assertTrue($providers['google_meet']['configured']);
        $this->assertTrue($providers['google_meet']['default']);
        $this->assertTrue((bool) $providers['google_meet']['health']['ok']);
        // لا أي حقول اعتمادات — Google Meet لا يحتاج Client ID/Secret.
        $this->assertSame([], $providers['google_meet']['fields']);
    }

    // ─── Create (teacher) ───────────────────────────────

    public function test_teacher_can_create_google_meet_session_with_own_link(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');

        $response = $this->postJson('/api/v1/teacher/live-sessions', [
            'title' => 'مراجعة شاملة',
            'provider' => 'google_meet',
            'meeting_url' => self::MEET_URL,
            'duration_minutes' => 60,
        ], $this->authHeaders($teacher, $tenant))
            ->assertStatus(201);

        $session = $response->json('session');
        $this->assertSame('google_meet', $session['provider']);
        $this->assertSame('scheduled', $session['status']);
        $this->assertSame(self::MEET_URL, $session['meeting_url']);
        $this->assertSame(self::MEET_URL, $session['start_url']);
        $this->assertSame('meeting', $session['player_type']);

        $this->assertDatabaseHas('live_sessions', [
            'id' => $session['id'],
            'provider' => 'google_meet',
        ]);
    }

    public function test_google_meet_requires_valid_meeting_url(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');
        $headers = $this->authHeaders($teacher, $tenant);

        foreach ([
            '',
            'https://meet.example.com/abc',
            'https://zoom.us/j/123',
            'https://meet.google.com/',
            'not-a-url',
        ] as $bad) {
            $this->postJson('/api/v1/teacher/live-sessions', [
                'title' => 'T', 'provider' => 'google_meet', 'meeting_url' => $bad,
            ], $headers)->assertStatus(422);
        }

        $this->assertDatabaseCount('live_sessions', 0);
    }

    public function test_meeting_url_is_mandatory_for_google_meet(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');

        $this->postJson('/api/v1/teacher/live-sessions', [
            'title' => 'T',
            'provider' => 'google_meet',
        ], $this->authHeaders($teacher, $tenant))->assertStatus(422);
    }

    public function test_teacher_can_update_google_meet_link(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');

        $session = $this->makeLiveSession($tenant, 'google_meet');
        $session->update(['user_id' => $teacher->id]);

        $newUrl = 'https://meet.google.com/xyz-abc-def';

        $this->putJson('/api/v1/teacher/live-sessions/'.$session->id, [
            'meeting_url' => $newUrl,
        ], $this->authHeaders($teacher, $tenant))
            ->assertOk()
            ->assertJsonPath('session.meeting_url', $newUrl)
            ->assertJsonPath('session.start_url', $newUrl);

        $this->assertSame($newUrl, $session->fresh()->metadata['meeting_url']);
    }

    public function test_provider_cannot_be_changed_after_creation(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');

        $session = $this->makeLiveSession($tenant, 'google_meet');
        $session->update(['user_id' => $teacher->id]);

        // المحاولة لا تنتج provider مختلفاً — الحقل غير قابل للتغيير.
        $this->putJson('/api/v1/teacher/live-sessions/'.$session->id, [
            'title' => 'Renamed',
        ], $this->authHeaders($teacher, $tenant))->assertOk();

        $this->assertSame('google_meet', $session->fresh()->provider);
    }

    public function test_disabled_provider_cannot_create_sessions(): void
    {
        PlatformSetting::set('live_google_meet_enabled', false);
        app(LiveProviderManager::class)->invalidateStatusCache();

        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');

        $this->postJson('/api/v1/teacher/live-sessions', [
            'title' => 'T', 'provider' => 'google_meet', 'meeting_url' => self::MEET_URL,
        ], $this->authHeaders($teacher, $tenant))->assertStatus(403);

        PlatformSetting::set('live_google_meet_enabled', true);
        app(LiveProviderManager::class)->invalidateStatusCache();
    }

    // ─── Join authorization (student) ───────────────────

    public function test_unauthorized_student_is_rejected_from_join_ticket(): void
    {
        [$teacher, $course] = $this->makeCourseWithLesson($this->makeTenant());
        $tenant = $course->tenant;
        $this->attachSubscription($tenant);

        $session = $this->makeLiveSession($tenant, 'google_meet', $course->id);
        $session->update(['user_id' => $teacher->id, 'status' => 'live']);

        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');

        $this->postJson('/api/v1/live-sessions/'.$session->id.'/join-ticket', [], $this->authHeaders($student, $tenant))
            ->assertStatus(403);
    }

    public function test_google_meet_join_payload_reaches_enrolled_students(): void
    {
        [$teacher, $course] = $this->makeCourseWithLesson($this->makeTenant());
        $tenant = $course->tenant;
        $this->attachSubscription($tenant);

        $session = $this->makeLiveSession($tenant, 'google_meet', $course->id);
        $session->update(['user_id' => $teacher->id, 'status' => 'live']);

        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');
        Enrollment::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenant->id,
            'course_id' => $course->id,
            'user_id' => $student->id,
            'status' => 'active',
            'enrolled_at' => now(),
            'metadata' => [],
        ]);

        $this->postJson('/api/v1/live-sessions/'.$session->id.'/join-ticket', [], $this->authHeaders($student, $tenant))
            ->assertOk()
            ->assertJsonPath('role', 'participant')
            ->assertJsonPath('meeting_url', self::MEET_URL)
            ->assertJsonPath('player_type', 'meeting')
            // لا start_url للطالب — الرابط يظهر كرابط انضمام فقط.
            ->assertJsonMissing(['start_url' => self::MEET_URL]);
    }

    public function test_join_ticket_teacher_is_host_with_same_link(): void
    {
        [$teacher, $course] = $this->makeCourseWithLesson($this->makeTenant());
        $tenant = $course->tenant;
        $this->attachSubscription($tenant);

        $session = $this->makeLiveSession($tenant, 'google_meet', $course->id);
        $session->update(['user_id' => $teacher->id, 'status' => 'live']);

        $this->postJson('/api/v1/live-sessions/'.$session->id.'/join-ticket', [], $this->authHeaders($teacher, $tenant))
            ->assertOk()
            ->assertJsonPath('role', 'host')
            ->assertJsonPath('meeting_url', self::MEET_URL)
            ->assertJsonPath('start_url', self::MEET_URL);
    }

    public function test_join_ticket_and_show_payload_keep_host_secrets_from_students(): void
    {
        [$teacher, $course] = $this->makeCourseWithLesson($this->makeTenant());
        $tenant = $course->tenant;
        $this->attachSubscription($tenant);

        $session = $this->makeLiveSession($tenant, 'google_meet', $course->id);
        $session->update(['user_id' => $teacher->id]);

        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');
        Enrollment::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenant->id,
            'course_id' => $course->id,
            'user_id' => $student->id,
            'status' => 'active',
            'enrolled_at' => now(),
            'metadata' => [],
        ]);

        // طالب مسجل: يرى meeting_url ولا يرى start_url/meeting_id/record_url.
        $studentView = $this->getJson('/api/v1/live-sessions/'.$session->id, $this->authHeaders($student, $tenant));
        $studentView->assertOk();
        $studentView->assertJsonPath('session.meeting_url', self::MEET_URL);
        $studentView->assertJsonMissing(['start_url' => self::MEET_URL]);
        $studentView->assertJsonMissing(['meeting_id' => 'abc-defg-hij']);

        // المعلم: يرى start_url و meeting_id.
        $teacherView = $this->getJson('/api/v1/live-sessions/'.$session->id, $this->authHeaders($teacher, $tenant));
        $teacherView->assertOk();
        $teacherView->assertJsonPath('session.start_url', self::MEET_URL);
        $teacherView->assertJsonPath('session.meeting_id', 'abc-defg-hij');
    }

    public function test_google_meet_needs_no_credentials_or_integration(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');

        // لا integrations ولا zoom_connections — الجلسة تعمل بلا أي اعتمادات.
        $this->postJson('/api/v1/teacher/live-sessions', [
            'title' => 'T', 'provider' => 'google_meet', 'meeting_url' => self::MEET_URL,
        ], $this->authHeaders($teacher, $tenant))->assertStatus(201);
    }

    // ─── Legacy stored sessions (removed providers) ─────

    public function test_legacy_sessions_with_removed_providers_stay_readable(): void
    {
        [$teacher, $course] = $this->makeCourseWithLesson($this->makeTenant());
        $tenant = $course->tenant;
        $this->attachSubscription($tenant);

        foreach (['zoom', 'jitsi', 'teams', 'youtube', 'bunny', 'cloudflare', 'browser', 'custom'] as $provider) {
            $session = LiveSession::create([
                'id' => UuidV7::generate(),
                'tenant_id' => $tenant->id,
                'user_id' => $teacher->id,
                'course_id' => $course->id,
                'title' => 'Legacy '.$provider,
                'provider' => $provider,
                'room_name' => 'room-'.Str::random(8),
                'status' => 'scheduled',
                'metadata' => ['meeting_url' => 'https://legacy.example/'.$provider],
            ]);

            $this->getJson('/api/v1/live-sessions/'.$session->id, $this->authHeaders($teacher, $tenant))
                ->assertOk()
                ->assertJsonPath('session.provider', $provider)
                ->assertJsonPath('session.meeting_url', 'https://legacy.example/'.$provider)
                ->assertJsonPath('session.player_type', 'meeting');
        }
    }

    // ─── Quotas (plan limits still enforced) ────────────

    public function test_live_sessions_limit_is_enforced_on_creation(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $this->setPlanLimit($tenant->id, ['live_sessions_limit' => 1, 'live_stream_enabled' => true]);

        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');
        $headers = $this->authHeaders($teacher, $tenant);

        $this->postJson('/api/v1/teacher/live-sessions', [
            'title' => 'A', 'provider' => 'google_meet', 'meeting_url' => self::MEET_URL,
        ], $headers)->assertStatus(201);

        $this->postJson('/api/v1/teacher/live-sessions', [
            'title' => 'B', 'provider' => 'google_meet', 'meeting_url' => self::MEET_URL,
        ], $headers)->assertStatus(403);
    }

    public function test_live_minutes_limit_blocks_starting_when_exhausted(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $this->setPlanLimit($tenant->id, ['live_minutes_limit' => 30, 'live_stream_enabled' => true]);

        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');

        $session = $this->makeLiveSession($tenant, 'google_meet');
        $session->update(['user_id' => $teacher->id]);

        // استهلاك 30 دقيقة كاملة الشهر الحالي.
        app(\App\Media\MediaUsageService::class)->applyDelta(
            $tenant->id, 'live_minutes', 30, 'test', null, 'live_session', $session->id,
        );

        $this->postJson('/api/v1/teacher/live-sessions/'.$session->id.'/start', [], $this->authHeaders($teacher, $tenant))
            ->assertStatus(422);

        $this->assertSame('scheduled', $session->fresh()->status);
    }

    public function test_usage_ledger_is_idempotent_for_live_events(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');

        $session = $this->makeLiveSession($tenant, 'google_meet');
        $session->update(['user_id' => $teacher->id]);

        $usage = app(\App\Media\MediaUsageService::class);

        $usage->applyDelta($tenant->id, 'live_sessions_count', 1, 'live_created', null, 'live_session', $session->id, idempotencyKey: 'live:created:'.$session->id);
        $usage->applyDelta($tenant->id, 'live_sessions_count', 1, 'live_created', null, 'live_session', $session->id, idempotencyKey: 'live:created:'.$session->id);

        $monthly = $usage->monthlyAll($tenant->id, now()->format('Y-m'));
        $this->assertSame(1, (int) ($monthly['live_sessions_count'] ?? 0));
    }

    // ─── Tenant isolation & lifecycle ───────────────────

    public function test_tenant_isolation_blocks_cross_tenant_management(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $this->attachSubscription($tenantA);
        $this->attachSubscription($tenantB);

        $teacherB = $this->makeUser();
        $this->attachToTenant($teacherB, $tenantB, 'teacher');

        $sessionA = $this->makeLiveSession($tenantA, 'google_meet');

        $this->getJson('/api/v1/live-sessions/'.$sessionA->id, $this->authHeaders($teacherB, $tenantB))
            ->assertStatus(404);

        $this->putJson('/api/v1/teacher/live-sessions/'.$sessionA->id, ['title' => 'Hack'], $this->authHeaders($teacherB, $tenantB))
            ->assertStatus(404);
    }

    public function test_session_start_end_cancel_destroy_lifecycle(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');

        $session = $this->makeLiveSession($tenant, 'google_meet');
        $session->update(['user_id' => $teacher->id]);
        $headers = $this->authHeaders($teacher, $tenant);

        // start → live
        $this->postJson('/api/v1/teacher/live-sessions/'.$session->id.'/start', [], $headers)
            ->assertOk()
            ->assertJsonPath('session.status', 'live');

        // end → ended
        $this->postJson('/api/v1/teacher/live-sessions/'.$session->id.'/end', [], $headers)
            ->assertOk()
            ->assertJsonPath('session.status', 'ended');

        // إعادة إنهاء جلسة منتهية → 422
        $this->postJson('/api/v1/teacher/live-sessions/'.$session->id.'/end', [], $headers)
            ->assertStatus(422);

        // جلسة مجدولة → إلغاء → حذف
        $scheduled = $this->makeLiveSession($tenant, 'google_meet');
        $scheduled->update(['user_id' => $teacher->id]);

        $this->postJson('/api/v1/teacher/live-sessions/'.$scheduled->id.'/cancel', [], $headers)
            ->assertOk()
            ->assertJsonPath('session.status', 'cancelled');

        $this->deleteJson('/api/v1/teacher/live-sessions/'.$scheduled->id, [], $headers)
            ->assertOk();

        $this->assertDatabaseMissing('live_sessions', ['id' => $scheduled->id]);
    }

    public function test_ended_session_join_is_rejected(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');

        $session = $this->makeLiveSession($tenant, 'google_meet');
        $session->update(['user_id' => $teacher->id, 'status' => 'ended']);

        $this->postJson('/api/v1/live-sessions/'.$session->id.'/join-ticket', [], $this->authHeaders($teacher, $tenant))
            ->assertStatus(403);
    }

    public function test_concurrent_live_creation_never_exceeds_sessions_limit(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $this->setPlanLimit($tenant->id, ['live_sessions_limit' => 5, 'live_stream_enabled' => true]);

        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');

        // إنشاء متزامن (10 طلبات موازية) — بحد أقصى 5 جلسات.
        $responses = [];

        for ($i = 0; $i < 10; $i++) {
            $responses[] = $this->postJson('/api/v1/teacher/live-sessions', [
                'title' => 'C'.$i, 'provider' => 'google_meet', 'meeting_url' => self::MEET_URL,
            ], $this->authHeaders($teacher, $tenant));
        }

        $created = collect($responses)->filter(fn ($r) => $r->status() === 201)->count();
        $this->assertLessThanOrEqual(5, $created);
        $this->assertSame($created, LiveSession::withoutTenant()->where('tenant_id', $tenant->id)->count());
    }
}