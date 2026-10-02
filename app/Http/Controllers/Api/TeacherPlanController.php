<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Plans\PlanEngine;
use App\Plans\QuotaService;
use App\Plans\UsageService;
use App\Support\FeatureCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Teacher plan dashboard — generated entirely from the database through the
 * Plan Engine. No plan names, no hardcoded limits, no static usage.
 */
class TeacherPlanController extends Controller
{
    public function index(
        Request $request,
        PlanEngine $engine,
        QuotaService $quota,
        UsageService $usage,
    ): JsonResponse {
        // Super admins (and any user) without a tenant context get an empty
        // payload instead of a 500.
        $tenant = $request->attributes->get('current_tenant') ?? $request->input('current_tenant');

        if (! $tenant) {
            return response()->json(['plan' => null, 'features' => [], 'usage' => [], 'gates' => []]);
        }

        $tenantId = $tenant->getKey();

        $snapshot = $engine->snapshot($tenantId);
        $features = $snapshot['features'];
        $subscription = $snapshot['subscription'];

        // Gates: every boolean feature in the catalog → enabled state.
        $gates = [];
        foreach (FeatureCatalog::all() as $meta) {
            if (($meta['type'] ?? '') === 'boolean') {
                $gates[$meta['key']] = $engine->hasFeature($tenantId, $meta['key']);
            }
        }

        // Limits: every limit feature in the catalog → {value, unlimited}.
        $limits = [];
        foreach (FeatureCatalog::all() as $meta) {
            if (($meta['type'] ?? '') === 'numeric' && isset($features[$meta['key']])) {
                $limits[$meta['key']] = [
                    'value' => $features[$meta['key']],
                    'unlimited' => $features[$meta['key']] === 'unlimited',
                ];
            }
        }

        // Usage: every countable resource with used/limit/remaining/pct.
        $usageStatus = $quota->status($tenantId);

        // مصفوفة موحدة لكل ميزة في الكتالوج: مفعّلة/معطلة + وصف — للعرض
        // المباشر في لوحة المعلم (مع X حمراء للمعطلة وشرح عند التمرير).
        $featureMatrix = [];
        foreach (FeatureCatalog::all() as $meta) {
            $key = $meta['key'];
            $value = $features[$key] ?? null;
            $type = $meta['type'] ?? 'custom';
            $enabled = match ($type) {
                'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
                default => $value !== null && $value !== '0' && $value !== '',
            };

            $featureMatrix[] = [
                'key' => $key,
                'type' => $type,
                'label_ar' => (string) ($meta['ar'] ?? $key),
                'label_en' => (string) ($meta['en'] ?? $key),
                'desc_ar' => (string) ($meta['desc_ar'] ?? ''),
                'desc_en' => (string) ($meta['desc_en'] ?? ''),
                'unit' => $meta['unit'] ?? null,
                'value' => $value,
                'enabled' => $enabled,
            ];
        }

        return response()->json([
            'plan' => $snapshot['active'] ? $subscription : null,
            'features' => $features,
            'limits' => $limits,
            'gates' => $gates,
            'feature_matrix' => $featureMatrix,
            'usage' => $usageStatus,
            'registry' => $usage->registry(),
        ]);
    }
}
