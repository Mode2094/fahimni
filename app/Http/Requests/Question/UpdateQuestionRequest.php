<?php

namespace App\Http\Requests\Question;

use App\Rules\TenantScopedExists;
use Illuminate\Foundation\Http\FormRequest;

class UpdateQuestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'course_id' => ['nullable', 'string', new TenantScopedExists('courses')],
            'section_id' => ['nullable', 'string', new TenantScopedExists('sections')],
            'question_type' => 'sometimes|required|string|in:multiple_choice,true_false,drag_drop,matching,ordering,writing,fill_blank,file_upload',
            'question_text' => 'sometimes|required|string',
            'difficulty' => 'nullable|string|in:easy,medium,hard|max:10',
            'category' => 'nullable|string|max:100',
            'points' => 'nullable|numeric|min:0',
            'explanation' => 'nullable|string',
            'is_active' => 'nullable|boolean',
            'metadata' => 'nullable|array',
        ];
    }
}
