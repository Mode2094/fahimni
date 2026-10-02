<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExamAnswerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isStaff = false;
        $user = $request->user();

        if ($user) {
            $exam = $this->whenLoaded('question') ? $this->question?->exams?->first() : null;
            $isStaff = $user->is_super_admin || $this->isTenantStaff($request, $this->tenant_id ?? null);
        }

        return [
            'id' => $this->id,
            'exam_attempt_id' => $this->exam_attempt_id,
            'exam_question_id' => $this->exam_question_id,
            'question_id' => $this->question_id,
            'given_answer' => $this->given_answer,
            'is_correct' => $this->when($isStaff, $this->is_correct),
            'points_earned' => $this->points_earned,
            'points_max' => $this->points_max,
            'time_spent_seconds' => $this->time_spent_seconds,
            'question' => $this->whenLoaded('question', fn () => $this->when(
                $isStaff,
                new QuestionResource($this->question)
            )),
        ];
    }

    private function isTenantStaff(Request $request, ?string $tenantId): bool
    {
        $user = $request->user();

        if (! $user || ! $tenantId) {
            return false;
        }

        return $user->tenantPivots()
            ->where('tenant_id', $tenantId)
            ->whereIn('role', ['teacher', 'owner', 'admin'])
            ->where('status', 'active')
            ->exists();
    }
}
