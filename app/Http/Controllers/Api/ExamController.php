<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Exam\StoreExamRequest;
use App\Http\Requests\Exam\UpdateExamRequest;
use App\Http\Resources\ExamResource;
use App\Http\Resources\QuestionResource;
use App\Models\Course;
use App\Models\Exam;
use App\Models\ExamAnswer;
use App\Models\ExamQuestion;
use App\Models\ExamSection;
use App\Models\Question;
use App\Plans\QuotaService;
use App\Rules\TenantScopedExists;
use App\Traits\AuthorizesTenantAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
                    // Exams shared to specific course groups are hidden from
                    // students who are not members of those groups.
                    ->visibleToStudent($request->user()->id)
                    ->whereHas('course.enrollments', function ($q) use ($request) {
                        $q->where('user_id', $request->user()->id)
                            ->effective()
                            ->where(fn ($e) => $e->whereNull('expires_at')->orWhere('expires_at', '>', now()));
                    })
            )
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->orderBy('id')
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

        $data = $request->validated();

        // A new exam appends to the end of its section unless an order was
        // supplied explicitly — otherwise every exam would default to 0 and
        // the student list would be unordered.
        if (! isset($data['sort_order'])) {
            $data['sort_order'] = ((int) Exam::where('course_id', $data['course_id'])
                ->when(! empty($data['section_id']), fn ($q) => $q->where('section_id', $data['section_id']))
                ->when(empty($data['section_id']), fn ($q) => $q->whereNull('section_id'))
                ->max('sort_order')) + 1;
        }

        $exam = Exam::create([
            'id' => UuidV7::generate(),
            ...$data,
        ]);

        return response()->json([
            'exam' => new ExamResource($exam),
        ], 201);
    }

    /**
     * Reorder the exams inside one section. Mirrors lessons/reorder: optimistic
     * concurrency (the client sends the list it saw), row locks, and a conflict
     * response so two teachers never clobber each other.
     */
    public function reorder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'course_id' => ['required', 'string', new TenantScopedExists('courses')],
            'section_id' => ['nullable', 'string', new TenantScopedExists('sections')],
            'exam_ids' => 'required|array|min:1|max:500',
            'exam_ids.*' => 'required|string|distinct',
            'expected_exam_ids' => 'sometimes|required|array|min:1|max:500',
            'expected_exam_ids.*' => 'required|string|distinct',
        ]);

        $course = Course::findOrFail($validated['course_id']);

        $this->authorize('update', $course);

        $ids = array_values($validated['exam_ids']);
        $sectionId = $validated['section_id'] ?? null;

        $expectedIds = isset($validated['expected_exam_ids'])
            ? array_values($validated['expected_exam_ids'])
            : null;

        return DB::transaction(function () use ($course, $sectionId, $ids, $expectedIds) {
            $lockedCourse = Course::whereKey($course->id)->lockForUpdate()->firstOrFail();

            $exams = Exam::where('course_id', $lockedCourse->id)
                ->where(fn ($q) => $sectionId !== null
                    ? $q->where('section_id', $sectionId)
                    : $q->whereNull('section_id'))
                ->orderBy('sort_order')
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($ids as $examId) {
                if (! $exams->has($examId)) {
                    return response()->json(['message' => 'Invalid exam ids.'], 422);
                }
            }

            $currentIds = $exams->keys()->map(fn ($id) => (string) $id)->all();
            if (count($ids) !== count($currentIds) || array_diff($currentIds, $ids) !== []) {
                return response()->json([
                    'message' => 'The exam list changed. Reload the section and try again.',
                    'code' => 'exam_order_conflict',
                ], 409);
            }

            if ($expectedIds !== null && $expectedIds !== $currentIds) {
                return response()->json([
                    'message' => 'The exam order changed elsewhere. Reload the section and try again.',
                    'code' => 'exam_order_conflict',
                ], 409);
            }

            foreach ($ids as $index => $examId) {
                $exam = $exams[$examId];
                $nextOrder = $index + 1;
                if ($exam->sort_order !== $nextOrder) {
                    $exam->sort_order = $nextOrder;
                    $exam->save();
                }
            }

            return response()->json(['message' => 'Exams reordered successfully.']);
        }, attempts: 3);
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

    /**
     * إنشاء نسخة (مسودة) من اختبار — الإعدادات والأقسام والأسئلة (من بنك
     * الأسئلة) فقط، بلا محاولات أو درجات أو أي بيانات طلاب.
     */
    public function duplicate(Request $request, string $id): JsonResponse
    {
        $exam = Exam::findOrFail($id);

        $this->authorize('view', $exam);
        $this->authorize('create', Exam::class);

        $validated = $request->validate(['title' => 'nullable|string|max:255']);

        $result = app(\App\Content\ContentDuplicator::class)
            ->duplicateExam($exam, $validated['title'] ?? null);

        return response()->json([
            'exam' => new ExamResource($result['model']->load(['sections', 'questions.options'])),
            'report' => $result['report'],
        ], 201);
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

        $nextSort = (int) (ExamQuestion::where('exam_id', $exam->id)->max('sort_order') ?? -1) + 1;

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
                'sort_order' => $q['sort_order'] ?? $nextSort++,
                'points_override' => $q['points_override'] ?? null,
            ]);

            $attached[] = $eq;
        }

        return response()->json([
            'message' => count($attached).' question(s) added to exam.',
            'exam' => new ExamResource($exam->fresh(['questions.options'])),
        ]);
    }

    /**
     * اسحب عدداً محدداً من أسئلة البنك عشوائيًّا وأضفها إلى الاختبار.
     *
     * المصدر هو بنك أسئلة المعلم نفسه (يمكن تصفيته بالمادة/القسم/التصنيف/
     * الصعوبة/النوع)، والسحب يستثني أي سؤال مضاف إلى هذا الاختبار بالفعل فلا
     * تتكرر الأسئلة عند إعادة السحب. الأسئلة تُربط بالاختبار عبر exam_questions
     * فقط؛ لا تُنسخ ولا تُحتسب من حدود الخطة (كما في addQuestions)، ونتائج
     * المحاولات القائمة لا تُمس.
     */
    public function drawQuestions(Request $request, string $id): JsonResponse
    {
        $exam = Exam::findOrFail($id);

        $this->authorize('update', $exam);

        $validated = $request->validate([
            'count' => 'required|integer|min:1|max:200',
            'course_id' => ['nullable', 'string', new TenantScopedExists('courses')],
            'section_id' => ['nullable', 'string', new TenantScopedExists('sections')],
            'category' => 'nullable|string|max:100',
            'difficulty' => 'nullable|string|in:easy,medium,hard',
            'question_type' => 'nullable|string|in:'.implode(',', Question::TYPES),
        ]);

        // Never hand out a question the exam already contains.
        $alreadyAttached = ExamQuestion::where('exam_id', $exam->id)->pluck('question_id')->all();

        $candidates = Question::query()
            ->where('is_active', true)
            ->when(! empty($validated['course_id']), fn ($q) => $q->where('course_id', $validated['course_id']))
            ->when(! empty($validated['section_id']), fn ($q) => $q->where('section_id', $validated['section_id']))
            ->when(! empty($validated['category']), fn ($q) => $q->where('category', $validated['category']))
            ->when(! empty($validated['difficulty']), fn ($q) => $q->where('difficulty', $validated['difficulty']))
            ->when(! empty($validated['question_type']), fn ($q) => $q->where('question_type', $validated['question_type']))
            ->whereNotIn('id', $alreadyAttached)
            ->inRandomOrder()
            ->limit($validated['count'])
            ->pluck('id');

        if ($candidates->isEmpty()) {
            return response()->json([
                'message' => 'No matching questions are available in the bank.',
                'added_count' => 0,
                'requested_count' => (int) $validated['count'],
                'exam' => new ExamResource($exam->fresh(['questions.options'])),
            ]);
        }

        $nextSort = (int) (ExamQuestion::where('exam_id', $exam->id)->max('sort_order') ?? -1) + 1;

        DB::transaction(function () use ($exam, $candidates, &$nextSort) {
            foreach ($candidates as $questionId) {
                ExamQuestion::create([
                    'id' => UuidV7::generate(),
                    'tenant_id' => $exam->tenant_id,
                    'exam_id' => $exam->id,
                    'question_id' => $questionId,
                    'sort_order' => $nextSort++,
                ]);
            }
        });

        return response()->json([
            'message' => $candidates->count().' question(s) drawn from the bank.',
            'added_count' => $candidates->count(),
            'requested_count' => (int) $validated['count'],
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

        // Historical answers hang off the exam_questions row with a cascade
        // delete. Removing the question from the exam would silently destroy a
        // student's past answers, so refuse while any exist.
        $hasAnswers = ExamAnswer::where('exam_question_id', $examQuestion->id)->exists();

        if ($hasAnswers) {
            return response()->json([
                'message' => 'This question already has graded answers in this exam; it cannot be removed without losing results.',
            ], 409);
        }

        $examQuestion->delete();

        return response()->json(['message' => 'Question removed from exam.']);
    }

    /**
     * Persist the teacher's ordering of the questions attached to an exam.
     * Accepts the full ordered list of question ids; ids that do not belong to
     * this exam are ignored.
     */
    public function reorderQuestions(Request $request, string $id): JsonResponse
    {
        $exam = Exam::findOrFail($id);

        $this->authorize('update', $exam);

        $validated = $request->validate([
            'question_ids' => 'required|array|min:1',
            'question_ids.*' => 'required|string',
        ]);

        $owned = ExamQuestion::where('exam_id', $exam->id)
            ->whereIn('question_id', $validated['question_ids'])
            ->pluck('question_id')
            ->all();

        $ordered = array_values(array_intersect($validated['question_ids'], $owned));

        DB::transaction(function () use ($exam, $ordered) {
            foreach ($ordered as $index => $questionId) {
                ExamQuestion::where('exam_id', $exam->id)
                    ->where('question_id', $questionId)
                    ->update(['sort_order' => $index]);
            }
        });

        return response()->json(['message' => 'Questions reordered.']);
    }
}
