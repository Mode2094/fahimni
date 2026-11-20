<?php

use App\Models\PlanFeature;
use App\Models\SubscriptionPlan;
use Illuminate\Database\Migrations\Migration;

/**
 * تفعيل نظام حجز المواعيد لجميع الخطط ولجميع المعلمين.
 *
 * الطلب: أن تكون المواعيد متاحة لكل المعلمين بغضّ النظر عن خطتهم، فنجعل
 * `appointments_enabled = true` و`appointments_limit = unlimited` في كل خطة.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (SubscriptionPlan::withTrashed()->get() as $plan) {
            foreach (['appointments_enabled' => 'true', 'appointments_limit' => 'unlimited'] as $key => $value) {
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
