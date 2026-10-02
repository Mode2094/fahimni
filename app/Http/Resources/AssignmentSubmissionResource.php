<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssignmentSubmissionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'assignment_id' => $this->assignment_id,
            'user_id' => $this->user_id,
            'student' => $this->whenLoaded('student', fn () => [
                'id' => $this->student->id,
                'name' => $this->student->name,
                'email' => $this->student->email,
            ]),
            'submission_text' => $this->submission_text,
            'file_url' => $this->file_url,
            'file_name' => $this->file_name,
            'file_size_bytes' => $this->file_size_bytes,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'status' => $this->status,
            'grade' => $this->grade,
            'grade_comment' => $this->grade_comment,
            'graded_at' => $this->graded_at?->toIso8601String(),
            'comments' => AssignmentCommentResource::collection($this->whenLoaded('comments')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
