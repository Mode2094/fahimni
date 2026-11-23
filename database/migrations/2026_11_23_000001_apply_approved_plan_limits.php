<?php

use App\Models\PlanFeature;
use App\Models\SubscriptionPlan;
use Illuminate\Database\Migrations\Migration;

/**
 * الخطط المعتمدة (2026-11): الانطلاقة · المحترف · المتقدم.
 *
 * يضبط الحدود غير السعرية فقط — الأسعار لا تُلمس هنا إطلاقاً؛ تُنشر عبر
 * آلية Freemius الموثّقة (FreemiusPriceSyncService) التي لا تعتمد السعر
 * محلياً إلا بعد تأكيده من مزود الدفع.
 *
 *   | الخطة       | التخزين          | الطلاب | الكورسات | الفيديوهات           |
 *   | الانطلاقة   | 700 GB (716800)  | 50     | 3        | بلا حد عددي           |
 *   | المحترف     | 1.5 TB (1572864) | 250    | 7        | بلا حد عددي           |
 *   | المتقدم     | 3 TB (3145728)   | 1000   | 20       | بلا حد عددي           |
 *
 * "بلا حد عددي" = القيمة الكنسية `unlimited` التي يترجمها PlanEngine إلى
 * حدّ = null؛ فيبقى حد التخزين الفعلي (storage_limit_mb) هو الضابط الوحيد.
 */
return new class extends Migration
{
    /** @var array<string, array<string, string>> */
    private const PLAN_LIMITS = [
        'starter' => [
            'storage_limit_mb' => '716800',   // 700 GB
            'students_limit' => '50',
            'courses_limit' => '3',
            'videos_limit' => 'unlimited',
        ],
        'professional' => [
            'storage_limit_mb' => '1572864',  // 1.5 TB
            'students_limit' => '250',
            'courses_limit' => '7',
            'videos_limit' => 'unlimited',
        ],
        'enterprise' => [
            'storage_limit_mb' => '3145728',  // 3 TB
            'students_limit' => '1000',
            'courses_limit' => '20',
            'videos_limit' => 'unlimited',
        ],
    ];

    public function up(): void
    {
        foreach (self::PLAN_LIMITS as $slug => $limits) {
            // العمود الثالث له سلغان تاريخيان: enterprise (الإنتاج) و elite.
            $slugs = $slug === 'enterprise' ? ['enterprise', 'elite'] : [$slug];

            $plan = SubscriptionPlan::withTrashed()->whereIn('slug', $slugs)->first();

            if (! $plan) {
                continue;
            }

            $features = $limits + [
                // التشغيل الآمن مفعّل في كل الخطط الثلاث (الروابط الموقعة المؤقتة).
                'secure_playback_enabled' => 'true',
                'video_upload_enabled' => 'true',
            ];

            foreach ($features as $key => $value) {
                PlanFeature::updateOrCreate(
                    ['plan_id' => $plan->id, 'feature_key' => $key],
                    ['feature_value' => $value, 'feature_type' => 'string'],
                );
            }
        }

        // لقطة كل مستأجر مُخزّنة في Redis — نرفع الإصدار ليأخذ التغيير مفعوله فوراً.
        try {
            \App\Plans\PlanEngine::bumpVersion();
        } catch (\Throwable) {
            // لا نُفشل الترحيل إن كان الكاش غير متاح لحظياً — ينتهي إبطاله تلقائياً.
        }
    }

    public function down(): void
    {
        // القيم السابقة غير معروفة على مستوى كل خطة — لا نُرجّع تغييراً غير آمن.
    }
};
