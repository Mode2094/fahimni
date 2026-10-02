<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Plan\StorePlanRequest;
use App\Http\Requests\Plan\UpdatePlanRequest;
use App\Http\Resources\SubscriptionPlanResource;
use App\Models\SubscriptionPlan;
use App\Services\SaasCostService;
use App\Support\FeatureCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\UuidV7;

class SubscriptionPlanController extends Controller
{
    public function catalog(): JsonResponse
    {
        return response()->json([
            'catalog' => FeatureCatalog::withStoredExtra(),
        ]);
    }

    public function index(): JsonResponse
    {
        $this->authorize('viewAny', SubscriptionPlan::class);

        $plans = SubscriptionPlan::with('features')
            ->orderBy('sort_order')
            ->get();

        return response()->json([
            'plans' => SubscriptionPlanResource::collection($plans),
        ]);
    }

    public function store(StorePlanRequest $request): JsonResponse
    {
        $this->authorize('create', SubscriptionPlan::class);

        $plan = DB::transaction(function () use ($request) {
            $data = $request->validated();

            $data['metadata'] = $this->paddleMetadata($request, $this->lemonMetadata($request, []));

            $plan = SubscriptionPlan::create($data);

            if ($request->has('features')) {
                foreach ($request->features as $feat) {
                    $plan->features()->create([
                        'id' => UuidV7::generate(),
                        'feature_key' => $feat['feature_key'],
                        'feature_value' => $feat['feature_value'],
                        'feature_type' => $feat['feature_type'] ?? 'string',
                        'sort_order' => $feat['sort_order'] ?? 0,
                    ]);
                }
            }

            return $plan;
        });

        return response()->json([
            'plan' => new SubscriptionPlanResource($plan->load('features')),
        ], 201);
    }

    public function show(string $id): JsonResponse
    {
        $plan = SubscriptionPlan::with('features')->findOrFail($id);

        $this->authorize('view', $plan);

        return response()->json([
            'plan' => new SubscriptionPlanResource($plan),
        ]);
    }

    public function update(UpdatePlanRequest $request, string $id): JsonResponse
    {
        $plan = SubscriptionPlan::findOrFail($id);

        $this->authorize('update', $plan);

        $plan->update(array_merge($request->validated(), [
            'metadata' => $this->paddleMetadata($request, $this->lemonMetadata($request, $plan->metadata ?? [])),
        ]));

        $payload = [
            'plan' => new SubscriptionPlanResource($plan->load('features')),
        ];

        // حماية الربحية: لا نمنع التعديل، لكن ننبّه الأدمن إن أصبحت الخطة
        // خاسرة (التكلفة المتوقعة من حدودها > السعر).
        try {
            $warning = app(SaasCostService::class)->profitabilityWarning($plan->fresh());

            if ($warning !== null) {
                $payload['profitability_warning'] = $warning;
            }
        } catch (\Throwable) {
            // فشل حساب التكلفة لا يمنع تحديث الخطة.
        }

        return response()->json($payload);
    }

    public function destroy(string $id): JsonResponse
    {
        $plan = SubscriptionPlan::findOrFail($id);

        $this->authorize('delete', $plan);

        $plan->delete();

        return response()->json(['message' => 'Plan deleted.']);
    }

    /**
     * Persist the Paddle price-ID mapping inside the existing metadata JSONB
     * column (the plan itself remains the price source of truth — Paddle IDs
     * are only a mapping, never a price).
     */
    private function paddleMetadata($request, array $meta): array
    {
        foreach (['paddle_price_id_monthly', 'paddle_price_id_yearly'] as $key) {
            if ($request->exists($key)) {
                $meta[$key] = trim((string) $request->input($key)) !== '' ? trim((string) $request->input($key)) : null;
            }
        }

        return $meta;
    }

    /**
     * Persist the Lemon Squeezy variant-ID mapping inside the existing
     * metadata JSONB column (the plan itself remains the price source of
     * truth — LS variant IDs are only a mapping, never a price).
     */
    private function lemonMetadata($request, array $meta): array
    {
        foreach (['lemon_variant_id_monthly', 'lemon_variant_id_yearly'] as $key) {
            if ($request->exists($key)) {
                $meta[$key] = trim((string) $request->input($key)) !== '' ? trim((string) $request->input($key)) : null;
            }
        }

        return $meta;
    }
}
