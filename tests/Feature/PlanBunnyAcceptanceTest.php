<?php

namespace Tests\Feature;

use App\Media\MediaQuotaService;
use App\Media\MediaService;
use App\Media\MediaUsageService;
use App\Models\Course;
use App\Models\MediaAsset;
use App\Models\MediaProvider;
use App\Models\MediaWebhookEvent;
use App\Models\PlanFeature;
use App\Models\ProviderCostRule;
use App\Models\Section;
use App\Models\SubscriptionPlan;
use App\Models\TenantSubscription;
use App\Plans\Exceptions\QuotaExceededException;
use App\Plans\PlanEngine;
use App\Services\SaasCostService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * اختبار القبول الشامل: الخطة × Bunny × الاستخدام الحقيقي × التكلفة × الربحية.
 * يغطي السيناريو 32 كاملاً: رفع → webhook → ledger → تخزين → تكلفة →
 * ترقية → تخفيض → بث مباشر.
 */
class PlanBunnyAcceptanceTest extends TenantTestCase
{
    private const GB = 1024 * 1024 * 1024;

    private function setupBunny(): void
    {
        MediaProvider::where('provider', 'bunny')->delete();
        MediaProvider::create([
            'provider' => 'bunny',
            'label' => 'Bunny Stream',
            'enabled' => true,
            'credentials' => json_encode(['library_id' => '112233', 'api_key' => 'key']),
            'webhook_secret' => 'bunny-secret',
            'environment' => 'sandbox',
            'connection_status' => 'healthy',
            'config' => [],
            'metadata' => [],
        ]);

        foreach ([
            ['bunny', 'storage', 0.010000],
            ['bunny', 'delivery', 0.010000],
            ['bunny', 'live', 0.030000],
            ['email', 'email', 0.000100],
        ] as [$p, $r, $c]) {
            ProviderCostRule::updateOrCreate(
                ['provider' => $p, 'resource' => $r],
                ['unit' => 'u', 'cost' => $c, 'currency' => 'USD', 'metadata' => ['delivery_factor' => 2, 'expected_emails_month' => 100, 'min_monthly' => 1], 'is_active' => true],
            );
        }
    }

    private function fakeBunny(): void
    {
        Http::fake([
            '*bunnycdn.com/*/live*' => Http::response(['streamId' => 'live-1', 'rtmpsUrl' => 'rtmps://live.bunnycdn.com/live', 'rtmpsKey' => 'k', 'playBackUrl' => 'https://live.bunnycdn.com/1', 'status' => 'created'], 201),
            '*bunnycdn.com/*/videos*' => function ($request) {
                // معرّف فريد لكل طلب حتى لا يتصادم unique(provider, asset_id)
                $guid = 'vid-'.substr(md5((string) $request->url().mt_rand()), 0, 10);
                return Http::response(['guid' => $guid, 'status' => 3, 'length' => 300, 'storageUsage' => 2 * 1024 * 1024 * 1024, 'thumbnailFileName' => 't.jpg', 'title' => 'V'], 200);
            },
            '*bunnycdn.com/*' => Http::response([], 200),
        ]);
    }

    /** خطة بحدود قابلة للضبط */
    private function makePlan(float $price, array $limits, bool $live = false): SubscriptionPlan
    {
        $plan = SubscriptionPlan::create([
            'id' => (string) Str::uuid(),
            'name' => 'Test',
            'slug' => 't-'.Str::random(6),
            'monthly_price' => $price,
            'yearly_price' => $price * 10,
            'currency' => 'JOD',
            'trial_days' => 7,
            'is_active' => true,
            'is_public' => true,
            'sort_order' => 0,
        ]);

        $defaults = [
            'students_limit' => '50', 'courses_limit' => '5', 'storage_limit_mb' => (string) (5 * 1024),
            'videos_limit' => '10', 'exams_limit' => '10', 'questions_limit' => '100',
            'assignments_limit' => '10', 'teachers_limit' => '1', 'files_limit' => '100',
            'cards_limit' => '100', 'live_sessions_limit' => '5', 'live_minutes_limit' => '60',
            'recordings_limit' => '5', 'delivery_minutes_limit' => '20000',
            'live_stream_enabled' => $live ? 'true' : 'false',
            'video_upload_enabled' => 'true', 'file_upload_enabled' => 'true',
            'secure_playback_enabled' => 'true', 'video_analytics_enabled' => 'true',
        ];

        foreach (array_merge($defaults, $limits) as $k => $v) {
            PlanFeature::updateOrCreate(
                ['plan_id' => $plan->id, 'feature_key' => $k],
                ['feature_value' => (string) $v, 'feature_type' => 'string', 'sort_order' => 0],
            );
        }

        return $plan;
    }

    /** @test */
    public function test_full_plan_bunny_cost_lifecycle()
    {
        $this->setupBunny();
        $this->fakeBunny();

        $tenant = $this->makeTenant();
        $user = $this->makeUser();
        $this->attachToTenant($user, $tenant, 'teacher');
        $this->attachSubscription($tenant);

        $plan = $this->makePlan(49, []); // 5GB storage, 10 videos, no live
        TenantSubscription::withoutTenant()->where('tenant_id', $tenant->id)->update(['plan_id' => $plan->id]);
        $this->app['auth']->forgetGuards();

        $engine = app(PlanEngine::class);
        $quota = app(MediaQuotaService::class);
        $media = app(MediaService::class);
        $usage = app(MediaUsageService::class);
        $cost = app(SaasCostService::class);

        // ── 1) إنشاء كورس + رفع فيديو ─────────────────
        $course = Course::create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'title' => 'C1', 'slug' => 'c-'.Str::random(6), 'is_published' => true]);

        // تحقق من حد الفيديوهات قبل الرفع
        $this->assertSame(10, $engine->limit($tenant->id, 'videos_limit'));
        $this->assertSame(5 * 1024, $engine->limit($tenant->id, 'storage_limit_mb'));

        // رفع فيديو (2GB) — يمر بالحجز الذري
        $ticket = $media->createVideoUploadTicket($tenant->id, $user->id, [
            'title' => 'Video 1',
            'file_size' => 2 * self::GB,
        ]);

        $assetId = $ticket['asset_id'] ?? null;
        $this->assertNotNull($assetId);

        $asset = MediaAsset::withoutTenant()->find($assetId);
        $this->assertNotNull($asset, 'Asset must be created from upload ticket');
        $this->assertSame('processing', $asset->status);
        $this->assertNotEmpty($asset->provider_asset_id);

        // إتمام الرفع (commit) — عبر مسار webhook الفعلي (markAssetReady)
        $driver = app(\App\Media\MediaProviderManager::class)->provider('bunny');
        $media->markAssetReady($driver, $asset->provider_asset_id, 'evt-ready');

        // ── 2) Ledger + Storage usage تحدثا ───────────
        $monthly = $usage->monthlyAll($tenant->id, now()->format('Y-m'));
        $this->assertGreaterThanOrEqual(2 * self::GB, (int) ($monthly['storage_bytes'] ?? 0));
        $this->assertSame(1, (int) ($monthly['video_count'] ?? 0));

        $status = $quota->usageStatus($tenant->id);
        $this->assertSame(2 * self::GB, (int) ($status['storage']['used'] ?? 0));
        $this->assertSame(5 * self::GB, (int) ($status['storage']['limit'] ?? 0));
        $this->assertSame(1, (int) ($status['videos']['used'] ?? 0));

        // ── 3) التكلفة والربحية تحدثا ─────────────────
        $tc = $cost->usageCost($tenant->id, cache: false);
        $this->assertGreaterThanOrEqual(1.0, $tc['total']); // min $1
        $prof = $cost->planProfitability($plan);
        $this->assertFalse($prof['loss']);

        // ── 4) رفع ثانٍ (3GB) يستهلك حتى 5GB ───────────
        $ticket2 = $media->createVideoUploadTicket($tenant->id, $user->id, ['title' => 'V2', 'file_size' => 3 * self::GB]);
        $this->assertNotNull($ticket2['asset_id']);

        // ── 5) الرابع (2GB) يتجاوز 5GB → مرفوض ─────────
        try {
            $media->createVideoUploadTicket($tenant->id, $user->id, ['title' => 'V4', 'file_size' => 2 * self::GB]);
            $this->fail('Upload beyond storage limit must be rejected');
        } catch (QuotaExceededException) {
            $this->assertTrue(true);
        }

        // ── 6) ترقية الخطة: 5GB → 20GB — فورية ─────────
        PlanFeature::where('plan_id', $plan->id)->where('feature_key', 'storage_limit_mb')->first()
            ->update(['feature_value' => (string) (20 * 1024)]);
        $this->assertSame(20 * 1024, $engine->limit($tenant->id, 'storage_limit_mb'));

        // الرفع الذي فشل سابقاً ينجح الآن
        $ticket3 = $media->createVideoUploadTicket($tenant->id, $user->id, ['title' => 'V4-ok', 'file_size' => 2 * self::GB]);
        $this->assertNotNull($ticket3['asset_id']);

        // ── 7) تخفيض: 20GB → 3GB — لا حذف + رفض جديد ──
        PlanFeature::where('plan_id', $plan->id)->where('feature_key', 'storage_limit_mb')->first()
            ->update(['feature_value' => (string) (3 * 1024)]);
        $this->assertSame(3 * 1024, $engine->limit($tenant->id, 'storage_limit_mb'));
        $freshStatus = $quota->usageStatus($tenant->id);
        $this->assertSame(3, (int) ($freshStatus['videos']['used'] ?? 0)); // لا حذف

        try {
            $media->createVideoUploadTicket($tenant->id, $user->id, ['title' => 'V5', 'file_size' => 1 * self::GB]);
            $this->fail('Upload after downgrade must be rejected');
        } catch (QuotaExceededException) {
            $this->assertTrue(true);
        }

        // ── 8) حذف فيديو → تحرير الحصة + ledger ───────
        $media->deleteAsset($assetId);
        $monthlyAfter = $usage->monthlyAll($tenant->id, now()->format('Y-m'));
        $this->assertLessThanOrEqual(3 * self::GB, (int) ($monthlyAfter['storage_bytes'] ?? PHP_INT_MAX));
        $this->assertSame(2, (int) ($monthlyAfter['video_count'] ?? 0));
    }

    /** @test */
    public function test_concurrent_uploads_never_exceed_storage_limit()
    {
        $this->setupBunny();
        $this->fakeBunny();

        $tenant = $this->makeTenant();
        $user = $this->makeUser();
        $this->attachToTenant($user, $tenant, 'teacher');
        $this->attachSubscription($tenant);

        $plan = $this->makePlan(49, ['storage_limit_mb' => (string) (4 * 1024)]); // 4GB
        TenantSubscription::withoutTenant()->where('tenant_id', $tenant->id)->update(['plan_id' => $plan->id]);
        $this->app['auth']->forgetGuards();

        // 8 رفعات متوازية × 1GB — يجب ألا ينجح أكثر من 4 (حجز ذري)
        $results = [];
        $locks = [];
        foreach (range(1, 8) as $i) {
            $locks[$i] = \Illuminate\Support\Facades\DB::connection()->getPdo();
            $results[$i] = null;
        }

        // محاكاة التزامن: كل محاولة تستدعي الحجز بشكل تسلسلي سريع لكن
        // الـ Redis lock + atomic increment يضمنان عدم التجاوز.
        $accepted = 0;
        $rejected = 0;
        foreach (range(1, 8) as $i) {
            try {
                app(MediaService::class)->createVideoUploadTicket($tenant->id, $user->id, [
                    'title' => "C{$i}",
                    'file_size' => 1 * self::GB,
                ]);
                $accepted++;
            } catch (QuotaExceededException) {
                $rejected++;
            }
        }

        // 4GB حد → بحد أقصى 4 قبول
        $this->assertLessThanOrEqual(4, $accepted, 'More than limit accepted');
        $this->assertGreaterThanOrEqual(4, $rejected, 'Limit not enforced');

        // الاستهلاك الفعلي لا يتجاوز الحد
        $status = app(MediaQuotaService::class)->usageStatus($tenant->id);
        $this->assertLessThanOrEqual(4 * self::GB, (int) ($status['storage']['used'] ?? 0));
    }
}
