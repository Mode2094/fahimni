<?php

namespace App\Http\Requests\Lesson;

use App\Rules\TenantScopedExists;
use Illuminate\Foundation\Http\FormRequest;

class StoreLessonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'section_id' => ['required', 'string', new TenantScopedExists('sections')],
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'duration_minutes' => 'nullable|integer|min:0',
            'sort_order' => 'nullable|integer|min:0',
            'is_published' => 'nullable|boolean',
            'is_free_preview' => 'nullable|boolean',
            'lesson_type' => 'nullable|string|max:20',
            'metadata' => 'nullable|array',
        ];
    }
}
