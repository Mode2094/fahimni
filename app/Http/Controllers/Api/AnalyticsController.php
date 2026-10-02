<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\AnalyticsService;
use App\Traits\AuthorizesTenantAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class AnalyticsController extends Controller
{
    use AuthorizesTenantAccess;

    public function __construct(
        private readonly AnalyticsService $analyticsService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenantId = $request->header('X-Tenant-Id');

        if ($user->is_super_admin) {
            return response()->json($this->analyticsService->superAdmin());
        }

        $pivot = $user->tenantPivots()
            ->where('tenant_id', $tenantId)
            ->first();

        if (! $pivot) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        if ($this->isTenantStaff($request, $tenantId)) {
            return response()->json($this->analyticsService->teacher($tenantId));
        }

        return response()->json($this->analyticsService->student($tenantId, $user->id));
    }

    public function daily(Request $request, string $statType): JsonResponse
    {
        $user = $request->user();
        $tenantId = $request->header('X-Tenant-Id');

        if (! $user->is_super_admin && ! $this->isTenantStaff($request, $tenantId)) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        $days = min(max($request->integer('days', 30), 1), 90);

        return response()->json([
            'stats' => $this->analyticsService->getDailyStats($tenantId, $statType, $days),
        ]);
    }

    public function clearCache(): JsonResponse
    {
        $this->authorize('system.manage');

        Cache::forget('analytics.super_admin');

        Tenant::query()->select('id')->chunkById(100, function ($tenants) {
            foreach ($tenants as $tenant) {
                Cache::forget("analytics.teacher.{$tenant->id}");
            }
        });

        return response()->json(['message' => 'Analytics cache cleared.']);
    }
}
