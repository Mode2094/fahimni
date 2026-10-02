<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssignmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();

        $mySubmission = null;
        if ($user && $this->relationLoaded('submissions')) {
            $found = $this->submissions->firstWhere('user_id', $user->id);
            if ($found) {
                $mySubmission = AssignmentSubmissionResource::make($found)->resolve();
            }
        }

        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'course_id' => $this->course_id,
            'section_id' => $this->section_id,
            'title' => $this->title,
            'description' => $this->description,
            'points' => $this->points,
            'due_at' => $this->due_at?->toIso8601String(),
            'allow_late' => (bool) $this->allow_late,
            'is_published' => (bool) $this->is_published,
            'sort_order' => $this->sort_order,
            'file_url' => $this->file_url,
            'file_name' => $this->file_name,
            'file_size_bytes' => $this->file_size_bytes,
            'submissions_count' => $this->whenCounted('submissions'),
            'submissions' => AssignmentSubmissionResource::collection($this->whenLoaded('submissions')),
            'my_submission' => $mySubmission,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
