<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Exam;
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
                // A practice exercise has no score/pass-fail; the client must
                // never render a grade for it. `status` (in_progress /
                // completed / submitted) is the authoritative state, and a
                // graded attempt still awaiting a teacher stays "pending".
                $isPractice = $attempt->assessment_mode === Exam::MODE_PRACTICE;

                return [
                    'id' => $attempt->id,
                    'exam_id' => $attempt->exam_id,
                    'exam_title' => $attempt->exam?->title,
                    'attempt_number' => $attempt->attempt_number,
                    'academic_term_id' => $attempt->academic_term_id,
                    'status' => $attempt->status,
                    'is_practice' => $isPractice,
                    'assessment_mode' => $attempt->assessment_mode,
                    'needs_review' => $isPractice ? false : (bool) $attempt->needs_review,
                    'score' => $isPractice ? null : (float) $attempt->score,
                    'total_points' => $isPractice ? null : (float) $attempt->total_points,
                    'percentage' => $isPractice ? null : (float) $attempt->percentage,
                    'passed' => $isPractice ? null : (bool) $attempt->passed,
                    'submitted_at' => $attempt->submitted_at?->toIso8601String(),
                    'completed_at' => $attempt->completed_at?->toIso8601String(),
                ];
            }),
        ]);
    }
}
