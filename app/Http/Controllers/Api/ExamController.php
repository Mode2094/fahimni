<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Exam\StoreExamRequest;
use App\Http\Requests\Exam\UpdateExamRequest;
use App\Http\Resources\ExamResource;
use App\Http\Resources\QuestionResource;
use App\Models\Exam;
use App\Models\ExamQuestion;
use App\Models\ExamSection;
use App\Plans\QuotaService;
use App\Rules\TenantScopedExists;
use App\Traits\AuthorizesTenantAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\Uid\UuidV7;

class ExamController extends Controller
{
    use AuthorizesTenantAccess;

    public function index(Request $request): JsonResponse
    {
        $exams = Exam::withCount(['questions', 'attempts'])
            ->when($request->filled('course_id'), fn ($q) => $q->where('course_id', $request->course_id))
            ->when(
                $this->isTenantStaff($request) && $request->filled('is_published'),
                fn ($q) => $q->where('is_published', $request->boolean('is_published'))
            )
            // Students only ever see published exams of the courses they
            // are enrolled in — never the tenant-wide catalog.
            ->when(
                ! $this->isTenantStaff($request),
                fn ($q) => $q
                    ->where('is_published', true)
                    ->whereHas('course.enrollments', function ($q) use ($request) {
                        $q->where('user_id', $request->user()->id)
                            ->whereIn('status', ['active', 'completed'])
                            ->where(fn ($e) => $e->whereNull('expires_at')->orWhere('expires_at', '>', now()));
                    })
            )
            ->orderBy('sort_order')
            ->paginate($this->perPage($request));

        return response()->json([
            'exams' => ExamResource::collection($exams->items()),
            'pagination' => [
                'current_page' => $exams->currentPage(),
                'last_page' => $exams->lastPage(),
                'per_page' => $exams->perPage(),
                'total' => $exams->total(),
            ],
        ]);
    }

    private function perPage(Request $request): int
    {
        return min(max((int) $request->input('per_page', 20), 1), 100);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $exam = Exam::with(['sections'])
            ->when($this->isTenantStaff($request), fn ($q) => $q->with('questions.options'))
            ->findOrFail($id);

        $this->authorize('view', $exam);

        return response()->json([
            'exam' => new ExamResource($exam),
        ]);
    }

    public function questions(Request $request, string $id): JsonResponse
    {
        $exam = Exam::with(['questions.options'])->findOrFail($id);

        $this->authorize('viewQuestions', $exam);

        return response()->json([
            'questions' => QuestionResource::collection($exam->questions),
        ]);
    }

    public function store(StoreExamRequest $request): JsonResponse
    {
        $this->authorize('create', Exam::class);

        app(QuotaService::class)->assertCan($request->current_tenant->id, 'exams');

        $exam = Exam::create([
            'id' => UuidV7::generate(),
            ...$request->validated(),
        ]);

        return response()->json([
            'exam' => new ExamResource($exam),
        ], 201);
    }

    public function update(UpdateExamRequest $request, string $id): JsonResponse
    {
        $exam = Exam::findOrFail($id);

        $this->authorize('update', $exam);

        $exam->update($request->validated());

        return response()->json([
            'exam' => new ExamResource($exam->fresh()),
        ]);
    }

    public function destroy(string $id): JsonResponse
    {
        $exam = Exam::findOrFail($id);

        $this->authorize('delete', $exam);

        $exam->delete();

        return response()->json(['message' => 'Exam deleted successfully.']);
    }

    public function publish(string $id): JsonResponse
    {
        $exam = Exam::findOrFail($id);

        $this->authorize('update', $exam);

        $exam->update(['is_published' => true]);

        return response()->json([
            'exam' => new ExamResource($exam->fresh()),
        ]);
    }

    public function addQuestions(Request $request, string $id): JsonResponse
    {
        $exam = Exam::findOrFail($id);

        $this->authorize('update', $exam);

        $validated = $request->validate([
            'questions' => 'required|array|min:1',
            'questions.*.question_id' => ['required', 'string', new TenantScopedExists('questions')],
            'questions.*.exam_section_id' => ['nullable', 'string', new TenantScopedExists('exam_sections')],
            'questions.*.sort_order' => 'nullable|integer|min:0',
            'questions.*.points_override' => 'nullable|numeric|min:0',
        ]);

        $attached = [];
        foreach ($validated['questions'] as $q) {
            $exists = ExamQuestion::where('exam_id', $exam->id)
                ->where('question_id', $q['question_id'])
                ->exists();

            if ($exists) {
                continue;
            }

            // The section must belong to THIS exam, not just the tenant.
            if (! empty($q['exam_section_id'])) {
                $sectionBelongsToExam = ExamSection::where('id', $q['exam_section_id'])
                    ->where('exam_id', $exam->id)
                    ->exists();

                if (! $sectionBelongsToExam) {
                    return response()->json([
                        'message' => 'The selected exam_section_id does not belong to this exam.',
                    ], 422);
                }
            }

            $eq = ExamQuestion::create([
                'id' => UuidV7::generate(),
                'tenant_id' => $exam->tenant_id,
                'exam_id' => $exam->id,
                'exam_section_id' => $q['exam_section_id'] ?? null,
                'question_id' => $q['question_id'],
                'sort_order' => $q['sort_order'] ?? 0,
                'points_override' => $q['points_override'] ?? null,
            ]);

            $attached[] = $eq;
        }

        return response()->json([
            'message' => count($attached).' question(s) added to exam.',
            'exam' => new ExamResource($exam->fresh(['questions.options'])),
        ]);
    }

    public function removeQuestion(string $id, string $questionId): JsonResponse
    {
        $exam = Exam::findOrFail($id);

        $this->authorize('update', $exam);

        $examQuestion = ExamQuestion::where('exam_id', $exam->id)
            ->where('question_id', $questionId)
            ->firstOrFail();

        $examQuestion->delete();

        return response()->json(['message' => 'Question removed from exam.']);
    }
}
