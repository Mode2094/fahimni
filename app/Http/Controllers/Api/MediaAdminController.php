<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Media\MediaCostService;
use App\Media\MediaProviderManager;
use App\Media\MediaReconciliationService;
use App\Media\MediaUsageService;
use App\Media\MediaWebhookService;
use App\Media\ProviderHealthService;
use App\Models\MediaProvider;
use App\Models\ProviderPricing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * لوحة البنية التحتية للوسائط — Super Admin فقط.
 */
class MediaAdminController extends Controller
{
    public function __construct(
        private readonly MediaProviderManager $manager,
        private readonly ProviderHealthService $health,
        private readonly MediaWebhookService $webhooks,
        private readonly MediaReconciliationService $reconcile,
        private readonly MediaUsageService $usage,
        private readonly MediaCostService $costs,
    ) {}

    /**
     * الملخص العام: تخزين، فيديوهات، بث، تكلفة، صحة المزود.
     */
    public function dashboard(Request $request): JsonResponse
    {
        $yearMonth = $request->input('year_month', now()->format('Y-m'));

        return response()->json([
            'summary' => $this->costs->adminSummary($yearMonth),
            'providers' => $this->manager->adminPayload(),
            'webhooks' => $this->webhooks->stats(),
            'recent_webhook_events' => $this->webhooks->recentEvents(10),
        ]);
    }

    /**
     * حفظ إعدادات المزود (بدون كشف الأسرار للفرونت).
     */
    public function updateProvider(Request $request, string $provider): JsonResponse
    {
        abort_unless($this->manager->supports($provider), 404, 'Provider not supported.');

        $validated = $request->validate([
            'label' => 'nullable|string|max:190',
            'enabled' => 'nullable|boolean',
            'environment' => 'nullable|in:sandbox,production',
            'credentials' => 'nullable|array',
            'credentials.*' => 'nullable|string',
        ]);

        $model = $this->manager->model($provider) ?? new MediaProvider(['provider' => $provider, 'label' => $provider]);

        if (array_key_exists('enabled', $validated)) {
            $model->enabled = $validated['enabled'];
        }

        if (array_key_exists('label', $validated) && $validated['label'] !== null) {
            $model->label = $validated['label'];
        }

        if (array_key_exists('environment', $validated)) {
            $model->environment = $validated['environment'];
        }

        if (! empty($validated['credentials'])) {
            $model->fillCredentials($validated['credentials']);
        }

        $model->save();

        return response()->json(['provider' => $this->manager->adminPayload()[$provider] ?? null]);
    }

    /**
     * فحص اتصال المزود.
     */
    public function testProvider(Request $request, string $provider): JsonResponse
    {
        $result = $this->health->check($provider);

        return response()->json($result, $result['ok'] ? 200 : 502);
    }

    /**
     * مزامنة الاستخدام مع المزود (تشغيل فوري).
     */
    public function sync(Request $request, ?string $provider = null): JsonResponse
    {
        $result = $this->reconcile->reconcile($provider);

        return response()->json(['result' => $result]);
    }

    /**
     * تكلفة كل معلم + الربحية.
     */
    public function profitability(Request $request): JsonResponse
    {
        return response()->json([
            'teachers' => $this->costs->teacherProfitability($request->input('year_month')),
            'platform' => $this->costs->platformTotal($request->input('year_month')),
        ]);
    }

    /**
     * أسعار المزود (تعديل ديناميكي).
     */
    public function pricing(Request $request): JsonResponse
    {
        return response()->json([
            'pricing' => ProviderPricing::query()->orderBy('provider')->orderBy('resource')->get()->map(fn (ProviderPricing $p) => [
                'id' => $p->id,
                'provider' => $p->provider,
                'resource' => $p->resource,
                'unit_price' => (float) $p->unit_price,
                'currency' => $p->currency,
                'effective_from' => $p->effective_from?->toDateString(),
            ]),
        ]);
    }

    public function updatePricing(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'provider' => 'required|string|max:50',
            'resource' => 'required|string|max:50',
            'unit_price' => 'required|numeric|min:0',
            'currency' => 'nullable|string|max:10',
        ]);

        $pricing = ProviderPricing::updateOrCreate(
            [
                'provider' => $validated['provider'],
                'resource' => $validated['resource'],
                'effective_from' => now()->toDateString(),
            ],
            [
                'unit_price' => $validated['unit_price'],
                'currency' => $validated['currency'] ?? 'USD',
            ],
        );

        return response()->json(['pricing' => $pricing]);
    }

    /**
     * أحداث الويبهوك الأخيرة.
     */
    public function webhookEvents(Request $request): JsonResponse
    {
        return response()->json(['events' => $this->webhooks->recentEvents((int) ($request->input('limit', 50)))]);
    }
}
