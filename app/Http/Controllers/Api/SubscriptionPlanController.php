<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Plan\PublishPlanPriceRequest;
use App\Http\Requests\Plan\StorePlanRequest;
use App\Http\Requests\Plan\UpdatePlanRequest;
use App\Http\Resources\SubscriptionPlanResource;
use App\Models\SubscriptionPlan;
use App\Services\FreemiusPriceSyncService;
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
            $data = $this->withoutFreemiusKeys($request->validated());

            // Platform-subscription prices are ALWAYS USD (Freemius is the
            // only gateway and does not support JOD). Never trust the client
            // for this.
            $data['currency'] = 'USD';

            $data['metadata'] = $this->freemiusMetadata($request, []);

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

        // Prices are NEVER changed through the generic update: the displayed
        // price must be verified against Freemius first (see publishPrice()).
        $plan->update(array_merge(
            collect($this->withoutFreemiusKeys($request->validated()))
                ->except(['currency', 'monthly_price', 'yearly_price'])
                ->all(),
            ['metadata' => $this->freemiusMetadata($request, $plan->metadata ?? [])]
        ));

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
     * Live Freemius sync state for a plan (read-only; safe to poll).
     */
    public function freemiusSync(string $id): JsonResponse
    {
        $plan = SubscriptionPlan::findOrFail($id);

        $this->authorize('view', $plan);

        return response()->json([
            'sync' => app(FreemiusPriceSyncService::class)->status($plan),
        ]);
    }

    /**
     * Save & publish a plan's subscription price(s) to Freemius.
     *
     * Super-admin only (enforced by the `admin` route group + policy). Sends the
     * new price to Freemius through the documented developer-scope integration,
     * re-reads the ACTUAL price, and adopts it locally ONLY on a confirmed
     * match. On any failure the displayed price is left untouched and an honest
     * failure status is returned — never a fake success.
     */
    public function publishPrice(PublishPlanPriceRequest $request, string $id): JsonResponse
    {
        $plan = SubscriptionPlan::findOrFail($id);

        $this->authorize('publishPrice', $plan);

        $data = $request->validated();
        $prices = [];

        if (array_key_exists('monthly_price', $data) && $data['monthly_price'] !== null) {
            $prices['monthly'] = (float) $data['monthly_price'];
        }

        if (array_key_exists('yearly_price', $data) && $data['yearly_price'] !== null) {
            $prices['yearly'] = (float) $data['yearly_price'];
        }

        if ($prices === []) {
            return response()->json(['message' => 'Provide a monthly and/or a yearly price.'], 422);
        }

        $result = app(FreemiusPriceSyncService::class)->publish($plan, $prices, $request);

        return response()->json([
            'ok' => $result['ok'],
            'locked' => $result['locked'],
            'results' => $result['results'],
            'plan' => new SubscriptionPlanResource($result['plan']->load('features')),
        ], $result['locked'] ? 409 : 200);
    }

    /**
     * Strip Freemius mapping keys from the validated payload — they live in
     * the `metadata` JSONB column, not as top-level table columns.
     */
    private function withoutFreemiusKeys(array $data): array
    {
        foreach ([
            'freemius_monthly_plan_id', 'freemius_yearly_plan_id',
            'freemius_monthly_pricing_id', 'freemius_yearly_pricing_id',
        ] as $key) {
            unset($data[$key]);
        }

        return $data;
    }

    /**
     * Persist the Freemius plan/pricing-ID mapping inside the existing
     * metadata JSONB column (the local plan remains the price/feature source
     * of truth — Freemius IDs are only a billing mapping, never a price).
     *
     * Also clears any legacy Paddle/Lemon mapping keys so the admin panel no
     * longer carries dead configuration.
     */
    private function freemiusMetadata($request, array $meta): array
    {
        foreach ([
            'freemius_monthly_plan_id',
            'freemius_yearly_plan_id',
            'freemius_monthly_pricing_id',
            'freemius_yearly_pricing_id',
        ] as $key) {
            if ($request->exists($key)) {
                $meta[$key] = trim((string) $request->input($key)) !== '' ? trim((string) $request->input($key)) : null;
            }
        }

        // Remove obsolete gateway mapping keys.
        foreach ([
            'paddle_price_id_monthly', 'paddle_price_id_yearly',
            'lemon_variant_id_monthly', 'lemon_variant_id_yearly',
        ] as $dead) {
            unset($meta[$dead]);
        }

        return $meta;
    }
}
