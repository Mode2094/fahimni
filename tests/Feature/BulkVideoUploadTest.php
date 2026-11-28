<?php

namespace Tests\Feature;

use App\Media\MediaUsageService;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonContent;
use App\Models\MediaAsset;
use App\Models\MediaProvider;
use App\Models\PlanFeature;
use App\Models\Section;
use App\Models\VideoContent;
use App\Plans\PlanEngine;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\Uid\UuidV7;

/**
 * الرفع الجماعي المباشر إلى Bunny (TUS) — بلا مرور الفيديو عبر السيرفر.
 *
 * يغطي: إنشاء التذكرة لكل ملف عبر HTTP، حتمية/Idempotency (لا تكرار ولا احتساب
 * مساحة مرتين)، الحصص والحدود (مساحة/عدد)، عزل المستأجر، إتمام الرفع وربطه
 * بالدرس مع البقاء `processing` حتى يؤكد المزود، وتنظيف الإلغاء.
 */
class BulkVideoUploadTest extends TenantTestCase
{
    private const LIBRARY = '112233';

    private const API_KEY = 'test-api-key';

    private function setupBunnyProvider(): void
    {
        MediaProvider::where('provider', 'bunny')->delete();

        MediaProvider::create([
            'provider' => 'bunny',
            'label' => 'Bunny Stream',
            'enabled' => true,
            'credentials' => json_encode([
                'library_id' => self::LIBRARY,
                'api_key' => self::API_KEY,
                'security_key' => 'test-security-key',
                'hostname' => '',
            ]),
            'webhook_secret' => 'test-bunny-webhook-secret',
            'environment' => 'sandbox',
            'connection_status' => 'unknown',
            'config' => [],
            'metadata' => [],
        ]);
    }

    /** كل نداء إنشاء فيديو في Bunny يعيد GUID جديداً. */
    private function fakeTicket(): void
    {
        $n = 0;
        Http::fake([
            '*bunnycdn.com/*/videos' => function () use (&$n) {
                $n++;

                return Http::response(['guid' => 'guid-'.$n, 'status' => 0, 'length' => 0], 200);
            },
            '*bunnycdn.com/*' => Http::response([], 200),
        ]);
    }

    private function makeCourse(string $tenantId, string $teacherId): Course
    {
        return Course::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenantId, 'teacher_id' => $teacherId,
            'title' => 'Course '.Str::random(4), 'slug' => 'course-'.Str::random(6),
            'description' => '', 'is_published' => true, 'sort_order' => 0,
        ]);
    }

    private function makeLesson(Course $course): Lesson
    {
        $section = Section::create([
            'id' => UuidV7::generate(), 'course_id' => $course->id, 'tenant_id' => $course->tenant_id,
            'title' => 'Section', 'sort_order' => 0, 'is_published' => true,
        ]);

        return Lesson::create([
            'id' => UuidV7::generate(), 'section_id' => $section->id, 'tenant_id' => $course->tenant_id,
            'title' => 'Lesson', 'duration_minutes' => 10, 'sort_order' => 0,
            'is_published' => true, 'is_free_preview' => false, 'lesson_type' => 'mixed',
        ]);
    }

    // ─── Ticket: direct + resumable, real quota, no server transit ───

    public function test_resumable_ticket_returns_tus_credentials_with_a_server_side_signature(): void
    {
        $this->setupBunnyProvider();
        $this->fakeTicket();

        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');
        $course = $this->makeCourse($tenant->id, $teacher->id);
        $lesson = $this->makeLesson($course);

        $res = $this->postJson('/api/v1/teacher/media/resumable-ticket', [
            'lesson_id' => $lesson->id,
            'title' => 'Intro',
            'file_size' => 50 * 1024 * 1024,
            'original_filename' => 'intro.mp4',
            'idempotency_key' => 'bulk-1',
        ], $this->authHeaders($teacher, $tenant))->assertCreated();

        $upload = $res->json('upload');

        // المتصفح يرفع مباشرة إلى Bunny — لا يمر الملف عبر السيرفر.
        $this->assertSame('https://video.bunnycdn.com/tusupload', $upload['endpoint']);
        $this->assertSame(self::LIBRARY, $upload['library_id']);
        $this->assertNotEmpty($upload['video_guid']);
        $this->assertGreaterThanOrEqual(time() + 3600, $upload['expires']);

        // التوقيع يُحسب على السيرفر: SHA256(library_id + api_key + expires + video_id).
        $expected = hash('sha256', self::LIBRARY.self::API_KEY.$upload['expires'].$upload['video_guid']);
        $this->assertSame($expected, $upload['signature']);
        $this->assertSame(64, strlen($upload['signature']));

        // الحجز والعدد يُسجّلان فوراً (عدد الفيديوهات يزيد عند بدء الرفع).
        $this->assertNotEmpty($upload['reservation_id']);
        $this->assertSame(1, app(MediaUsageService::class)->current($tenant->id)['video_count']);
        $this->assertSame(0, app(MediaUsageService::class)->current($tenant->id)['storage_bytes']);
    }

    public function test_resumable_ticket_is_idempotent_so_a_retry_never_creates_a_second_video(): void
    {
        $this->setupBunnyProvider();
        $this->fakeTicket();

        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');
        $headers = $this->authHeaders($teacher, $tenant);

        $payload = [
            'title' => 'Same file', 'file_size' => 1024 * 1024, 'idempotency_key' => 'same-key',
        ];

        $first = $this->postJson('/api/v1/teacher/media/resumable-ticket', $payload, $headers)->assertCreated();
        $second = $this->postJson('/api/v1/teacher/media/resumable-ticket', $payload, $headers)->assertCreated();

        $this->assertSame($first->json('upload.asset_id'), $second->json('upload.asset_id'));
        $this->assertSame($first->json('upload.video_guid'), $second->json('upload.video_guid'));
        $this->assertTrue($second->json('upload.reused'));

        // فيديو واحد فقط، وعدد الفيديوهات 1 (لا احتساب مزدوج). مفتاح
        // الـidempotency صار مُنطّقًا بالمستأجر (SHA256 للمعرّف + المفتاح
        // الخام) حتى لا يتصادم مفتاحان متطابقان بين مستأجرين مختلفين.
        $scopedKey = hash('sha256', $tenant->id."\0".'same-key');
        $this->assertSame(1, MediaAsset::withoutTenant()
            ->where('tenant_id', $tenant->id)
            ->where('idempotency_key', $scopedKey)
            ->count());
        $this->assertSame(1, app(MediaUsageService::class)->current($tenant->id)['video_count']);
    }

    public function test_resumable_ticket_is_rejected_when_storage_is_full(): void
    {
        $this->setupBunnyProvider();
        $this->fakeTicket();

        $tenant = $this->makeTenant();
        $sub = $this->attachSubscription($tenant);
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');

        // خطة بسعة 1 ميجابايت، ومستهلك 0.95MB — رفع 1MB يجب أن يُرفض.
        PlanFeature::where('plan_id', $sub->plan_id)->where('feature_key', 'storage_limit_mb')
            ->update(['feature_value' => '1']);
        PlanEngine::bumpVersion();

        app(MediaUsageService::class)->applyDelta($tenant->id, 'storage_bytes', (int) (0.95 * 1024 * 1024), 'seed');

        $res = $this->postJson('/api/v1/teacher/media/resumable-ticket', [
            'title' => 'Too big', 'file_size' => 1024 * 1024,
        ], $this->authHeaders($teacher, $tenant));

        $res->assertStatus(422);
    }

    public function test_resumable_ticket_rejects_a_lesson_from_another_tenant(): void
    {
        $this->setupBunnyProvider();
        $this->fakeTicket();

        $tenantA = $this->makeTenant();
        $this->attachSubscription($tenantA);
        $teacherA = $this->makeUser();
        $this->attachToTenant($teacherA, $tenantA, 'teacher');

        $tenantB = $this->makeTenant();
        $teacherB = $this->makeUser();
        $this->attachToTenant($teacherB, $tenantB, 'teacher');
        $lessonB = $this->makeLesson($this->makeCourse($tenantB->id, $teacherB->id));

        $this->postJson('/api/v1/teacher/media/resumable-ticket', [
            'lesson_id' => $lessonB->id, 'title' => 'X', 'file_size' => 1024 * 1024,
        ], $this->authHeaders($teacherA, $tenantA))->assertNotFound();
    }

    public function test_resumable_ticket_is_forbidden_for_students(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');

        $this->postJson('/api/v1/teacher/media/resumable-ticket', [
            'title' => 'X', 'file_size' => 1024 * 1024,
        ], $this->authHeaders($student, $tenant))->assertForbidden();
    }

    public function test_resumable_ticket_enforces_the_per_file_size_ceiling(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');

        $this->postJson('/api/v1/teacher/media/resumable-ticket', [
            'title' => 'Huge', 'file_size' => 6 * 1024 * 1024 * 1024, // فوق 5 GB
        ], $this->authHeaders($teacher, $tenant))->assertStatus(422);
    }

    public function test_an_unlimited_video_plan_never_imposes_a_numeric_cap(): void
    {
        $this->setupBunnyProvider();
        $this->fakeTicket();

        $tenant = $this->makeTenant();
        $sub = $this->attachSubscription($tenant);
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');
        $headers = $this->authHeaders($teacher, $tenant);

        PlanFeature::where('plan_id', $sub->plan_id)->where('feature_key', 'videos_limit')
            ->update(['feature_value' => 'unlimited']);
        PlanEngine::bumpVersion();

        // 40 ملفاً دفعةً واحدة — كلها تُقبل (الحد لا يُفرض رقمياً).
        for ($i = 1; $i <= 40; $i++) {
            $this->postJson('/api/v1/teacher/media/resumable-ticket', [
                'title' => 'V'.$i, 'file_size' => 1024 * 1024, 'idempotency_key' => 'bulk-'.$i,
            ], $headers)->assertCreated();
        }

        $this->assertSame(40, app(MediaUsageService::class)->current($tenant->id)['video_count']);
    }

    // ─── Complete: attach to lesson, stay processing until provider confirms ───

    public function test_resumable_complete_attaches_the_lesson_but_never_marks_it_ready_prematurely(): void
    {
        $this->setupBunnyProvider();
        $this->fakeTicket();

        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');
        $course = $this->makeCourse($tenant->id, $teacher->id);
        $lesson = $this->makeLesson($course);
        $headers = $this->authHeaders($teacher, $tenant);

        $ticket = $this->postJson('/api/v1/teacher/media/resumable-ticket', [
            'lesson_id' => $lesson->id, 'title' => 'V', 'file_size' => 1024 * 1024, 'idempotency_key' => 'c-1',
        ], $headers)->assertCreated();

        $assetId = $ticket->json('upload.asset_id');

        $complete = $this->postJson('/api/v1/teacher/media/resumable-complete', [
            'asset_id' => $assetId, 'lesson_id' => $lesson->id,
        ], $headers)->assertCreated();

        $content = LessonContent::withoutTenant()->where('lesson_id', $lesson->id)->firstOrFail();
        $video = VideoContent::withoutTenant()->where('lesson_content_id', $content->id)->firstOrFail();

        // مربوط، لكن الحالة `processing` — الجاهزية تُنتظر من تأكيد المزود.
        $this->assertSame('processing', $complete->json('video.status'));
        $this->assertSame('processing', $video->status);
        $this->assertSame($content->id, MediaAsset::withoutTenant()->findOrFail($assetId)->lesson_content_id);
        $this->assertSame('video', $content->content_type);
    }

    public function test_resumable_complete_is_idempotent(): void
    {
        $this->setupBunnyProvider();
        $this->fakeTicket();

        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');
        $course = $this->makeCourse($tenant->id, $teacher->id);
        $lesson = $this->makeLesson($course);
        $headers = $this->authHeaders($teacher, $tenant);

        $assetId = $this->postJson('/api/v1/teacher/media/resumable-ticket', [
            'lesson_id' => $lesson->id, 'title' => 'V', 'file_size' => 1024 * 1024, 'idempotency_key' => 'c-2',
        ], $headers)->json('upload.asset_id');

        $this->postJson('/api/v1/teacher/media/resumable-complete', ['asset_id' => $assetId, 'lesson_id' => $lesson->id], $headers)->assertCreated();
        $this->postJson('/api/v1/teacher/media/resumable-complete', ['asset_id' => $assetId, 'lesson_id' => $lesson->id], $headers)->assertCreated();

        $this->assertSame(1, LessonContent::withoutTenant()->where('lesson_id', $lesson->id)->count());
        $this->assertSame(1, VideoContent::withoutTenant()->where('lesson_id', $lesson->id)->count());
    }

    public function test_resumable_complete_rejects_an_asset_from_another_tenant(): void
    {
        $this->setupBunnyProvider();
        $this->fakeTicket();

        $tenantA = $this->makeTenant();
        $this->attachSubscription($tenantA);
        $teacherA = $this->makeUser();
        $this->attachToTenant($teacherA, $tenantA, 'teacher');
        $lessonA = $this->makeLesson($this->makeCourse($tenantA->id, $teacherA->id));

        $tenantB = $this->makeTenant();
        $this->attachSubscription($tenantB);
        $teacherB = $this->makeUser();
        $this->attachToTenant($teacherB, $tenantB, 'teacher');

        $foreignAsset = $this->postJson('/api/v1/teacher/media/resumable-ticket', [
            'title' => 'B', 'file_size' => 1024 * 1024, 'idempotency_key' => 'b-1',
        ], $this->authHeaders($teacherB, $tenantB))->json('upload.asset_id');

        $this->postJson('/api/v1/teacher/media/resumable-complete', [
            'asset_id' => $foreignAsset, 'lesson_id' => $lessonA->id,
        ], $this->authHeaders($teacherA, $tenantA))->assertNotFound();
    }

    // ─── Visibility: never show an unfinished video to students ───

    public function test_an_incomplete_upload_is_never_playable_by_students_until_the_provider_confirms_it(): void
    {
        $this->setupBunnyProvider();
        $this->fakeTicket();

        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');
        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');
        $course = $this->makeCourse($tenant->id, $teacher->id);
        $lesson = $this->makeLesson($course);
        $headers = $this->authHeaders($teacher, $tenant);

        $ticket = $this->postJson('/api/v1/teacher/media/resumable-ticket', [
            'lesson_id' => $lesson->id, 'title' => 'V', 'file_size' => 1024 * 1024, 'idempotency_key' => 'vis-1',
        ], $headers)->assertCreated();

        $assetId = $ticket->json('upload.asset_id');
        $guid = $ticket->json('upload.video_guid');

        $this->postJson('/api/v1/teacher/media/resumable-complete', [
            'asset_id' => $assetId, 'lesson_id' => $lesson->id,
        ], $headers)->assertCreated();

        $asset = MediaAsset::withoutTenant()->findOrFail($assetId);
        $this->assertSame('processing', $asset->status);

        // رغم أن الفيديو مربوط بالدرس، لا يُقدّم للطالب قبل تأكيد المزود.
        $security = app(\App\Media\MediaSecurityService::class);
        $this->assertFalse($security->canAccess($asset, $student));
        $this->assertNull($security->signedPlayback($asset, $student)['stream_url']);

        // عند تأكيد المزّود (Webhook) تصبح جاهزة — وعندها فقط.
        app(\App\Media\MediaService::class)->markAssetReady(
            app(\App\Media\MediaProviderManager::class)->provider('bunny'),
            (string) $guid,
            'ready-evt-1',
        );

        $this->assertSame('ready', MediaAsset::withoutTenant()->findOrFail($assetId)->status);
        $this->assertSame('ready', VideoContent::withoutTenant()->where('lesson_id', $lesson->id)->firstOrFail()->status);
    }

    // ─── Cancel / cleanup ────────────────────────────────
    public function test_cancelling_an_unfinished_upload_frees_the_reservation_and_the_video_slot(): void
    {
        $this->setupBunnyProvider();
        $this->fakeTicket();

        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');
        $headers = $this->authHeaders($teacher, $tenant);

        $assetId = $this->postJson('/api/v1/teacher/media/resumable-ticket', [
            'title' => 'V', 'file_size' => 2 * 1024 * 1024, 'idempotency_key' => 'cancel-1',
        ], $headers)->json('upload.asset_id');

        $this->assertSame(2 * 1024 * 1024, (int) Cache::get('media:reservation:pending:'.$tenant->id));
        $this->assertSame(1, app(MediaUsageService::class)->current($tenant->id)['video_count']);

        $this->deleteJson('/api/v1/teacher/media/assets/'.$assetId, [], $headers)->assertOk();

        // الحجز المعلّق تحرّر، وعدّاد الفيديوهات عاد صفراً، والأصل أُرشف.
        $this->assertSame(0, (int) Cache::get('media:reservation:pending:'.$tenant->id));
        $this->assertSame(0, app(MediaUsageService::class)->current($tenant->id)['video_count']);
        $this->assertSoftDeleted('media_assets', ['id' => $assetId]);

        // ويمكن حجز مساحة أخرى فوراً (لا مساحة معلّقة بلا داعٍ).
        $this->postJson('/api/v1/teacher/media/resumable-ticket', [
            'title' => 'New', 'file_size' => 2 * 1024 * 1024, 'idempotency_key' => 'cancel-2',
        ], $headers)->assertCreated();
    }
}
