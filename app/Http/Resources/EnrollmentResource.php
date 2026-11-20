<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EnrollmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'course_id' => $this->course_id,
            'user_id' => $this->user_id,
            'academic_term_id' => $this->academic_term_id,
            'status' => $this->status,
            'progress_percent' => $this->progress_percent,
            'enrolled_at' => $this->enrolled_at,
            'completed_at' => $this->completed_at,
            'expires_at' => $this->expires_at,
            'archived_at' => $this->archived_at,
            'source' => $this->source,
            'is_effective' => $this->isEffective(),
            'course' => new CourseResource($this->whenLoaded('course')),
            'student' => new UserResource($this->whenLoaded('student')),
            'academic_term' => $this->whenLoaded('academicTerm', fn () => [
                'id' => $this->academicTerm->id,
                'name' => $this->academicTerm->name,
                'status' => $this->academicTerm->status,
            ]),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
