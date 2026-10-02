<?php

namespace Tests\Feature;

use App\Media\MediaService;
use App\Models\Course;
use App\Models\FileContent;
use App\Models\Lesson;
use App\Models\LessonContent;
use App\Models\MediaAsset;
use App\Models\PlanFeature;
use App\Models\PlatformSetting;
use App\Models\Section;
use App\Services\FileProtectionService;
use App\Services\PayPalGateway;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use ReflectionMethod;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Uid\UuidV7;

/**
 * Regression tests for the security-hardening pass:
 *  - cross-tenant media asset takeover
 *  - cross-tenant private file paths
 *  - admin-only plan CRUD
 *  - OAuth state signing (no unsigned role=admin)
 *  - PayPal webhook cert SSRF allowlist
 *  - upload abuse gates
 *  - CORS allowlist
 *  - mass assignment on the profile endpoint
 *  - login throttle + uniform error message
 */
class SecurityHardeningTest extends TenantTestCase
{
    private function makeCourse(string $tenantId): Course
    {
        return Course::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenantId,
            'title' => 'Security Course',
            'slug' => 'sec-'.Str::random(6),
            'is_published' => true,
            'sort_order' => 0,
        ]);
    }

    private function makeLesson(string $tenantId): Lesson
    {
        $course = $this->makeCourse($tenantId);
        $section = Section::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenantId,
            'course_id' => $course->id,
            'title' => 'S1',
            'sort_order' => 0,
        ]);

        return Lesson::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenantId,
            'section_id' => $section->id,
            'title' => 'L1',
            'sort_order' => 0,
        ]);
    }

    private function makeMediaAsset(string $tenantId, array $overrides = []): MediaAsset
    {
        return MediaAsset::withoutTenant()->create(array_merge([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenantId,
            'provider' => 'bunny',
            'provider_asset_id' => 'guid-'.Str::random(8),
            'kind' => 'video',
            'status' => 'processing',
            'title' => 'Asset',
            'size_bytes' => 1024,
            'storage_bytes' => 0,
            'metadata' => [],
        ], $overrides));
    }

    public function test_cross_tenant_asset_id_is_rejected_on_direct_upload(): void
    {
        Http::fake();

        $ownerA = $this->makeUser();
        $tenantA = $this->makeTenant();
        $this->attachToTenant($ownerA, $tenantA, 'owner');
        $this->attachSubscription($tenantA);

        $tenantB = $this->makeTenant();
        $foreign = $this->makeMediaAsset($tenantB->id);

        $response = $this->post('/api/v1/videos/upload', [
            'file' => UploadedFile::fake()->create('video.mp4', 100, 'video/mp4'),
            'asset_id' => $foreign->id,
        ], $this->authHeaders($ownerA, $tenantA));

        $response->assertStatus(404);
        Http::assertNothingSent();

        $this->assertNull($foreign->fresh()->lesson_content_id);
    }

    public function test_media_service_refuses_cross_tenant_attach(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();

        $asset = $this->makeMediaAsset($tenantB->id);
        $lessonA = $this->makeLesson($tenantA->id);

        $this->expectException(HttpException::class);

        app(MediaService::class)->attachToLesson($asset->id, $lessonA->id);
    }

    public function test_cross_tenant_storage_path_is_rejected(): void
    {
        $teacher = $this->makeUser();
        $tenant = $this->makeTenant();
        $this->attachToTenant($teacher, $tenant, 'teacher');
        $this->attachSubscription($tenant);
        $other = $this->makeTenant();
        $lesson = $this->makeLesson($tenant->id);

        $response = $this->postJson('/api/v1/lesson-contents', [
            'lesson_id' => $lesson->id,
            'content_type' => 'file',
            'content_data' => [
                'original_name' => 'secret.pdf',
                'storage_path' => 'lesson-files/'.$other->id.'/secret.pdf',
                'mime_type' => 'application/pdf',
                'size_bytes' => 10,
                'extension' => 'pdf',
            ],
        ], $this->authHeaders($teacher, $tenant));

        $response->assertStatus(422);
    }

    public function test_same_tenant_storage_path_is_accepted(): void
    {
        $teacher = $this->makeUser();
        $tenant = $this->makeTenant();
        $this->attachToTenant($teacher, $tenant, 'teacher');
        $this->attachSubscription($tenant);
        $lesson = $this->makeLesson($tenant->id);

        $response = $this->postJson('/api/v1/lesson-contents', [
            'lesson_id' => $lesson->id,
            'content_type' => 'file',
            'content_data' => [
                'original_name' => 'ok.pdf',
                'storage_path' => 'lesson-files/'.$tenant->id.'/ok.pdf',
                'mime_type' => 'application/pdf',
                'size_bytes' => 10,
                'extension' => 'pdf',
            ],
        ], $this->authHeaders($teacher, $tenant));

        $response->assertStatus(201);
    }

    public function test_cross_tenant_file_download_is_blocked(): void
    {
        $teacher = $this->makeUser();
        $tenant = $this->makeTenant();
        $this->attachToTenant($teacher, $tenant, 'teacher');

        $other = $this->makeTenant();
        $lesson = $this->makeLesson($tenant->id);

        $content = LessonContent::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenant->id,
            'lesson_id' => $lesson->id,
            'content_type' => 'file',
            'sort_order' => 0,
            'title' => 'file',
        ]);

        FileContent::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenant->id,
            'lesson_content_id' => $content->id,
            'original_name' => 'secret.pdf',
            'storage_path' => 'lesson-files/'.$other->id.'/secret.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 10,
            'extension' => 'pdf',
            'is_downloadable' => true,
            'metadata' => [],
        ]);

        $token = app(FileProtectionService::class)->sign($lesson->id, $teacher->id);

        $this->getJson('/api/v1/lessons/'.$lesson->id.'/file?token='.$token)
            ->assertStatus(404);
    }

    public function test_non_admin_cannot_manage_platform_plans(): void
    {
        $teacher = $this->makeUser();
        $tenant = $this->makeTenant();
        $this->attachToTenant($teacher, $tenant, 'owner');
        $this->attachSubscription($tenant);

        $this->getJson('/api/v1/admin/plans', $this->authHeaders($teacher, $tenant))
            ->assertStatus(403);
    }

    public function test_google_callback_rejects_unsigned_state(): void
    {
        $this->get('/api/v1/auth/google/callback?state=admin')
            ->assertRedirect()
            ->assertRedirectContains('error=invalid_state');
    }

    public function test_google_callback_rejects_tampered_signed_state(): void
    {
        $payload = base64_encode(json_encode(['role' => 'admin', 'exp' => time() + 600]));
        $state = rtrim(strtr($payload, '+/', '-_'), '=').'.deadbeef';

        $this->get('/api/v1/auth/google/callback?state='.urlencode($state))
            ->assertRedirect()
            ->assertRedirectContains('error=invalid_state');
    }

    public function test_google_callback_accepts_correctly_signed_state(): void
    {
        $payload = base64_encode(json_encode(['role' => 'teacher', 'exp' => time() + 600]));
        $encoded = rtrim(strtr($payload, '+/', '-_'), '=');
        $state = $encoded.'.'.hash_hmac('sha256', $encoded, (string) config('app.key'));

        // Socialite is not configured in tests: reaching `google_failed`
        // proves the state passed signature verification.
        $this->get('/api/v1/auth/google/callback?state='.urlencode($state))
            ->assertRedirect()
            ->assertRedirectContains('error=google_failed');
    }

    public function test_paypal_certificate_fetch_is_allowlisted(): void
    {
        // Stub the allowlisted host once: deny-cases must never reach HTTP.
        Http::fake(['api.paypal.com/*' => Http::response("-----BEGIN CERTIFICATE-----\nMIIB\n-----END CERTIFICATE-----", 200)]);

        $method = new ReflectionMethod(PayPalGateway::class, 'cachedCertificate');

        // Private/loopback, wrong scheme and unknown host are all rejected
        // without any outbound request (SSRF guard).
        $this->assertNull($method->invoke(null, 'https://169.254.169.254/v1/notifications/certs/CERT-x.pem'));
        $this->assertNull($method->invoke(null, 'http://api.paypal.com/v1/notifications/certs/CERT-x.pem'));
        $this->assertNull($method->invoke(null, 'https://evil.example/v1/notifications/certs/CERT-x.pem'));
        $this->assertNull($method->invoke(null, 'https://api.paypal.com/other/path/CERT-x.pem'));

        Http::assertNothingSent();

        // The real PayPal host + cert path is allowed exactly once.
        $pem = $method->invoke(null, 'https://api.paypal.com/v1/notifications/certs/CERT-test-'.uniqid().'.pem');

        $this->assertNotNull($pem);
        Http::assertSentCount(1);
    }

    public function test_unbound_upload_requires_tenant_membership(): void
    {
        $user = $this->makeUser();

        $response = $this->post('/api/v1/upload', [
            'file' => UploadedFile::fake()->image('pic.png', 10, 10),
        ], [
            'Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken,
            'Accept' => 'application/json',
        ]);

        $response->assertStatus(403);
    }

    public function test_markup_uploaded_as_text_is_rejected(): void
    {
        $teacher = $this->makeUser();
        $tenant = $this->makeTenant();
        $this->attachToTenant($teacher, $tenant, 'teacher');

        $response = $this->post('/api/v1/upload', [
            'file' => UploadedFile::fake()->createWithContent(
                'note.txt',
                '<html><body><script>alert(document.cookie)</script></body></html>'
            ),
        ], array_merge($this->authHeaders($teacher, $tenant), ['Accept' => 'application/json']));

        $response->assertStatus(422);
    }

    public function test_cors_does_not_reflect_unknown_origins(): void
    {
        $response = $this->call('OPTIONS', '/api/v1/auth/login', [], [], [], [
            'HTTP_ORIGIN' => 'https://evil.example',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ]);

        $this->assertNull($response->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_profile_update_cannot_self_promote(): void
    {
        $user = $this->makeUser();

        $this->putJson('/api/v1/user/profile', [
            'name' => 'Updated',
            'is_super_admin' => true,
        ], [
            'Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken,
            'Accept' => 'application/json',
        ])->assertOk();

        $this->assertFalse((bool) $user->fresh()->is_super_admin);
    }

    public function test_login_bruteforce_is_throttled(): void
    {
        $user = $this->makeUser(['email' => 'throttle-'.Str::random(6).'@test.local']);

        $last = null;
        for ($i = 0; $i < 7; $i++) {
            $last = $this->postJson('/api/v1/auth/login', [
                'email' => $user->email,
                'password' => 'wrong-password-'.$i,
            ]);
        }

        $last->assertStatus(429);
    }

    public function test_mobile_google_login_cannot_bypass_2fa(): void
    {
        PlatformSetting::set('student_mobile_app', true);

        $tenant = $this->makeTenant();
        $sub = $this->attachSubscription($tenant);
        PlanFeature::create([
            'id' => (string) Str::uuid(),
            'plan_id' => $sub->plan_id,
            'feature_key' => 'student_mobile_app',
            'feature_value' => 'true',
            'feature_type' => 'string',
            'sort_order' => 0,
        ]);

        $student = $this->makeUser();
        // Privilege/security flags are intentionally not mass-assignable.
        $student->forceFill([
            'two_factor_enabled' => true,
            'two_factor_secret' => Crypt::encryptString('JBSWY3DPEHPK3PXP'),
        ])->save();
        $this->attachToTenant($student, $tenant, 'student');

        Http::fake(['oauth2.googleapis.com/*' => Http::response([
            'email' => $student->email,
            'email_verified' => true,
            'name' => 'Student',
        ], 200)]);

        $this->postJson('/api/v1/mobile/auth/google', [
            'teacher_code' => $tenant->teacher_code,
            'id_token' => 'fake-google-token',
        ])
            ->assertOk()
            ->assertJsonPath('requires_2fa', true)
            ->assertJsonMissing(['token' => true]);
    }

    public function test_login_errors_do_not_disclose_account_existence(): void
    {
        $user = $this->makeUser(['email' => 'exists-'.Str::random(6).'@test.local']);

        $unknown = $this->postJson('/api/v1/auth/login', [
            'email' => 'nobody-'.Str::random(6).'@test.local',
            'password' => 'whatever-password',
        ]);

        $wrongPassword = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'whatever-password',
        ]);

        $unknown->assertStatus(401);
        $wrongPassword->assertStatus(401);
        $this->assertSame($unknown->json('message'), $wrongPassword->json('message'));
    }
}
