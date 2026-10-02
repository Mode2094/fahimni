<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LessonResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'section_id' => $this->section_id,
            'title' => $this->title,
            'description' => $this->description,
            'duration_minutes' => $this->duration_minutes,
            'sort_order' => $this->sort_order,
            'is_published' => $this->is_published,
            'is_free_preview' => $this->is_free_preview,
            'lesson_type' => $this->lesson_type,
            'contents_count' => $this->whenCounted('contents'),
            'contents' => LessonContentResource::collection($this->whenLoaded('contents')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
