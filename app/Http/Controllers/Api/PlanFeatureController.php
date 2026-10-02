<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Plan\UpdateFeatureRequest;
use App\Http\Resources\PlanFeatureResource;
use App\Models\PlanFeature;
use App\Models\SubscriptionPlan;
use Illuminate\Http\JsonResponse;
use Symfony\Component\Uid\UuidV7;

class PlanFeatureController extends Controller
{
    public function index(string $planId): JsonResponse
    {
        $plan = SubscriptionPlan::findOrFail($planId);

        $this->authorize('viewAny', PlanFeature::class);

        return response()->json([
            'features' => PlanFeatureResource::collection($plan->features),
        ]);
    }

    public function store(UpdateFeatureRequest $request, string $planId): JsonResponse
    {
        $plan = SubscriptionPlan::findOrFail($planId);

        $this->authorize('create', PlanFeature::class);

        $feature = $plan->features()->create([
            'id' => UuidV7::generate(),
            'feature_key' => $request->feature_key,
            'feature_value' => $request->feature_value,
            'feature_type' => $request->feature_type ?? 'string',
            'sort_order' => $request->sort_order ?? 0,
        ]);

        return response()->json([
            'feature' => new PlanFeatureResource($feature),
        ], 201);
    }

    public function update(UpdateFeatureRequest $request, string $planId, string $featureId): JsonResponse
    {
        $feature = PlanFeature::where('plan_id', $planId)->findOrFail($featureId);

        $this->authorize('update', PlanFeature::class);

        $feature->update($request->validated());

        $payload = [
            'feature' => new PlanFeatureResource($feature),
        ];

        // حماية الربحية عند تغيير الحدود (storage/videos/live...).
        try {
            $plan = SubscriptionPlan::find($planId);

            if ($plan) {
                $warning = app(\App\Services\SaasCostService::class)->profitabilityWarning($plan->fresh());

                if ($warning !== null) {
                    $payload['profitability_warning'] = $warning;
                }
            }
        } catch (\Throwable) {
            // فشل حساب التكلفة لا يمنع تحديث الميزة.
        }

        return response()->json($payload);
    }

    public function destroy(string $planId, string $featureId): JsonResponse
    {
        $feature = PlanFeature::where('plan_id', $planId)->findOrFail($featureId);

        $this->authorize('delete', PlanFeature::class);

        $feature->delete();

        return response()->json(['message' => 'Feature removed.']);
    }
}
