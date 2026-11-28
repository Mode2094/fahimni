<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AcademicTermResource;
use App\Models\AcademicTerm;
use App\Models\Enrollment;
use App\Services\AcademicTermService;
use App\Services\EnrollmentService;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AcademicTermController extends Controller
{
    /** Statuses a term may be created/edited into directly (never `archived`). */
    private const EDITABLE_STATUSES = [AcademicTerm::STATUS_UPCOMING, AcademicTerm::STATUS_ACTIVE, AcademicTerm::STATUS_COMPLETED];

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
            // `archived` is intentionally excluded — archiving only happens
            // through the end/renew flow so enrollments are handled safely.
            'status' => ['nullable', Rule::in([
                AcademicTerm::STATUS_UPCOMING,
                AcademicTerm::STATUS_ACTIVE,
            ])],
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
            'status' => ['sometimes', Rule::in(self::EDITABLE_STATUSES)],
            'metadata' => 'nullable|array',
        ]);

        // An archived term is immutable: un-archiving must go through an
        // explicit, audited reactivation, never a blind status write.
        if ($term->status === AcademicTerm::STATUS_ARCHIVED && array_key_exists('status', $data)) {
            return response()->json([
                'message' => 'An archived term cannot be reactivated directly.',
                'code' => 'term_archived',
            ], 422);
        }

        // `archived` is not an EDITABLE_STATUS, so a direct archive attempt
        // already fails validation above. Belt-and-braces:
        if (($data['status'] ?? null) === AcademicTerm::STATUS_ARCHIVED) {
            return response()->json([
                'message' => 'Use the end/renew flow to archive a term.',
                'code' => 'use_end_flow',
            ], 422);
        }

        $term->update($data);

        AuditLogger::log($request, 'term.updated', 'academic_term', $term->id, $data);

        return response()->json(['term' => new AcademicTermResource($term->fresh())]);
    }

    public function destroy(string $id): JsonResponse
    {
        $term = AcademicTerm::findOrFail($id);
        $this->authorize('delete', $term);

        // A term that still owns enrollments must be ended (archived) instead
        // of deleted, so its history grouping is never orphaned.
        $hasEnrollments = Enrollment::withoutTenant()->where('academic_term_id', $term->id)->exists();

        if ($hasEnrollments) {
            return response()->json([
                'message' => 'This term has enrollments. End it instead of deleting it.',
                'code' => 'term_has_enrollments',
            ], 422);
        }

        $term->delete();

        return response()->json(['message' => 'Term deleted successfully.']);
    }

    public function activate(string $id): JsonResponse
    {
        $term = AcademicTerm::findOrFail($id);
        $this->authorize('update', $term);

        if ($term->status === AcademicTerm::STATUS_ARCHIVED) {
            return response()->json([
                'message' => 'An archived term cannot be reactivated directly.',
                'code' => 'term_archived',
            ], 422);
        }

        $term->update(['status' => AcademicTerm::STATUS_ACTIVE]);

        return response()->json(['term' => new AcademicTermResource($term->fresh())]);
    }

    /**
     * Enroll NEW students into a course within this term.
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
     * Students with an effective, term-less enrollment that can be linked.
     */
    public function linkableStudents(Request $request, string $id): JsonResponse
    {
        $term = AcademicTerm::findOrFail($id);
        $this->authorize('view', $term);

        $courseId = $request->filled('course_id')
            ? $request->validate(['course_id' => 'string|exists:courses,id'])['course_id']
            : null;

        return response()->json([
            'students' => $this->terms->linkableStudents((string) $term->tenant_id, $courseId),
        ]);
    }

    /**
     * Link EXISTING enrollments to this term (no delete, no recreate).
     */
    public function linkStudents(Request $request, string $id): JsonResponse
    {
        $term = AcademicTerm::findOrFail($id);
        $this->authorize('update', $term);

        $data = $request->validate([
            'user_ids' => 'required|array|min:1',
            'user_ids.*' => 'string|exists:users,id',
            'course_id' => 'nullable|string|exists:courses,id',
        ]);

        $result = $this->terms->linkEnrollments($term, $data['user_ids'], $data['course_id'] ?? null);

        AuditLogger::log($request, 'term.students_linked', 'academic_term', $term->id, [
            'linked' => $result['linked'],
            'skipped' => count($result['skipped']),
            'course_id' => $data['course_id'] ?? null,
        ]);

        return response()->json([
            'result' => $result,
            'seats' => $this->terms->seatSummary((string) $term->tenant_id),
        ]);
    }

    /**
     * Dry-run preview of ending a term. Recompute on every selection change by
     * passing `continue[]`; the response carries a `signature` the client must
     * echo back when confirming.
     */
    public function endPreview(Request $request, string $id): JsonResponse
    {
        $term = AcademicTerm::findOrFail($id);
        $this->authorize('view', $term);

        $request->validate([
            'continue' => 'nullable|array',
            'continue.*' => 'string|exists:users,id',
        ]);

        $preview = $this->terms->endPreview($term, (array) $request->input('continue', []));
        $signature = $preview['signature'];
        unset($preview['free_ids'], $preview['continuing_ids'], $preview['active_elsewhere_ids'], $preview['signature']);

        return response()->json(['preview' => $preview, 'signature' => $signature]);
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
            // The client must echo the signature of a preview it was shown, so
            // a stale selection can never end the term with a different set of
            // students. Internal (non-HTTP) callers may omit it.
            'preview_token' => 'required|string',
        ]);

        $tenantId = (string) $term->tenant_id;

        $spec = [];

        if (! empty($data['next_term_id'])) {
            $spec['next_term_id'] = $data['next_term_id'];
        } elseif (! empty($data['create_next']['name'])) {
            $spec['create'] = [
                'name' => $data['create_next']['name'],
                'starts_at' => $data['create_next']['starts_at'] ?? null,
                'ends_at' => $data['create_next']['ends_at'] ?? null,
            ];
        }

        $summary = $this->terms->end(
            $term,
            $data['continue'] ?? [],
            $spec,
            $data['preview_token'] ?? null,
        );

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
