<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\MediaProvider;
use App\Models\PlanFeature;
use App\Models\ProviderCostRule;
use App\Models\Section;
use App\Models\SubscriptionPlan;
use App\Services\SaasCostService;
use Illuminate\Support\Str;

/**
 * اختبارات SaaS Cost & Profit Engine: حساب التكلفة، الربحية، تحذير
 * الخطة الخاسرة، وربط كل شيء بقاعدة البيانات.
 */
class CostEngineTest extends TenantTestCase
{
    private const GB = 1024 * 1024 * 1024;

    private function setupProvider(): void
    {
        MediaProvider::where('provider', 'bunny')->delete();
        MediaProvider::create([
            'provider' => 'bunny',
            'label' => 'Bunny Stream',
            'enabled' => true,
            'credentials' => json_encode([
                'library_id' => '112233',
                'api_key' => 'test-key',
            ]),
            'webhook_secret' => 'secret',
            'environment' => 'sandbox',
            'connection_status' => 'healthy',
            'config' => [],
            'metadata' => [],
        ]);

        // أسعار من قاعدة البيانات فقط (لا hardcode)
        foreach ([
            ['bunny', 'storage', 'GB', 0.010000],
            ['bunny', 'delivery', 'GB', 0.005000],
            ['bunny', 'encoding', 'video', 0.000000],
            ['bunny', 'live', 'hour', 0.030000],
            ['email', 'email', 'email', 0.000100],
        ] as [$provider, $resource, $unit, $cost]) {
            ProviderCostRule::updateOrCreate(
                ['provider' => $provider, 'resource' => $resource],
                ['unit' => $unit, 'cost' => $cost, 'currency' => 'USD', 'metadata' => ['delivery_factor' => 2, 'expected_emails_month' => 100], 'is_active' => true],
            );
        }
    }

    private function makePlanWithLimits(float $price, array $limits): SubscriptionPlan
    {
        $plan = SubscriptionPlan::create([
            'id' => (string) Str::uuid(),
            'name' => 'Test Plan '.Str::random(4),
            'slug' => 'test-'.Str::random(6),
            'monthly_price' => $price,
            'yearly_price' => $price * 10,
            'currency' => 'JOD',
            'trial_days' => 7,
            'is_active' => true,
            'is_public' => true,
            'sort_order' => 0,
        ]);

        foreach ($limits as $key => $value) {
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

    /** @test */
    public function test_usage_cost_is_computed_from_database_rules()
    {
        $this->setupProvider();
        $tenant = $this->makeTenant();

        // استهلاك: 5GB تخزين + 60 دقيقة بث
        app(\App\Media\MediaUsageService::class)->applyDelta($tenant->id, 'storage_bytes', 5 * self::GB, 'upload');
        app(\App\Media\MediaUsageService::class)->applyDelta($tenant->id, 'live_minutes', 60, 'live.start');

        $cost = app(SaasCostService::class)->usageCost($tenant->id, cache: false);

        // storage: 5GB × 0.01 = 0.05 | delivery: 5×2×0.005 = 0.05 | live: 1h × 0.03 = 0.03 | email: 100 × 0.0001 = 0.01
        $this->assertSame(0.05, $cost['breakdown']['storage']);
        $this->assertSame(0.05, $cost['breakdown']['delivery']);
        $this->assertSame(0.03, $cost['breakdown']['live']);
        $this->assertSame(0.01, $cost['breakdown']['email']);
        $this->assertSame(0.14, round($cost['total'], 4));
        $this->assertSame('bunny', $cost['provider']);
    }

    /** @test */
    public function test_plan_expected_cost_comes_from_plan_limits()
    {
        $this->setupProvider();

        // خطة: 10GB تخزين + 20 فيديو + 60 دقيقة بث مفعل — سعر 49
        $plan = $this->makePlanWithLimits(49, [
            'storage_limit_mb' => '10240',
            'videos_limit' => '20',
            'live_stream_enabled' => 'true',
            'live_minutes_limit' => '60',
        ]);

        $cost = app(SaasCostService::class)->planExpectedCost($plan);

        // storage: 10×0.01 = 0.10 | delivery: 10×2×0.005 = 0.10 | live: 1h×0.03 = 0.03 | email: 0.01
        $this->assertSame(0.24, round($cost, 4));

        // الخطة مربحة: 49 JOD >> 0.24 USD
        $p = app(SaasCostService::class)->planProfitability($plan);
        $this->assertFalse($p['loss']);
        $this->assertGreaterThan(99, $p['margin_percentage']);
    }

    /** @test */
    public function test_loss_making_plan_is_detected()
    {
        $this->setupProvider();

        // خطة رخيصة بحدود ضخمة (500GB تخزين بسعر 1) → خاسرة
        $plan = $this->makePlanWithLimits(1, [
            'storage_limit_mb' => '512000',
            'videos_limit' => '5000',
            'live_stream_enabled' => 'true',
            'live_minutes_limit' => '60000',
        ]);

        $p = app(SaasCostService::class)->planProfitability($plan);

        $this->assertTrue($p['loss']);
        $this->assertLessThan(0, $p['expected_profit']);

        // التحذير يُبنى للواجهة
        $warning = app(SaasCostService::class)->profitabilityWarning($plan);
        $this->assertNotNull($warning);
        $this->assertTrue($warning['loss']);
        $this->assertStringContainsString('خاسرة', $warning['message']);
    }

    /** @test */
    public function test_plan_update_returns_profitability_warning_when_loss()
    {
        $this->setupProvider();
        $admin = $this->makeUser(['is_super_admin' => true]);

        $plan = $this->makePlanWithLimits(49, [
            'storage_limit_mb' => '10240',
            'videos_limit' => '20',
        ]);

        // تعديل الخطة بسعر ثابت — الاستجابة لا تمنع التعديل أبداً
        $response = $this->withHeader('Authorization', 'Bearer '.$admin->createToken('test')->plainTextToken)
            ->putJson("/api/v1/admin/plans/{$plan->id}", [
                'name' => $plan->name,
                'description' => 'Plan',
                'monthly_price' => 49,
                'currency' => 'JOD',
                'is_active' => true,
            ]);

        $response->assertOk();

        $this->assertDatabaseHas('plan_features', [
            'plan_id' => $plan->id,
            'feature_key' => 'storage_limit_mb',
            'feature_value' => '10240',
        ]);
    }

    /** @test */
    public function test_persist_usage_cost_writes_ledger_rows()
    {
        $this->setupProvider();
        $tenant = $this->makeTenant();

        app(\App\Media\MediaUsageService::class)->applyDelta($tenant->id, 'storage_bytes', 2 * self::GB, 'upload');

        $cost = app(SaasCostService::class)->persistUsageCost($tenant->id);

        $this->assertDatabaseHas('provider_usage_costs', [
            'tenant_id' => $tenant->id,
            'provider' => 'bunny',
            'resource' => 'storage',
            'year_month' => now()->format('Y-m'),
        ]);

        $this->assertSame(0.02, round((float) $cost['breakdown']['storage'], 4));
    }

    /** @test */
    public function test_plan_profitability_snapshot_is_persisted()
    {
        $this->setupProvider();

        $plan = $this->makePlanWithLimits(49, [
            'storage_limit_mb' => '10240',
            'videos_limit' => '20',
        ]);

        $count = app(SaasCostService::class)->persistPlanProfitability();

        $this->assertGreaterThanOrEqual(2, $count);
        $this->assertDatabaseHas('plan_profitability', [
            'plan_id' => $plan->id,
            'billing_cycle' => 'monthly',
        ]);
    }

    /** @test */
    public function test_student_without_enrollment_cannot_access_video()
    {
        $this->setupProvider();

        $tenant = $this->makeTenant();
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');
        $this->attachSubscription($tenant);

        $course = Course::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'title' => 'Course',
            'slug' => 'course-'.Str::random(6),
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

        $lessonContent = \App\Models\LessonContent::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'lesson_id' => $lesson->id,
            'content_type' => 'video',
            'sort_order' => 0,
        ]);

        $video = \App\Models\VideoContent::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'lesson_content_id' => $lessonContent->id,
            'lesson_id' => $lesson->id,
            'provider' => 'bunny',
            'provider_video_id' => 'bunny-video-1',
            'is_uploaded' => false,
            'status' => 'ready',
            'title' => 'Video',
        ]);

        // طالب غير مسجل بالكورس → ممنوع من التشغيل
        $stranger = $this->makeUser();
        $this->attachToTenant($stranger, $tenant, 'student');

        $this->app['auth']->forgetGuards();
        $response = $this->withHeader('Authorization', 'Bearer '.$stranger->createToken('test')->plainTextToken)
            ->getJson("/api/v1/videos/{$video->id}/playback");
        $response->assertStatus(403);

        // الطالب المسجل → يسمح له
        $enrolled = $this->makeUser();
        $this->attachToTenant($enrolled, $tenant, 'student');
        \App\Models\Enrollment::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'course_id' => $course->id,
            'user_id' => $enrolled->id,
            'status' => 'active',
            'enrolled_at' => now(),
            'metadata' => [],
        ]);

        $can = app(\App\Services\VideoProtectionService::class)->canAccess($video, $enrolled);
        $this->assertTrue($can, 'canAccess should be true for enrolled student');

        $this->app['auth']->forgetGuards();
        $token = $enrolled->createToken('test')->plainTextToken;
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->withHeader('X-Tenant-Id', $tenant->id)
            ->getJson("/api/v1/videos/{$video->id}/playback");
        $response->assertOk();
    }
}
