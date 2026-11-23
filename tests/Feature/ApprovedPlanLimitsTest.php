<?php

namespace Tests\Feature;

use App\Media\MediaQuotaService;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonContent;
use App\Models\PlanFeature;
use App\Models\Section;
use App\Models\SubscriptionPlan;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Models\VideoContent;
use App\Plans\PlanEngine;
use Illuminate\Support\Str;

/**
 * الخطط المعتمدة (2026-11) عبر ترحيل `apply_approved_plan_limits`:
 *
 *   | الخطة       | السعر | التخزين   | الطلاب | الكورسات | الفيديوهات     |
 *   | الانطلاقة   | 29    | 700 GB    | 50     | 3        | بلا حد عددي    |
 *   | المحترف     | 59    | 1.5 TB    | 250    | 7        | بلا حد عددي    |
 *   | المتقدم     | 89    | 3 TB      | 1000   | 20       | بلا حد عددي    |
 *
 * التشغيل الآمن (secure_playback_enabled) مفعّل في الثلاث، والأسعار لا تُلمس
 * في الترحيل إطلاقاً (تُنشر حصراً عبر FreemiusPriceSyncService).
 */
class ApprovedPlanLimitsTest extends TenantTestCase
{
    private const MIGRATION = 'migrations/2026_11_23_000001_apply_approved_plan_limits.php';

    /** خطة بإعداد "ما قبل الترحيل" — لمحاكاة قيم الإنتاج القديمة. */
    private function legacyPlan(string $slug, float $monthly, float $yearly): SubscriptionPlan
    {
        $plan = SubscriptionPlan::create([
            'id' => (string) Str::uuid(),
            'name' => $slug,
            'slug' => $slug,
            'monthly_price' => $monthly,
            'yearly_price' => $yearly,
            'currency' => 'USD',
            'trial_days' => 7,
            'is_active' => true,
            'is_public' => true,
            'sort_order' => 0,
        ]);

        foreach ([
            'students_limit' => '50',
            'courses_limit' => '3',
            'storage_limit_mb' => '15360',   // 15 GB قديمة
            'videos_limit' => '100',         // حدّ عددي قديم يجب أن يزول
            'live_stream_enabled' => 'false',
            'video_upload_enabled' => 'true',
        ] as $key => $value) {
            PlanFeature::create([
                'id' => (string) Str::uuid(),
                'plan_id' => $plan->id,
                'feature_key' => $key,
                'feature_value' => $value,
                'feature_type' => 'string',
                'sort_order' => 0,
            ]);
        }

        return $plan;
    }

    /** @return array<string, SubscriptionPlan> */
    private function threePlans(): array
    {
        return [
            'starter' => $this->legacyPlan('starter', 29.00, 299.00),
            'professional' => $this->legacyPlan('professional', 49.00, 499.00),
            'enterprise' => $this->legacyPlan('enterprise', 79.00, 799.00),
        ];
    }

    private function runMigration(): void
    {
        $migration = require database_path(self::MIGRATION);
        $migration->up();
    }

    private function feature(SubscriptionPlan $plan, string $key): ?string
    {
        return $plan->features()->where('feature_key', $key)->value('feature_value');
    }

    /** @test */
    public function test_migration_applies_approved_limits_to_the_three_plans(): void
    {
        $plans = $this->threePlans();

        $this->runMigration();

        $expected = [
            'starter' => ['716800', '50', '3'],
            'professional' => ['1572864', '250', '7'],
            'enterprise' => ['3145728', '1000', '20'],
        ];

        foreach ($expected as $slug => [$storage, $students, $courses]) {
            $plan = $plans[$slug]->fresh();

            $this->assertSame($storage, $this->feature($plan, 'storage_limit_mb'), "storage {$slug}");
            $this->assertSame($students, $this->feature($plan, 'students_limit'), "students {$slug}");
            $this->assertSame($courses, $this->feature($plan, 'courses_limit'), "courses {$slug}");

            // الفيديوهات: لا حدّ عددي — الضابط الوحيد هو مساحة التخزين.
            $this->assertSame('unlimited', $this->feature($plan, 'videos_limit'), "videos {$slug}");

            // التشغيل الآمن مفعّل في كل الخطط الثلاث.
            $this->assertSame('true', $this->feature($plan, 'secure_playback_enabled'), "secure {$slug}");
        }
    }

    /** @test */
    public function test_migration_never_touches_prices(): void
    {
        $plans = $this->threePlans();

        $this->runMigration();

        // الأسعار لا ينشرها هذا الترحيل — تُنشر حصراً عبر FreemiusPriceSyncService.
        $this->assertSame('29.00', $plans['starter']->fresh()->monthly_price);
        $this->assertSame('49.00', $plans['professional']->fresh()->monthly_price);
        $this->assertSame('79.00', $plans['enterprise']->fresh()->monthly_price);
        $this->assertSame('499.00', $plans['professional']->fresh()->yearly_price);
    }

    /** @test */
    public function test_videos_are_unlimited_and_storage_is_the_only_cap(): void
    {
        $plans = $this->threePlans();
        $this->runMigration();

        $tenant = $this->makeTenant();
        $this->attachToTenant($this->makeUser(), $tenant, 'teacher');
        TenantSubscription::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'plan_id' => $plans['starter']->id,
            'status' => 'trialing',
            'billing_cycle' => 'monthly',
            'starts_at' => now(),
            'trial_ends_at' => now()->addDays(7),
            'auto_renew' => true,
        ]);
        PlanEngine::bumpVersion();

        $engine = app(PlanEngine::class);

        // بلا حدّ عددي للفيديوهات…
        $this->assertNull($engine->limit($tenant->id, 'videos_limit'));
        // …لكن حد التخزين الفعلي قائم (700 GB = 716800 MB).
        $this->assertSame(716800, $engine->limit($tenant->id, 'storage_limit_mb'));
        // التشغيل الآمن مفعّل.
        $this->assertTrue($engine->hasFeature($tenant->id, 'secure_playback_enabled'));

        // الرفع مسموح رقمياً بلا سقف عدد الفيديوهات.
        app(MediaQuotaService::class)->assertCanUploadVideo($tenant->id);
        $this->assertTrue(true);
    }

    /** @test */
    public function test_public_plans_payload_exposes_approved_numbers(): void
    {
        $this->threePlans();
        $this->runMigration();

        $plans = collect($this->getJson('/api/v1/public/plans')->assertOk()->json('plans'));

        $this->assertEquals([29, 49, 79], $plans->pluck('monthly_price')->all());

        $display = fn (array $plan, string $key) => collect($plan['features'])->firstWhere('key', $key)['display_en'] ?? null;

        $starter = $plans->firstWhere('monthly_price', 29.0);
        $this->assertSame('700 GB', $display($starter, 'storage_limit_mb'));
        $this->assertSame('No numeric limit', $display($starter, 'videos_limit'));
        $this->assertSame('Enabled', $display($starter, 'secure_playback_enabled'));

        $pro = $plans->firstWhere('monthly_price', 49.0);
        $this->assertSame('1.5 TB', $display($pro, 'storage_limit_mb'));

        $ent = $plans->firstWhere('monthly_price', 79.0);
        $this->assertSame('3 TB', $display($ent, 'storage_limit_mb'));
    }

    /** @test */
    public function test_migration_leaves_existing_subscriptions_and_tenants_untouched(): void
    {
        $plans = $this->threePlans();

        $tenant = $this->makeTenant();
        $this->attachToTenant($this->makeUser(), $tenant, 'teacher');

        $sub = TenantSubscription::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'plan_id' => $plans['professional']->id,
            'status' => 'trialing',
            'billing_cycle' => 'yearly',
            'starts_at' => now()->subDays(3),
            'trial_ends_at' => now()->addDays(4),
            'auto_renew' => true,
        ]);

        $subBefore = [
            'plan_id' => $sub->plan_id,
            'status' => $sub->status,
            'billing_cycle' => $sub->billing_cycle,
            'starts_at' => $sub->starts_at->toIso8601String(),
            'trial_ends_at' => $sub->trial_ends_at->toIso8601String(),
            'auto_renew' => $sub->auto_renew,
        ];
        $tenantBefore = $tenant->only(['name', 'slug', 'status', 'storage_limit_mb', 'max_students']);

        $this->runMigration();

        // بيانات المشتركين والمستأجرين لا تتغيّر بالترحيل — تُعدَّل الميزات فقط.
        $subAfter = $sub->fresh();
        $this->assertSame($subBefore, [
            'plan_id' => $subAfter->plan_id,
            'status' => $subAfter->status,
            'billing_cycle' => $subAfter->billing_cycle,
            'starts_at' => $subAfter->starts_at->toIso8601String(),
            'trial_ends_at' => $subAfter->trial_ends_at->toIso8601String(),
            'auto_renew' => $subAfter->auto_renew,
        ]);
        $this->assertSame($tenantBefore, $tenant->fresh()->only(array_keys($tenantBefore)));
    }

    /** @test */
    public function test_secure_playback_disabled_blocks_playback(): void
    {
        $tenant = $this->makeTenant();
        $sub = $this->attachSubscription($tenant);

        // أوقف التشغيل الآمن لهذه الخطة صراحةً.
        PlanFeature::updateOrCreate(
            ['plan_id' => $sub->plan_id, 'feature_key' => 'secure_playback_enabled'],
            ['feature_value' => 'false', 'feature_type' => 'string'],
        );
        PlanEngine::bumpVersion();

        ['course' => $course, 'video' => $video] = $this->makeCourseTree($tenant);

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

        $headers = $this->authHeaders($student, $tenant) + ['X-Device-Id' => 'device-sec'];

        $this->getJson("/api/v1/videos/{$video->id}/playback", $headers)
            ->assertStatus(403)
            ->assertJsonPath('code', 'FEATURE_UNAVAILABLE');
    }

    /** @test */
    public function test_secure_playback_enabled_allows_enrolled_student_playback(): void
    {
        $tenant = $this->makeTenant();
        $sub = $this->attachSubscription($tenant);
        PlanFeature::updateOrCreate(
            ['plan_id' => $sub->plan_id, 'feature_key' => 'secure_playback_enabled'],
            ['feature_value' => 'true', 'feature_type' => 'string'],
        );
        PlanEngine::bumpVersion();

        ['course' => $course, 'video' => $video] = $this->makeCourseTree($tenant);

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

        $headers = $this->authHeaders($student, $tenant) + ['X-Device-Id' => 'device-ok'];

        $this->getJson("/api/v1/videos/{$video->id}/playback", $headers)->assertOk();
    }

    /** شجرة كورس/درس/فيديو — مطابقة لما يستخدمه مسار التشغيل المحمي. */
    private function makeCourseTree(\App\Models\Tenant $tenant): array
    {
        $course = Course::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'title' => 'Approved Course',
            'slug' => 'appr-'.Str::random(6),
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
            'title' => 'Protected',
        ]);

        return compact('course', 'section', 'lesson', 'video');
    }
}
