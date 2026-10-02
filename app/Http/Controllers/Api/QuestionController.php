<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Question\StoreQuestionRequest;
use App\Http\Requests\Question\UpdateQuestionRequest;
use App\Http\Resources\QuestionResource;
use App\Models\Exam;
use App\Models\ExamQuestion;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Plans\QuotaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\Uid\UuidV7;

class QuestionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Question::class);

        $questions = Question::with(['options', 'exams'])
            ->when($request->filled('course_id'), fn ($q) => $q->where('course_id', $request->course_id))
            ->when($request->filled('section_id'), fn ($q) => $q->where('section_id', $request->section_id))
            ->when($request->filled('question_type'), fn ($q) => $q->where('question_type', $request->question_type))
            ->when($request->filled('difficulty'), fn ($q) => $q->where('difficulty', $request->difficulty))
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->category))
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'questions' => QuestionResource::collection($questions),
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $question = Question::with('options')->findOrFail($id);

        $this->authorize('view', $question);

        return response()->json([
            'question' => new QuestionResource($question),
        ]);
    }

    public function store(StoreQuestionRequest $request): JsonResponse
    {
        $this->authorize('create', Question::class);

        app(QuotaService::class)->assertCan($request->current_tenant->id, 'questions');

        $question = Question::create([
            'id' => UuidV7::generate(),
            'course_id' => $request->course_id,
            'section_id' => $request->section_id,
            'question_type' => $request->question_type,
            'question_text' => $request->question_text,
            'difficulty' => $request->input('difficulty', 'medium'),
            'category' => $request->category,
            'points' => $request->input('points', 1),
            'explanation' => $request->explanation,
            'is_active' => $request->input('is_active', true),
            'metadata' => $request->input('metadata', []),
        ]);

        if ($request->question_type === 'multiple_choice' && $request->has('options')) {
            foreach ($request->options as $opt) {
                QuestionOption::create([
                    'id' => UuidV7::generate(),
                    'question_id' => $question->id,
                    'tenant_id' => $question->tenant_id,
                    'option_text' => $opt['option_text'],
                    'is_correct' => $opt['is_correct'] ?? false,
                    'sort_order' => $opt['sort_order'] ?? 0,
                ]);
            }
        } elseif ($request->question_type === 'true_false') {
            QuestionOption::create(['id' => UuidV7::generate(), 'question_id' => $question->id, 'tenant_id' => $question->tenant_id, 'option_text' => 'True', 'is_correct' => true, 'sort_order' => 0]);
            QuestionOption::create(['id' => UuidV7::generate(), 'question_id' => $question->id, 'tenant_id' => $question->tenant_id, 'option_text' => 'False', 'is_correct' => false, 'sort_order' => 1]);
        }

        if ($request->filled('exam_id')) {
            // The exam must belong to the same tenant before attaching.
            Exam::where('tenant_id', $request->current_tenant->id)
                ->findOrFail($request->exam_id);

            ExamQuestion::firstOrCreate(
                ['exam_id' => $request->exam_id, 'question_id' => $question->id],
                ['tenant_id' => $question->tenant_id, 'id' => UuidV7::generate(), 'sort_order' => 0]
            );
        }

        return response()->json([
            'question' => new QuestionResource($question->load('options')),
        ], 201);
    }

    public function update(UpdateQuestionRequest $request, string $id): JsonResponse
    {
        $question = Question::findOrFail($id);

        $this->authorize('update', $question);

        $question->update($request->validated());

        return response()->json([
            'question' => new QuestionResource($question->fresh()->load('options')),
        ]);
    }

    public function destroy(string $id): JsonResponse
    {
        $question = Question::findOrFail($id);

        $this->authorize('delete', $question);

        $question->delete();

        return response()->json(['message' => 'Question deleted successfully.']);
    }
}
