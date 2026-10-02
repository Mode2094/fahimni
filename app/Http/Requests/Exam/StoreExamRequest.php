<?php

namespace App\Http\Requests\Exam;

use App\Rules\TenantScopedExists;
use Illuminate\Foundation\Http\FormRequest;

class StoreExamRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'course_id' => ['required', 'string', new TenantScopedExists('courses')],
            'section_id' => ['nullable', 'string', new TenantScopedExists('sections')],
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'duration_minutes' => 'nullable|integer|min:1',
            'pass_mark_percent' => 'nullable|numeric|min:0|max:100',
            'max_attempts' => 'nullable|integer|min:0',
            'random_questions_count' => 'nullable|integer|min:1',
            'anti_cheat_enabled' => 'nullable|boolean',
            'shuffle_questions' => 'nullable|boolean',
            'shuffle_options' => 'nullable|boolean',
            'show_result_immediately' => 'nullable|boolean',
            'show_correct_answers' => 'nullable|boolean',
            'is_published' => 'nullable|boolean',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date|after:starts_at',
            'sort_order' => 'nullable|integer|min:0',
            'metadata' => 'nullable|array',
        ];
    }
}
