<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProgressResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'enrollment_id' => $this->enrollment_id,
            'lesson_id' => $this->lesson_id,
            'user_id' => $this->user_id,
            'status' => $this->status,
            'watch_time_seconds' => $this->watch_time_seconds,
            'completed_at' => $this->completed_at,
            'lesson' => new LessonResource($this->whenLoaded('lesson')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
