<?php

namespace App\Http\Requests\Question;

use App\Models\Question;
use App\Rules\TenantScopedExists;
use App\Services\InteractiveHtmlGrader;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateQuestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $questionType = $this->input('question_type');

        $rules = [
            'course_id' => ['nullable', 'string', new TenantScopedExists('courses')],
            'section_id' => ['nullable', 'string', new TenantScopedExists('sections')],
            'question_type' => 'sometimes|required|string|in:'.implode(',', Question::TYPES),
            'question_text' => 'sometimes|required|string',
            'difficulty' => 'nullable|string|in:easy,medium,hard|max:10',
            'category' => 'nullable|string|max:100',
            'points' => 'nullable|numeric|min:0',
            'explanation' => 'nullable|string',
            'is_active' => 'nullable|boolean',
            'metadata' => 'nullable|array',
        ];

        // Type-specific completeness, mirroring StoreQuestionRequest so an
        // update can never leave an interactive question without its payload.
        return match ($questionType) {
            'interactive_html' => [
                ...$rules,
                'metadata.interactive' => 'sometimes|required|array',
                'metadata.interactive.html' => 'sometimes|required|string|max:200000',
                'metadata.interactive.css' => 'nullable|string|max:100000',
                'metadata.interactive.js' => 'nullable|string|max:100000',
                'metadata.interactive.height' => 'nullable|integer|min:120|max:1600',
                'metadata.interactive.auto_capture' => 'nullable|boolean',
                'metadata.grading' => 'nullable|array',
                'metadata.grading.mode' => 'required_with:metadata.grading|in:auto,manual',
                'metadata.grading.rule' => 'nullable|in:'.implode(',', InteractiveHtmlGrader::RULES),
                'metadata.grading.key' => 'nullable',
                'metadata.grading.tolerance' => 'nullable|numeric|min:0',
            ],
            default => $rules,
        };
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator) {
            if ($this->input('question_type') !== 'interactive_html') {
                return;
            }

            if ($this->input('metadata.grading.mode') === InteractiveHtmlGrader::MODE_AUTO
                && $this->input('metadata.grading.key') === null) {
                $validator->errors()->add(
                    'metadata.grading.key',
                    'An auto-graded interactive question must define a hidden answer key.'
                );
            }
        });
    }
}
