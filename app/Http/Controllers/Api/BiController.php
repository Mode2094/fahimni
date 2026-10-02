<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProviderCostRule;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Services\SaasCostService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\Uid\UuidV7;

/**
 * لوحة Business Intelligence — ربحية الخطط وتكاليف البنية التحتية.
 */
class BiController extends Controller
{
    public function __construct(private readonly SaasCostService $cost) {}

    /**
     * GET /admin/bi — ربحية الخطط + تكاليف البنية للشهر.
     */
    public function index(): JsonResponse
    {
        return response()->json(['bi' => $this->cost->biSummary()]);
    }

    /**
     * POST /admin/bi/recalculate — إعادة حساب كل التكاليف والربحية.
     */
    public function recalculate(): JsonResponse
    {
        $tenantIds = Tenant::query()->pluck('id');

        foreach ($tenantIds as $tenantId) {
            try {
                $this->cost->persistUsageCost((string) $tenantId);
            } catch (\Throwable) {
                // مستأجر بلا بيانات استخدام — يتخطى.
            }
        }

        $plans = $this->cost->persistPlanProfitability();

        return response()->json([
            'message' => "Recalculated usage costs for {$tenantIds->count()} tenants and profitability for {$plans} plan snapshots.",
            'bi' => $this->cost->biSummary(),
        ]);
    }

    /**
     * GET /admin/bi/cost-rules — قواعد الأسعار (المصدر الوحيد للحقيقة).
     */
    public function costRules(): JsonResponse
    {
        return response()->json([
            'rules' => ProviderCostRule::query()->orderBy('provider')->orderBy('resource')->get(),
        ]);
    }

    /**
     * PUT /admin/bi/cost-rules — تعديل سعر قاعدة.
     */
    public function updateCostRule(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'cost' => 'required|numeric|min:0',
            'unit' => 'nullable|string|max:20',
            'currency' => 'nullable|string|max:10',
            'is_active' => 'nullable|boolean',
            'metadata' => 'nullable|array',
        ]);

        $rule = ProviderCostRule::findOrFail($id);
        $rule->update($validated);

        return response()->json(['rule' => $rule]);
    }

    /**
     * GET /admin/bi/plans/{plan} — تحذير الربحية لخطة (يستخدم عند التعديل).
     */
    public function planCheck(string $id): JsonResponse
    {
        $plan = SubscriptionPlan::findOrFail($id);

        return response()->json([
            'plan_id' => $plan->id,
            'profitability' => $this->cost->planProfitability($plan),
        ]);
    }
}
