<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Plan\RecommendPlanRequest;
use App\Support\AuditLogger;
use App\Support\PlanRecommender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlanRecommenderController extends Controller
{
    public function __construct(private readonly PlanRecommender $recommender) {}

    /**
     * أسئلة مساعد اختيار الخطة الأنسب (للواجهة).
     */
    public function questions(Request $request): JsonResponse
    {
        return response()->json([
            'questions' => $this->recommender->questions(),
        ]);
    }

    /**
     * احسب الخطة الأنسب بناءً على إجابات المعلم.
     */
    public function recommend(RecommendPlanRequest $request): JsonResponse
    {
        $result = $this->recommender->recommend($request->answers);

        if (! empty($result['unanswered'])) {
            return response()->json([
                'message' => 'Some questions are unanswered.',
                'unanswered' => $result['unanswered'],
            ], 422);
        }

        if ($result['recommended'] === null) {
            return response()->json([
                'message' => 'No active plans available to recommend.',
            ], 404);
        }

        AuditLogger::log(
            $request,
            'plan.recommended',
            'plan',
            $result['recommended']['id'],
            ['answers' => $request->answers]
        );

        return response()->json($result);
    }
}
