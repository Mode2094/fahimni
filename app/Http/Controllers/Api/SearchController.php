<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SearchService;
use App\Traits\AuthorizesTenantAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    use AuthorizesTenantAccess;

    public function __construct(
        private readonly SearchService $searchService,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $request->validate(['q' => 'required|string|min:2|max:100']);

        $user = $request->user();
        $tenantId = $request->header('X-Tenant-Id');

        if (! $user->is_super_admin) {
            $pivot = $user->tenantPivots()
                ->where('tenant_id', $tenantId)
                ->first();

            if (! $pivot) {
                return response()->json(['message' => 'Access denied.'], 403);
            }
        }

        $results = $this->searchService->global($request->q, $tenantId, $this->isTenantStaff($request));

        return response()->json(['results' => $results]);
    }

    public function courses(Request $request): JsonResponse
    {
        $request->validate(['q' => 'required|string|min:2|max:100']);

        $tenantId = $request->header('X-Tenant-Id');
        $perPage = min(max($request->integer('per_page', 10), 1), 100);

        $response = $this->searchService->searchCourses($request->q, $tenantId, $perPage);

        return response()->json($response);
    }

    public function lessons(Request $request): JsonResponse
    {
        $request->validate(['q' => 'required|string|min:2|max:100']);

        $this->requireTenantStaff($request);

        $tenantId = $request->header('X-Tenant-Id');
        $perPage = min(max($request->integer('per_page', 10), 1), 100);

        $response = $this->searchService->searchLessons($request->q, $tenantId, $perPage);

        return response()->json($response);
    }

    public function students(Request $request): JsonResponse
    {
        $request->validate(['q' => 'required|string|min:2|max:100']);

        $this->requireTenantStaff($request);

        $tenantId = $request->header('X-Tenant-Id');
        $perPage = min(max($request->integer('per_page', 10), 1), 100);

        $response = $this->searchService->searchStudents($request->q, $tenantId, $perPage);

        return response()->json($response);
    }
}
