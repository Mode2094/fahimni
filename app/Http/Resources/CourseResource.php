<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CourseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'title' => $this->title,
            'slug' => $this->slug,
            'description' => $this->description,
            'short_description' => $this->short_description,
            'thumbnail_url' => $this->thumbnail_url,
            'cover_url' => $this->cover_url,
            'level' => $this->level,
            'category' => $this->category,
            'tags' => $this->tags,
            'duration_hours' => $this->duration_hours,
            'is_published' => $this->is_published,
            'is_featured' => $this->is_featured,
            'sort_order' => $this->sort_order,
            'price' => $this->price,
            'max_students' => $this->max_students,
            'completion_criteria' => $this->completion_criteria,
            'certificate_enabled' => $this->certificate_enabled,
            'certificate_template' => $this->certificate_template,
            'certificate_accent_color' => $this->certificate_accent_color,
            'certificate_signature_name' => $this->certificate_signature_name,
            'certificate_signature_title' => $this->certificate_signature_title,
            'prerequisites' => $this->prerequisites,
            'drip_enabled' => $this->drip_enabled,
            'drip_interval_days' => $this->drip_interval_days,
            'sections_count' => $this->whenCounted('sections'),
            'enrollments_count' => $this->whenCounted('enrollments'),
            'exams_count' => $this->whenCounted('exams'),
            'questions_count' => $this->whenCounted('questions'),
            'sections' => SectionResource::collection($this->whenLoaded('sections')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
