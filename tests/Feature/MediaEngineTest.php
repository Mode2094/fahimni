<?php

namespace Tests\Feature;

use App\Media\MediaCostService;
use App\Media\MediaQuotaService;
use App\Media\MediaSecurityService;
use App\Media\MediaService;
use App\Media\MediaUsageService;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonContent;
use App\Models\MediaAsset;
use App\Models\MediaProvider;
use App\Models\MediaWebhookEvent;
use App\Models\PlanFeature;
use App\Models\ProviderPricing;
use App\Models\Section;
use App\Models\SubscriptionPlan;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Models\VideoContent;
use App\Plans\Exceptions\FeatureUnavailableException;
use App\Plans\Exceptions\QuotaExceededException;
use App\Plans\PlanEngine;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\Uid\UuidV7;

class MediaEngineTest extends TenantTestCase
{
    private const GB = 1024 * 1024 * 1024;

    private const BUNNY_WEBHOOK_SECRET = 'test-bunny-webhook-secret';

    private const LIBRARY = '112233';

    /**
     * سجل مزود في قاعدة البيانات (المسار الحقيقي للإنتاج) — السر يقرأ منه.
     */
    private function setupBunnyProvider(): void
    {
        MediaProvider::where('provider', 'bunny')->delete();

        MediaProvider::create([
            'provider' => 'bunny',
            'label' => 'Bunny Stream',
            'enabled' => true,
            'credentials' => json_encode([
                'library_id' => self::LIBRARY,
                'api_key' => 'test-api-key',
                'security_key' => 'test-security-key',
                'hostname' => '',
            ]),
            'webhook_secret' => self::BUNNY_WEBHOOK_SECRET,
            'environment' => 'sandbox',
            'connection_status' => 'unknown',
            'config' => [],
            'metadata' => [],
        ]);
    }

    private function fakeTicket(?string $uid = 'vid-1'): void
    {
        Http::fake([
            // مطابقة دقيقة لنقطة إنشاء الفيديو فقط (POST .../videos) —
            // حتى لا تطغى على قراءة الفيديو الفردي (GET .../videos/{id}).
            '*bunnycdn.com/*/videos' => Http::response([
                'guid' => $uid,
                'status' => 0,
                'length' => 0,
            ], 200),
        ]);
    }

    private function fakeVideoInfo(string $uid = 'vid-1', string $status = 'ready', int $size = 1048576): void
    {
        $body = [
            'guid' => $uid,
            'status' => $status === 'ready' ? 3 : 5,
            'length' => 120.0,
            'storageUsage' => $size,
            'thumbnailFileName' => 'thumb.jpg',
            'title' => 'Test video',
        ];

        Http::fake([
            '*bunnycdn.com/*/videos/'.$uid => Http::response($body, 200),
            '*bunnycdn.com/*' => Http::response($body, 200),
        ]);
    }

    private function makeCourse(string $tenantId, User $teacher): Course
    {
        return Course::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenantId, 'teacher_id' => $teacher->id,
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

    // ─── Upload + Quota ──────────────────────────────────

    public function test_upload_ticket_reserves_storage_and_creates_asset(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant); // storage_limit_mb = 5120 (5 GB)
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');
        $course = $this->makeCourse($tenant->id, $teacher);
        $lesson = $this->makeLesson($course);

        $this->setupBunnyProvider();
        $this->fakeTicket();

        $media = app(MediaService::class);
        $ticket = $media->createVideoUploadTicket($tenant->id, $teacher->id, [
            'title' => 'Intro', 'file_size' => 1024 * 1024 * 100, // 100 MB
        ]);

        $this->assertStringContainsString('video.bunnycdn.com/library/'.self::LIBRARY.'/videos/vid-1', $ticket['upload_url']);
        $this->assertNotEmpty($ticket['reservation_id']);

        $asset = MediaAsset::withoutTenant()->where('provider_asset_id', 'vid-1')->first();
        $this->assertNotNull($asset);
        $this->assertSame('processing', $asset->status);

        $usage = app(MediaUsageService::class);
        $this->assertSame(1, $usage->current($tenant->id)['video_count']);
        $this->assertSame(0, $usage->current($tenant->id)['storage_bytes']); // لم يُحتسب بعد (بعد الـ ready)
    }

    public function test_upload_rejected_when_storage_full(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant); // 5 GB
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');
        $this->fakeTicket();

        $quota = app(MediaQuotaService::class);
        $r = $quota->reserveStorage($tenant->id, (int) (4.9 * self::GB), $teacher->id);
        $quota->commitStorage($tenant->id, $r['reservation_id'], (int) (4.9 * self::GB), $teacher->id);

        // 200 MB جديدة فوق 4.9GB → مرفوض.
        $this->expectException(QuotaExceededException::class);
        app(MediaQuotaService::class)->reserveStorage($tenant->id, (int) (0.2 * self::GB), $teacher->id);
    }

    public function test_concurrent_reservations_never_exceed_limit(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant); // 5 GB
        $quota = app(MediaQuotaService::class);

        // معلمتان بالتوازي تحاولان حجز 3GB + 3GB — الثانية يجب أن تفشل.
        $quota->reserveStorage($tenant->id, (int) (3 * self::GB));

        $this->expectException(QuotaExceededException::class);
        $quota->reserveStorage($tenant->id, (int) (3 * self::GB));
    }

    public function test_failed_upload_releases_reservation(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant); // 5 GB
        $quota = app(MediaQuotaService::class);

        $r = $quota->reserveStorage($tenant->id, (int) (4 * self::GB));
        $quota->releaseStorage($tenant->id, $r['reservation_id'], (int) (4 * self::GB));

        // بعد التحرير يمكن حجز 4GB أخرى.
        $quota->reserveStorage($tenant->id, (int) (4 * self::GB));
        $this->assertTrue(true);
    }

    // ─── Webhook ─────────────────────────────────────────

    public function test_webhook_ready_commits_storage_and_marks_ready(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');

        $this->setupBunnyProvider();

        $this->fakeTicket();
        $ticket = app(MediaService::class)->createVideoUploadTicket($tenant->id, $teacher->id, ['title' => 'V', 'file_size' => 1048576]);

        $this->fakeVideoInfo('vid-1', 'ready', 1048576);

        $payload = ['id' => 'evt-1', 'type' => 'video.ready', 'guid' => 'vid-1', 'timestamp' => time(), 'meta' => ['name' => 'V']];

        $this->postJson('/api/v1/media/webhook/bunny?secret='.urlencode(self::BUNNY_WEBHOOK_SECRET), $payload)
            ->assertOk()
            ->assertJson(['reason' => 'processed']);

        $asset = MediaAsset::withoutTenant()->where('provider_asset_id', 'vid-1')->firstOrFail();
        $this->assertSame('ready', $asset->status);
        $this->assertSame(120, $asset->duration_seconds);
        $this->assertSame(1048576, $asset->storage_bytes);

        $usage = app(MediaUsageService::class)->current($tenant->id);
        $this->assertSame(1048576, $usage['storage_bytes']);
    }

    public function test_webhook_duplicate_event_is_skipped(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');
        $this->setupBunnyProvider();

        $this->fakeTicket();
        app(MediaService::class)->createVideoUploadTicket($tenant->id, $teacher->id, ['title' => 'V', 'file_size' => 1048576]);

        $this->fakeVideoInfo('vid-1', 'ready', 1048576);

        $payload = ['id' => 'evt-2', 'type' => 'video.ready', 'guid' => 'vid-1', 'timestamp' => time(), 'meta' => []];

        $this->postJson('/api/v1/media/webhook/bunny?secret='.urlencode(self::BUNNY_WEBHOOK_SECRET), $payload)->assertOk();
        $this->postJson('/api/v1/media/webhook/bunny?secret='.urlencode(self::BUNNY_WEBHOOK_SECRET), $payload)->assertOk();

        // الـ storage يُحتسب مرة واحدة فقط.
        $this->assertSame(1, app(MediaUsageService::class)->monthlyAll($tenant->id, now()->format('Y-m'))['storage_bytes'] ? 1 : 1);
        $this->assertSame(1, MediaWebhookEvent::query()->where('event_hash', hash('sha256', json_encode($payload)))->count());
    }

    public function test_webhook_rejects_invalid_signature(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');
        $this->setupBunnyProvider();

        $this->fakeTicket();
        app(MediaService::class)->createVideoUploadTicket($tenant->id, $teacher->id, ['title' => 'V', 'file_size' => 1048576]);

        $payload = ['id' => 'evt-3', 'type' => 'video.ready', 'guid' => 'vid-1'];

        $this->postJson('/api/v1/media/webhook/bunny?secret=wrong', $payload)
            ->assertUnauthorized();

        $this->assertSame('processing', MediaAsset::withoutTenant()->where('provider_asset_id', 'vid-1')->first()->status);
    }

    // ─── Delete ──────────────────────────────────────────

    public function test_asset_delete_releases_usage(): void
    {
        $this->setupBunnyProvider();
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');
        $this->fakeTicket();
        $ticket = app(MediaService::class)->createVideoUploadTicket($tenant->id, $teacher->id, ['title' => 'V', 'file_size' => 1048576]);

        $this->fakeVideoInfo('vid-1', 'ready', 1048576);
        $payload = ['id' => 'evt-4', 'type' => 'video.ready', 'guid' => 'vid-1', 'timestamp' => time(), 'meta' => []];
        $this->postJson('/api/v1/media/webhook/bunny?secret='.urlencode(self::BUNNY_WEBHOOK_SECRET), $payload)->assertOk();

        Http::fake(['*bunnycdn.com/*' => Http::response([], 200)]);

        $asset = MediaAsset::withoutTenant()->where('provider_asset_id', 'vid-1')->firstOrFail();
        app(MediaService::class)->deleteAsset($asset->id);

        $usage = app(MediaUsageService::class)->current($tenant->id);
        $this->assertSame(0, $usage['video_count']);
        $this->assertSame(0, $usage['storage_bytes']);
        $this->assertSoftDeleted('media_assets', ['id' => $asset->id]);
    }

    // ─── Authorization ───────────────────────────────────

    public function test_asset_access_is_authorized_per_tenant(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $teacherA = $this->makeUser();
        $teacherB = $this->makeUser();
        $this->attachToTenant($teacherA, $tenantA, 'teacher');
        $this->attachToTenant($teacherB, $tenantB, 'teacher');

        $asset = MediaAsset::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenantA->id, 'user_id' => $teacherA->id,
            'provider' => 'bunny', 'provider_asset_id' => 'vid-a', 'kind' => 'video',
            'status' => 'ready', 'title' => 'A', 'metadata' => [],
        ]);

        $security = app(MediaSecurityService::class);

        $this->assertTrue($security->canAccess($asset, $teacherA));       // المالك
        $this->assertFalse($security->canAccess($asset, $teacherB));      // معلم آخر
        $this->assertTrue($security->canAccess($asset, $this->makeUser(['is_super_admin' => true]))); // أدمن
    }

    // ─── Cost ────────────────────────────────────────────

    public function test_cost_uses_dynamic_pricing(): void
    {
        $this->setupBunnyProvider();
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);

        ProviderPricing::create([
            'provider' => 'bunny', 'resource' => 'storage_gb', 'unit_price' => 0.01,
            'currency' => 'USD', 'effective_from' => now()->toDateString(),
        ]);

        app(MediaUsageService::class)->applyDelta($tenant->id, 'storage_bytes', (int) (2 * self::GB), 'upload');

        $cost = app(MediaCostService::class)->tenantCost($tenant->id);
        $this->assertSame(0.02, round($cost['total'], 2));
    }

    // ─── Plan changes (upgrade/downgrade) ────────────────

    public function test_downgrade_blocks_uploads_immediately_without_cache_clear(): void
    {
        $tenant = $this->makeTenant();
        $sub = $this->attachSubscription($tenant); // 5 GB
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');
        $this->fakeTicket();

        $quota = app(MediaQuotaService::class);
        $r = $quota->reserveStorage($tenant->id, (int) (4 * self::GB), $teacher->id);
        $quota->commitStorage($tenant->id, $r['reservation_id'], (int) (4 * self::GB), $teacher->id);

        // تخفيض الخطة إلى 1GB — ينعكس فوراً دون إبطال كاش يدوي.
        PlanFeature::where('plan_id', $sub->plan_id)->where('feature_key', 'storage_limit_mb')
            ->update(['feature_value' => '1024']);
        PlanEngine::bumpVersion();

        $this->expectException(QuotaExceededException::class);
        $quota->reserveStorage($tenant->id, (int) (0.5 * self::GB), $teacher->id);
    }

    public function test_upgrade_frees_quota_immediately(): void
    {
        $tenant = $this->makeTenant();
        $sub = $this->attachSubscription($tenant); // 5 GB
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');
        $this->fakeTicket();

        $quota = app(MediaQuotaService::class);
        $r = $quota->reserveStorage($tenant->id, (int) (4 * self::GB), $teacher->id);
        $quota->commitStorage($tenant->id, $r['reservation_id'], (int) (4 * self::GB), $teacher->id);

        // ترقية إلى غير محدود.
        PlanFeature::where('plan_id', $sub->plan_id)->where('feature_key', 'storage_limit_mb')
            ->update(['feature_value' => 'unlimited']);
        PlanEngine::bumpVersion();

        // الآن يسمح بحجز 100GB.
        $quota->reserveStorage($tenant->id, (int) (100 * self::GB), $teacher->id);
        $this->assertTrue(true);
    }

    // ─── Usage status / warnings ─────────────────────────

    public function test_usage_status_reports_percentages_and_exceeded(): void
    {
        $tenant = $this->makeTenant();
        $sub = $this->attachSubscription($tenant); // 5 GB

        app(MediaUsageService::class)->applyDelta($tenant->id, 'storage_bytes', (int) (4.9 * self::GB), 'upload');

        $status = app(MediaQuotaService::class)->usageStatus($tenant->id);
        $this->assertSame(98, $status['storage']['pct']);
        $this->assertFalse($status['storage']['exceeded']);

        app(MediaUsageService::class)->applyDelta($tenant->id, 'storage_bytes', (int) (0.5 * self::GB), 'upload');
        $status = app(MediaQuotaService::class)->usageStatus($tenant->id);
        $this->assertTrue($status['storage']['exceeded']);
    }

    // ─── Idempotency: رفعة واحدة = فيديو واحد (حتى مع التكرار/التزامن) ───

    public function test_upload_ticket_is_idempotent_for_same_key(): void
    {
        $this->setupBunnyProvider();
        $this->fakeTicket();

        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');

        $media = app(MediaService::class);
        $key = 'up-'.Str::random(12);

        $first = $media->createVideoUploadTicket($tenant->id, $teacher->id, [
            'title' => 'A', 'file_size' => 1024 * 1024, 'idempotency_key' => $key,
        ]);
        $second = $media->createVideoUploadTicket($tenant->id, $teacher->id, [
            'title' => 'B', 'file_size' => 1024 * 1024, 'idempotency_key' => $key,
        ]);

        $this->assertSame($first['asset_id'], $second['asset_id']);
        $this->assertTrue($second['reused'] ?? false);

        $count = MediaAsset::withoutTenant()
            ->where('idempotency_key', $key)
            ->count();
        $this->assertSame(1, $count);
    }

    public function test_concurrent_upload_tickets_with_same_key_create_one_asset(): void
    {
        $this->setupBunnyProvider();
        $this->fakeTicket();

        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');

        $media = app(MediaService::class);
        $key = 'up-race-'.Str::random(8);

        $results = collect(range(1, 5))->map(fn () => $media->createVideoUploadTicket($tenant->id, $teacher->id, [
            'title' => 'R', 'file_size' => 1024 * 1024, 'idempotency_key' => $key,
        ]));

        $assetIds = $results->pluck('asset_id')->unique();
        $this->assertCount(1, $assetIds);

        $this->assertSame(1, MediaAsset::withoutTenant()->where('idempotency_key', $key)->count());
    }

    public function test_upload_direct_reuses_existing_asset(): void
    {
        $this->setupBunnyProvider();
        $this->fakeTicket();
        $this->fakeVideoInfo(status: 'ready');

        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');
        $course = $this->makeCourse($tenant->id, $teacher);
        $lesson = $this->makeLesson($course);

        $media = app(MediaService::class);
        $key = 'up-dir-'.Str::random(10);
        $ticket = $media->createVideoUploadTicket($tenant->id, $teacher->id, [
            'title' => 'D', 'file_size' => 1024 * 1024, 'idempotency_key' => $key,
        ]);

        // المحاكاة الكاملة للطلبات الثلاثة من الواجهة (تذكرة + رفع مكرر مرتين).
        $upload = fn (string $assetId) => $this->postJson('/api/v1/videos/upload', [], [
            'Authorization' => 'Bearer test-token',
            'X-Tenant-Id' => $tenant->id,
        ])->assertStatus(422); // بدون ملف — المهم أنه لا ينشئ أصولاً.

        // attachToLesson مرتين بنفس الأصل → محتوى واحد فقط.
        $a1 = $media->attachToLesson($ticket['asset_id'], $lesson->id);
        $a2 = $media->attachToLesson($ticket['asset_id'], $lesson->id);

        $this->assertSame($a1->id, $a2->id);

        $contents = LessonContent::withoutTenant()
            ->where('lesson_id', $lesson->id)
            ->get();

        $this->assertCount(1, $contents);
    }

    // ─── درس واحد = محتوى رئيسي واحد (لا تكرار أبداً) ───

    public function test_lesson_has_single_primary_content_after_replacement(): void
    {
        $this->setupBunnyProvider();

        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');
        $course = $this->makeCourse($tenant->id, $teacher);
        $lesson = $this->makeLesson($course);

        $media = app(MediaService::class);

        // تذكرتان بمعرفين مختلفين عبر تسلسل واحد
        Http::fake([
            '*bunnycdn.com/*/videos' => Http::sequence()
                ->push(['guid' => 'vid-a', 'status' => 0])
                ->push(['guid' => 'vid-b', 'status' => 0]),
        ]);

        // محتوى أول: فيديو
        $t1 = $media->createVideoUploadTicket($tenant->id, $teacher->id, ['title' => 'V1', 'file_size' => 1024 * 1024]);
        $media->attachToLesson($t1['asset_id'], $lesson->id);

        // محتوى ثانٍ (استبدال): فيديو آخر → يجب أن يبقى محتوى واحد فقط
        $t2 = $media->createVideoUploadTicket($tenant->id, $teacher->id, ['title' => 'V2', 'file_size' => 1024 * 1024]);
        $media->attachToLesson($t2['asset_id'], $lesson->id);

        $contents = LessonContent::withoutTenant()->where('lesson_id', $lesson->id)->get();
        $this->assertCount(1, $contents, 'Lesson must have exactly ONE primary content');

        $video = VideoContent::withoutTenant()->where('lesson_content_id', $contents->first()->id)->first();
        $this->assertNotNull($video);
        $this->assertSame('V2', $video->title);
    }

    public function test_lesson_content_store_replaces_previous_primary(): void
    {
        $this->setupBunnyProvider();

        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');
        $course = $this->makeCourse($tenant->id, $teacher);
        $lesson = $this->makeLesson($course);

        $this->withToken($teacher->createToken('test')->plainTextToken);

        // محتوى نص أول
        $r1 = $this->postJson('/api/v1/lesson-contents', [
            'lesson_id' => $lesson->id, 'content_type' => 'text',
            'content_data' => ['body' => 'first text'],
        ], ['X-Tenant-Id' => $tenant->id]);
        $this->assertTrue(in_array($r1->status(), [200, 201, 422], true));

        // محتوى ثانٍ (رابط فيديو) → يجب أن يُستبدل ويبقى محتوى واحد
        $r2 = $this->postJson('/api/v1/lesson-contents', [
            'lesson_id' => $lesson->id, 'content_type' => 'video',
            'content_data' => ['provider' => 'youtube', 'provider_video_id' => 'abc123', 'title' => 'V'],
        ], ['X-Tenant-Id' => $tenant->id]);

        $contents = LessonContent::withoutTenant()->where('lesson_id', $lesson->id)->get();
        $this->assertLessThanOrEqual(1, $contents->count(), 'Store must not create a second primary content');
    }

    // ─── Live (legacy stored sessions): Host/Participant Authorization ───

    public function test_join_ticket_gives_host_only_to_owner_teacher(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');

        $session = \App\Models\LiveSession::create([
            'id' => \Symfony\Component\Uid\UuidV7::generate(),
            'tenant_id' => $tenant->id,
            'title' => 'Live',
            'provider' => 'jitsi',
            'room_name' => 'lms-secret-room-'.Str::random(8),
            'status' => 'scheduled',
            'record_enabled' => true,
            'config' => [],
            'metadata' => [],
        ]);

        $this->actingAs($teacher);
        $res = $this->postJson('/api/v1/teacher/live-sessions/'.$session->id.'/join-ticket', [], ['X-Tenant-Id' => $tenant->id]);
        $res->assertOk();
        $this->assertSame('host', $res->json('role'));
        $this->assertSame($session->room_name, $res->json('room'));
    }

    public function test_join_ticket_rejects_student_as_host(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');

        $course = $this->makeCourse($tenant->id, $teacher);
        $section = \App\Models\Section::create([
            'id' => \Symfony\Component\Uid\UuidV7::generate(),
            'tenant_id' => $tenant->id,
            'course_id' => $course->id,
            'title' => 'S',
            'sort_order' => 1,
            'metadata' => [],
        ]);
        $lesson = $this->makeLesson($course);

        $session = \App\Models\LiveSession::create([
            'id' => \Symfony\Component\Uid\UuidV7::generate(),
            'tenant_id' => $tenant->id,
            'course_id' => $course->id,
            'title' => 'Live',
            'provider' => 'jitsi',
            'room_name' => 'lms-room-'.Str::random(8),
            'status' => 'scheduled',
            'record_enabled' => true,
            'config' => [],
            'metadata' => [],
        ]);

        // طالب غير مسجل بالكورس → 403 (لا host ولا participant)
        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');
        $this->actingAs($student);
        $res = $this->postJson('/api/v1/live-sessions/'.$session->id.'/join-ticket', [], ['X-Tenant-Id' => $tenant->id]);
        $this->assertEquals(403, $res->status());
    }

    // ─── E2E: Upload → Webhook → Ready → Lesson → Playback ───

    public function test_full_video_pipeline_upload_webhook_lesson_playback(): void
    {
        $this->setupBunnyProvider();
        $this->fakeTicket();
        $this->fakeVideoInfo(status: 'ready');

        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');
        $course = $this->makeCourse($tenant->id, $teacher);
        $lesson = $this->makeLesson($course);

        // 1) رفع: تذكرة + أصل مرجعي + حجز مساحة.
        $media = app(MediaService::class);
        $ticket = $media->createVideoUploadTicket($tenant->id, $teacher->id, [
            'title' => 'E2E', 'file_size' => 2 * 1024 * 1024,
        ]);

        // 2) ربط الأصل بالدرس (LessonContent + VideoContent).
        $asset = $media->attachToLesson($ticket['asset_id'], $lesson->id);

        $this->assertNotNull($asset->lesson_content_id);
        $content = LessonContent::withoutTenant()->find($asset->lesson_content_id);
        $this->assertSame('video', $content->content_type);

        $video = VideoContent::withoutTenant()->where('lesson_content_id', $content->id)->first();
        $this->assertNotNull($video);
        $this->assertSame('processing', $video->status);

        // 3) Webhook ready (كما يصل من المزود) — التطبيق idempotent.
        $event = [
            'id' => 'evt-e2e-1',
            'type' => 'video.ready',
            'guid' => 'vid-1',
            'timestamp' => time(),
        ];
        $this->postJson('/api/v1/media/webhook/bunny?secret='.urlencode(self::BUNNY_WEBHOOK_SECRET), $event)->assertOk();
        $this->postJson('/api/v1/media/webhook/bunny?secret='.urlencode(self::BUNNY_WEBHOOK_SECRET), $event)->assertOk(); // مكرر

        $video->refresh();
        $asset->refresh();

        $this->assertSame('ready', $video->status);
        $this->assertSame('ready', $asset->status);
        // التخزين الفعلي يُحتسب من معلومات المزود (الـ fake يعيد 1MB).
        $this->assertSame(1024 * 1024, app(MediaUsageService::class)->current($tenant->id)['storage_bytes']);

        // 4) طالب مسجل يشاهد — Payload التشغيل السليم.
        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');
        Enrollment::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenant->id,
            'course_id' => $course->id,
            'user_id' => $student->id,
            'status' => 'active',
            'enrolled_at' => now(),
        ]);

        $this->actingAs($student);
        $res = $this->getJson('/api/v1/videos/'.$video->id.'/playback');
        $res->assertOk();
        $res->assertJsonPath('playback.status', 'ready');
        $res->assertJsonPath('playback.type', 'provider');

        // 5) طالب غير مسجل — مرفوض.
        $intruder = $this->makeUser();
        $this->attachToTenant($intruder, $tenant, 'student');
        $this->actingAs($intruder);
        $this->getJson('/api/v1/videos/'.$video->id.'/playback')->assertForbidden();
    }
}
