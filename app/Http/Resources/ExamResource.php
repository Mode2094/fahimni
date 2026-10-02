<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExamResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'course_id' => $this->course_id,
            'section_id' => $this->section_id,
            'title' => $this->title,
            'description' => $this->description,
            'duration_minutes' => $this->duration_minutes,
            'pass_mark_percent' => $this->pass_mark_percent,
            'max_attempts' => $this->max_attempts,
            'random_questions_count' => $this->random_questions_count,
            'anti_cheat_enabled' => $this->anti_cheat_enabled,
            'shuffle_questions' => $this->shuffle_questions,
            'shuffle_options' => $this->shuffle_options,
            'show_result_immediately' => $this->show_result_immediately,
            'show_correct_answers' => $this->show_correct_answers,
            'is_published' => $this->is_published,
            'starts_at' => $this->starts_at,
            'ends_at' => $this->ends_at,
            'sort_order' => $this->sort_order,
            'questions_count' => $this->whenCounted('questions'),
            'attempts_count' => $this->whenCounted('attempts'),
            'sections' => ExamSectionResource::collection($this->whenLoaded('sections')),
            'questions' => QuestionResource::collection($this->whenLoaded('questions')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
