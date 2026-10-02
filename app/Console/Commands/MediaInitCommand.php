<?php

namespace App\Console\Commands;

use App\Models\MediaProvider;
use App\Models\PlanFeature;
use App\Models\ProviderPricing;
use App\Models\SubscriptionPlan;
use App\Plans\PlanEngine;
use Illuminate\Console\Command;
use Symfony\Component\Uid\UuidV7;

/**
 * تهيئة Media Engine (Bunny Stream — الفيديوهات المسجلة):
 *  1. إنشاء سجل المزود (bunny) إن لم يوجد.
 *  2. أسعار افتراضية قابلة للتعديل من لوحة الأدمن.
 *  3. ضمان وجود ميزات الوسائط الجديدة في الخطط النشطة (ديناميكية).
 */
class MediaInitCommand extends Command
{
    protected $signature = 'media:init {--force : إعادة إنشاء القيم الافتراضية}';

    protected $description = 'Initialize the Media Infrastructure Engine (Bunny provider, pricing, plan features).';

    public function handle(PlanEngine $engine): int
    {
        // 1) المزود.
        $provider = MediaProvider::query()->where('provider', 'bunny')->first();

        if (! $provider) {
            MediaProvider::create([
                'provider' => 'bunny',
                'label' => 'Bunny Stream',
                'enabled' => config('bunny.library_id', '') !== '' && config('bunny.api_key', '') !== '',
                'environment' => 'production',
                'connection_status' => 'not_configured',
                'config' => [],
                'metadata' => [],
            ]);

            $this->info('✔ Media provider [bunny] created.');
        } else {
            $this->info('✔ Media provider [bunny] already exists.');
        }

        // 2) الأسعار الافتراضية (تعديل من لوحة الأدمن — لا شيء في الكود).
        $defaults = [
            ['storage_gb', 0.01],       // $0.01 لكل GB شهرياً (مرجعي)
            ['delivery_gb', 0.01],      // $0.01 لكل GB تسليم
            ['video_minutes', 0.005],   // $0.005 لكل دقيقة فيديو مخزن شهرياً
        ];

        foreach ($defaults as [$resource, $price]) {
            $exists = ProviderPricing::query()
                ->where('provider', 'bunny')
                ->where('resource', $resource)
                ->where('effective_from', now()->toDateString())
                ->exists();

            if (! $exists || $this->option('force')) {
                ProviderPricing::updateOrCreate(
                    ['provider' => 'bunny', 'resource' => $resource, 'effective_from' => now()->toDateString()],
                    ['unit_price' => $price, 'currency' => 'USD'],
                );

                $this->line("  ✔ Pricing [{$resource}] = \${$price}.");
            }
        }

        // 3) ميزات الوسائط في الخطط (الافتراضيات المنطقية لكل مستوى).
        $newFeatures = [
            'live_minutes_limit' => ['unlimited', 'string'],
            'recordings_limit' => ['unlimited', 'string'],
            'delivery_minutes_limit' => ['unlimited', 'string'],
            'live_recording_enabled' => ['true', 'boolean'],
            'secure_playback_enabled' => ['true', 'boolean'],
            'video_analytics_enabled' => ['true', 'boolean'],
            'video_watermark' => ['true', 'boolean'],
        ];

        // العلامة المائية ميزة إضافية: متاحة للخطط المتقدمة فقط افتراضياً.
        $watermarkRestricted = ['starter'];

        $plans = SubscriptionPlan::query()->get();

        foreach ($plans as $plan) {
            foreach ($newFeatures as $key => [$value, $type]) {
                $exists = PlanFeature::query()
                    ->where('plan_id', $plan->id)
                    ->where('feature_key', $key)
                    ->exists();

                if (! $exists) {
                    $effective = $key === 'video_watermark' && in_array(strtolower((string) $plan->slug), $watermarkRestricted, true)
                        ? 'false'
                        : $value;

                    PlanFeature::create([
                        'id' => UuidV7::generate(),
                        'plan_id' => $plan->id,
                        'feature_key' => $key,
                        'feature_value' => $effective,
                        'feature_type' => $type,
                        'sort_order' => 999,
                    ]);

                    $this->line("  ✔ Plan [{$plan->slug}] feature [{$key}] = {$effective}.");
                }
            }
        }

        // إبطال كاش الخطط فوراً — أي تغيير خطة مستقبلاً ينعكس مباشرة.
        PlanEngine::bumpVersion();

        $this->info('✔ Media Engine initialized. Plan/limit cache invalidated.');

        return self::SUCCESS;
    }
}