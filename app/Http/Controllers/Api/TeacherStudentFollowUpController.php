<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\StudentFollowUpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * متابعة الطلاب — قائمة الطلاب الذين يحتاجون انتباهاً، مبنية على قواعد
 * واضحة من بيانات النشاط والتقدم والاستحقاقات الموجودة. ليست حكماً أكاديمياً
 * ولا ذكاءً اصطناعياً؛ كل صف مصحوب بأسباب استحقاقه ودليلها.
 */
class TeacherStudentFollowUpController extends Controller
{
    public function __construct(private readonly StudentFollowUpService $service) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'course_id' => 'nullable|uuid',
            'group_id' => 'nullable|uuid',
            'status' => 'nullable|in:all,at_risk,active,new,inactive,low_progress,overdue,no_activity_data,never_signed_in',
            'inactive_days' => 'nullable|integer|min:1|max:365',
            'progress_below' => 'nullable|integer|min:1|max:100',
            'min_enrolled_days' => 'nullable|integer|min:0|max:365',
            'q' => 'nullable|string|max:120',
            'sort' => 'nullable|in:risk,inactive,progress,name',
            'per_page' => 'nullable|integer|min:1|max:100',
            'page' => 'nullable|integer|min:1',
        ]);

        $tenantId = $request->current_tenant->id;

        $result = $this->service->build($tenantId, $validated);

        $perPage = (int) ($validated['per_page'] ?? 25);
        $page = max(1, (int) ($validated['page'] ?? 1));
        $total = count($result['students']);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($page, $lastPage);

        $slice = array_slice($result['students'], ($page - 1) * $perPage, $perPage);

        return response()->json([
            'data' => $slice,
            'meta' => $result['meta'] + [
                'pagination' => [
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'last_page' => $lastPage,
                ],
            ],
        ]);
    }
}
