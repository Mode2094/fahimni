<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExamAttemptResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'exam_id' => $this->exam_id,
            'user_id' => $this->user_id,
            'attempt_number' => $this->attempt_number,
            'status' => $this->status,
            'score' => $this->score,
            'total_points' => $this->total_points,
            'percentage' => $this->percentage,
            'passed' => $this->passed,
            'needs_review' => (bool) $this->needs_review,
            'reviewed_at' => $this->reviewed_at,
            'started_at' => $this->started_at,
            'submitted_at' => $this->submitted_at,
            'time_spent_seconds' => $this->time_spent_seconds,
            'exam' => new ExamResource($this->whenLoaded('exam')),
            'student' => new UserResource($this->whenLoaded('student')),
            'answers' => ExamAnswerResource::collection($this->whenLoaded('answers')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
