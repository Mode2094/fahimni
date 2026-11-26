<?php

use App\Models\PlanFeature;
use App\Models\SubscriptionPlan;
use App\Plans\PlanEngine;
use Illuminate\Database\Migrations\Migration;

/**
 * علامة الفيديو المائية: متاحة على «المحترف» و«المتقدم» فقط.
 *
 * المفتاح `video_watermark` موجود في الكتالوج (`FeatureCatalog`) ويُنفَّذ على
 * الخادم والعميل (`VideoProtectionService` / `protected-video-player.tsx`)،
 * لكنه لم يكن مكتوباً في أي خطة: لا `SubscriptionPlanSeeder` ولا أي ترحيل
 * أنشأ صفّه. وبما أن `PlanEngine::hasFeature()` يعيد false للمفتاح الغائب،
 * كانت الميزة **مقفلة على كل الخطط** — بما فيها اشتراك المعلّمة الحالي
 * (`professional`) — فلا تُعرض العلامة المائية أثناء التشغيل.
 *
 * هذا الترحيل يضبط الصف صراحةً على الخطط الثلاث (السياسة نهائية):
 *   starter      → false (غير متاحة)
 *   professional → true  (المحترف)
 *   enterprise   → true  (المتقدم)
 *
 * لأنه مبني على خطط قاعدة البيانات، يسري فوراً على كل المشتركين الحاليين على
 * هاتين الخطتين بلا إعادة شراء. الحماية الأساسية للفيديو
 * (`secure_playback_enabled`) لا تُلمس وتبقى مشمولة في الخطط الثلاث.
 */
return new class extends Migration
{
    /** الصلاحيات القانونية للخطط المدفوعة (enterprise هو slug الإنتاج، elite بديل). */
    private const WATERMARK_SLUGS = ['professional', 'enterprise', 'elite'];

    public function up(): void
    {
        foreach (SubscriptionPlan::withTrashed()->get() as $plan) {
            $enabled = in_array((string) $plan->slug, self::WATERMARK_SLUGS, true);

            PlanFeature::updateOrCreate(
                ['plan_id' => $plan->id, 'feature_key' => 'video_watermark'],
                [
                    'feature_value' => $enabled ? 'true' : 'false',
                    'feature_type' => 'boolean',
                    'sort_order' => 100,
                ],
            );
        }

        // لقطة كل مستأجر مُخزّنة في Redis — نرفع الإصدار ليأخذ التغيير مفعوله فوراً.
        try {
            PlanEngine::bumpVersion();
        } catch (\Throwable) {
            // لا نُفشل الترحيل إن كان الكاش غير متاح لحظياً — ينتهي إبطاله تلقائياً.
        }
    }

    public function down(): void
    {
        // القيم السابقة غير معروفة على مستوى كل خطة — لا نُرجّع تغييراً غير آمن.
    }
};
