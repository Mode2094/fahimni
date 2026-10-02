<?php

namespace App\Http\Requests\Course;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCourseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => 'sometimes|required|string|max:255',
            'slug' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'short_description' => 'nullable|string|max:500',
            'thumbnail_url' => 'nullable|string|max:500',
            'cover_url' => 'nullable|string|max:500',
            'level' => 'nullable|string|in:beginner,intermediate,advanced,all|max:20',
            'category' => 'nullable|string|max:100',
            'tags' => 'nullable|string',
            'duration_hours' => 'nullable|numeric|min:0',
            'is_published' => 'nullable|boolean',
            'is_featured' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0',
            'price' => 'nullable|numeric|min:0',
            'max_students' => 'nullable|integer|min:0',
            'completion_criteria' => 'nullable|string|max:20',
            'certificate_enabled' => 'nullable|boolean',
            'certificate_template' => 'nullable|string|in:classic,modern,minimal|max:40',
            'certificate_accent_color' => 'nullable|string|max:20',
            'certificate_signature_name' => 'nullable|string|max:255',
            'certificate_signature_title' => 'nullable|string|max:255',
            'prerequisites' => 'nullable|string|max:5000',
            'drip_enabled' => 'nullable|boolean',
            'drip_interval_days' => 'nullable|integer|min:1|max:365',
            'metadata' => 'nullable|array',
        ];
    }
}
