<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ExamSectionResource;
use App\Models\Exam;
use App\Models\ExamSection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\Uid\UuidV7;

class ExamSectionController extends Controller
{
    public function index(string $examId): JsonResponse
    {
        $exam = Exam::findOrFail($examId);

        $this->authorize('view', $exam);

        $sections = $exam->sections()->withCount('examQuestions')->get();

        return response()->json([
            'sections' => ExamSectionResource::collection($sections),
        ]);
    }

    public function store(Request $request, string $examId): JsonResponse
    {
        $exam = Exam::findOrFail($examId);

        $this->authorize('update', $exam);

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'instructions' => 'nullable|string',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $section = ExamSection::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $exam->tenant_id,
            'exam_id' => $exam->id,
            'title' => $validated['title'],
            'instructions' => $validated['instructions'] ?? null,
            'sort_order' => $validated['sort_order'] ?? 0,
        ]);

        return response()->json([
            'section' => new ExamSectionResource($section),
        ], 201);
    }

    public function update(Request $request, string $examId, string $sectionId): JsonResponse
    {
        $exam = Exam::findOrFail($examId);

        $this->authorize('update', $exam);

        $section = ExamSection::where('exam_id', $exam->id)
            ->where('id', $sectionId)
            ->firstOrFail();

        $validated = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'instructions' => 'nullable|string',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $section->update($validated);

        return response()->json([
            'section' => new ExamSectionResource($section->fresh()),
        ]);
    }

    public function destroy(string $examId, string $sectionId): JsonResponse
    {
        $exam = Exam::findOrFail($examId);

        $this->authorize('update', $exam);

        $section = ExamSection::where('exam_id', $exam->id)
            ->where('id', $sectionId)
            ->firstOrFail();

        $section->delete();

        return response()->json(['message' => 'Exam section deleted successfully.']);
    }
}
