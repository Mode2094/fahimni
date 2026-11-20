<?php

namespace App\Http\Resources;

use App\Support\SkillPerformance;
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
            'academic_term_id' => $this->academic_term_id,
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
            // How many answers are waiting for the teacher's manual score —
            // derived from real rows (an answer with no boolean verdict).
            'manual_review_count' => $this->whenLoaded(
                'answers',
                fn () => $this->answers->whereNull('is_correct')->count()
            ),
            // Per-skill accuracy/level computed from the actual graded answers
            // against the skills the teacher attached to the questions.
            'skills' => $this->whenLoaded('answers', fn () => $this->skillSummary()),
            'exam' => new ExamResource($this->whenLoaded('exam')),
            'course' => $this->whenLoaded('exam', fn () => $this->when(
                $this->exam && $this->exam->relationLoaded('course') && $this->exam->course,
                fn () => [
                    'id' => $this->exam->course->id,
                    'title' => $this->exam->course->title,
                ]
            )),
            'section' => $this->whenLoaded('exam', fn () => $this->when(
                $this->exam && $this->exam->relationLoaded('section') && $this->exam->section,
                fn () => [
                    'id' => $this->exam->section->id,
                    'title' => $this->exam->section->title,
                ]
            )),
            'student' => new UserResource($this->whenLoaded('student')),
            'answers' => ExamAnswerResource::collection($this->whenLoaded('answers')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    /**
     * Aggregate a student's answers per skill. Only questions that actually
     * carry `metadata.skills` contribute — a question without a skill is
     * simply skipped, never guessed.
     *
     * @return list<array{name: string, points_earned: float, points_max: float, accuracy: float, level: string}>
     */
    private function skillSummary(): array
    {
        $buckets = [];

        foreach ($this->answers as $answer) {
            $question = $answer->relationLoaded('question') ? $answer->question : null;
            if (! $question) {
                continue;
            }

            foreach (SkillPerformance::skillsOf($question->metadata ?? []) as $skill) {
                $buckets[$skill] ??= ['points_earned' => 0.0, 'points_max' => 0.0];
                $buckets[$skill]['points_earned'] += (float) $answer->points_earned;
                $buckets[$skill]['points_max'] += (float) $answer->points_max;
            }
        }

        return SkillPerformance::summarise($buckets);
    }
}
