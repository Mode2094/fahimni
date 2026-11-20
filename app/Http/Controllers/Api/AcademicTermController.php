<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AcademicTermResource;
use App\Models\AcademicTerm;
use App\Services\AcademicTermService;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AcademicTermController extends Controller
{
    public function __construct(
        private readonly AcademicTermService $terms,
    ) {}

    private function tenantId(Request $request): string
    {
        return (string) ($request->attributes->get('current_tenant')?->getKey()
            ?? $request->header('X-Tenant-Id'));
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', AcademicTerm::class);

        $terms = AcademicTerm::query()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->withCount('enrollments')
            ->orderByDesc('starts_at')
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'terms' => AcademicTermResource::collection($terms),
            'seats' => $this->terms->seatSummary($this->tenantId($request)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', AcademicTerm::class);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date|after_or_equal:starts_at',
            'status' => ['nullable', Rule::in(AcademicTerm::STATUSES)],
            'metadata' => 'nullable|array',
        ]);

        $tenantId = $this->tenantId($request);

        $term = AcademicTerm::create([
            'tenant_id' => $tenantId,
            'name' => $data['name'],
            'starts_at' => $data['starts_at'] ?? null,
            'ends_at' => $data['ends_at'] ?? null,
            'status' => $data['status'] ?? AcademicTerm::STATUS_UPCOMING,
            'created_by' => $request->user()->id,
            'metadata' => $data['metadata'] ?? [],
        ]);

        AuditLogger::log($request, 'term.created', 'academic_term', $term->id, ['name' => $term->name]);

        return response()->json(['term' => new AcademicTermResource($term)], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $term = AcademicTerm::withCount('enrollments')->findOrFail($id);
        $this->authorize('view', $term);

        return response()->json([
            'term' => new AcademicTermResource($term),
            'seats' => $this->terms->seatSummary($this->tenantId($request)),
        ]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $term = AcademicTerm::findOrFail($id);
        $this->authorize('update', $term);

        $data = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date|after_or_equal:starts_at',
            'status' => ['sometimes', Rule::in(AcademicTerm::STATUSES)],
            'metadata' => 'nullable|array',
        ]);

        $term->update($data);

        return response()->json(['term' => new AcademicTermResource($term->fresh())]);
    }

    public function destroy(string $id): JsonResponse
    {
        $term = AcademicTerm::findOrFail($id);
        $this->authorize('delete', $term);

        // Soft-delete only: enrollment rows keep their academic_term_id and
        // therefore keep the historical grouping intact.
        $term->delete();

        return response()->json(['message' => 'Term deleted successfully.']);
    }

    public function activate(string $id): JsonResponse
    {
        $term = AcademicTerm::findOrFail($id);
        $this->authorize('update', $term);

        $term->update(['status' => AcademicTerm::STATUS_ACTIVE]);

        return response()->json(['term' => new AcademicTermResource($term->fresh())]);
    }

    /**
     * Enroll students into a course within this term.
     */
    public function assignStudents(Request $request, string $id): JsonResponse
    {
        $term = AcademicTerm::findOrFail($id);
        $this->authorize('update', $term);

        $data = $request->validate([
            'course_id' => 'required|string|exists:courses,id',
            'user_ids' => 'required|array|min:1',
            'user_ids.*' => 'string|exists:users,id',
        ]);

        $result = $this->terms->assignStudents($term, $data['course_id'], $data['user_ids']);

        AuditLogger::log($request, 'term.students_assigned', 'academic_term', $term->id, [
            'course_id' => $data['course_id'],
            'created' => count($result['created']),
            'failed' => count($result['failed']),
        ]);

        return response()->json(['result' => $result, 'seats' => $this->terms->seatSummary((string) $term->tenant_id)]);
    }

    /**
     * Dry-run preview of ending a term: who is freed, who stays, seats after.
     */
    public function endPreview(Request $request, string $id): JsonResponse
    {
        $term = AcademicTerm::findOrFail($id);
        $this->authorize('view', $term);

        $continue = (array) $request->input('continue', []);

        $preview = $this->terms->endPreview($term, $continue);
        unset($preview['free_ids'], $preview['continuing_ids'], $preview['active_elsewhere_ids']);

        return response()->json(['preview' => $preview]);
    }

    /**
     * «إنهاء الفصل وتجديد مقاعد الطلاب».
     */
    public function end(Request $request, string $id): JsonResponse
    {
        $term = AcademicTerm::findOrFail($id);
        $this->authorize('update', $term);

        $data = $request->validate([
            'continue' => 'nullable|array',
            'continue.*' => 'string|exists:users,id',
            'next_term_id' => 'nullable|string|exists:academic_terms,id',
            'create_next.name' => 'nullable|string|max:255',
            'create_next.starts_at' => 'nullable|date',
            'create_next.ends_at' => 'nullable|date|after_or_equal:create_next.starts_at',
        ]);

        $tenantId = (string) $term->tenant_id;
        $continue = $data['continue'] ?? [];

        $nextTerm = null;

        if (! empty($data['next_term_id'])) {
            $nextTerm = AcademicTerm::where('tenant_id', $tenantId)->findOrFail($data['next_term_id']);
        } elseif (! empty($data['create_next']['name'])) {
            $nextTerm = AcademicTerm::create([
                'tenant_id' => $tenantId,
                'name' => $data['create_next']['name'],
                'starts_at' => $data['create_next']['starts_at'] ?? null,
                'ends_at' => $data['create_next']['ends_at'] ?? null,
                'status' => AcademicTerm::STATUS_ACTIVE,
                'created_by' => $request->user()->id,
            ]);
        }

        if (! empty($continue) && ! $nextTerm) {
            return response()->json([
                'message' => 'يجب تحديد الفصل القادم (أو إنشاؤه) قبل ترحيل الطلاب المستمرين.',
                'code' => 'next_term_required',
            ], 422);
        }

        $summary = $this->terms->end($term, $continue, $nextTerm);

        AuditLogger::log($request, 'term.ended', 'academic_term', $term->id, [
            'seats_freed' => $summary['seats_freed'] ?? null,
            'continuing' => count($summary['continuing_ids'] ?? []),
            'next_term_id' => $summary['next_term_id'] ?? null,
        ]);

        return response()->json([
            'summary' => $summary,
            'seats' => $this->terms->seatSummary($tenantId),
        ]);
    }

    /**
     * Move selected continuing students into the next term (without ending).
     */
    public function promote(Request $request, string $id): JsonResponse
    {
        $term = AcademicTerm::findOrFail($id);
        $this->authorize('update', $term);

        $data = $request->validate([
            'next_term_id' => 'required|string|exists:academic_terms,id',
            'user_ids' => 'required|array|min:1',
            'user_ids.*' => 'string|exists:users,id',
        ]);

        $nextTerm = AcademicTerm::where('tenant_id', (string) $term->tenant_id)->findOrFail($data['next_term_id']);

        $result = $this->terms->promote($term, $nextTerm, $data['user_ids']);

        AuditLogger::log($request, 'term.students_promoted', 'academic_term', $term->id, [
            'next_term_id' => $nextTerm->id,
            'moved' => $result['moved'],
        ]);

        return response()->json(['result' => $result, 'seats' => $this->terms->seatSummary((string) $term->tenant_id)]);
    }
}
