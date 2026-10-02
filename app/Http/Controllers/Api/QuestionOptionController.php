<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\QuestionOptionResource;
use App\Models\Question;
use App\Models\QuestionOption;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\Uid\UuidV7;

class QuestionOptionController extends Controller
{
    public function index(string $questionId): JsonResponse
    {
        $question = Question::findOrFail($questionId);

        $this->authorize('view', $question);

        $options = $question->options()->orderBy('sort_order')->get();

        return response()->json([
            'options' => QuestionOptionResource::collection($options),
        ]);
    }

    public function store(Request $request, string $questionId): JsonResponse
    {
        $question = Question::findOrFail($questionId);

        $this->authorize('update', $question);

        $validated = $request->validate([
            'option_text' => 'required|string',
            'is_correct' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $option = QuestionOption::create([
            'id' => UuidV7::generate(),
            'question_id' => $question->id,
            'tenant_id' => $question->tenant_id,
            'option_text' => $validated['option_text'],
            'is_correct' => $validated['is_correct'] ?? false,
            'sort_order' => $validated['sort_order'] ?? 0,
        ]);

        return response()->json([
            'option' => new QuestionOptionResource($option),
        ], 201);
    }

    public function update(Request $request, string $questionId, string $optionId): JsonResponse
    {
        $question = Question::findOrFail($questionId);

        $this->authorize('update', $question);

        $option = QuestionOption::where('question_id', $question->id)
            ->where('id', $optionId)
            ->firstOrFail();

        $validated = $request->validate([
            'option_text' => 'sometimes|required|string',
            'is_correct' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $option->update($validated);

        return response()->json([
            'option' => new QuestionOptionResource($option->fresh()),
        ]);
    }

    public function destroy(string $questionId, string $optionId): JsonResponse
    {
        $question = Question::findOrFail($questionId);

        $this->authorize('update', $question);

        $option = QuestionOption::where('question_id', $question->id)
            ->where('id', $optionId)
            ->firstOrFail();

        $option->delete();

        return response()->json(['message' => 'Option deleted successfully.']);
    }
}
