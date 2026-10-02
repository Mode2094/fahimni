<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExamAttempt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentAttemptController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $attempts = ExamAttempt::where('user_id', $user->id)
            ->with(['exam'])
            ->orderByDesc('submitted_at')
            ->get();

        return response()->json([
            'attempts' => $attempts->map(function (ExamAttempt $attempt) {
                return [
                    'id' => $attempt->id,
                    'exam_id' => $attempt->exam_id,
                    'exam_title' => $attempt->exam?->title,
                    'attempt_number' => $attempt->attempt_number,
                    'status' => $attempt->status,
                    'score' => (float) $attempt->score,
                    'total_points' => (float) $attempt->total_points,
                    'percentage' => (float) $attempt->percentage,
                    'passed' => (bool) $attempt->passed,
                    'submitted_at' => $attempt->submitted_at?->toIso8601String(),
                ];
            }),
        ]);
    }
}
