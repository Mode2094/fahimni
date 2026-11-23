<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AcademicTermResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'status' => $this->status,
            'derived_status' => $this->derivedStatus(),
            'starts_at' => $this->starts_at,
            'ends_at' => $this->ends_at,
            'archived_at' => $this->archived_at,
            'students_count' => $this->whenCounted('enrollments'),
            'enrollments_count' => $this->when(
                isset($this->enrollments_count) || $this->relationLoaded('enrollments'),
                fn () => $this->relationLoaded('enrollments')
                    ? $this->enrollments->count()
                    : $this->enrollments_count
            ),
            'metadata' => $this->metadata,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
