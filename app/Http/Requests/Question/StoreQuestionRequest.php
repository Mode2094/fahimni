<?php

namespace App\Http\Requests\Question;

use App\Models\Question;
use App\Rules\TenantScopedExists;
use App\Services\InteractiveHtmlGrader;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreQuestionRequest extends FormRequest
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
            'question_type' => 'required|string|in:'.implode(',', Question::TYPES),
            'question_text' => 'required|string',
            'difficulty' => 'nullable|string|in:easy,medium,hard|max:10',
            'category' => 'nullable|string|max:100',
            'points' => 'nullable|numeric|min:0',
            'explanation' => 'nullable|string',
            'is_active' => 'nullable|boolean',
            'metadata' => 'nullable|array',
            'options' => 'required_if:question_type,multiple_choice|array|min:2',
            'options.*.option_text' => 'required|string',
            'options.*.is_correct' => 'nullable|boolean',
            'options.*.sort_order' => 'nullable|integer|min:0',
        ];

        // Correctness data must be present for interactive types, otherwise
        // the question is unanswerable / auto-grades to zero forever.
        return match ($questionType) {
            'multiple_choice', 'true_false' => [
                ...$rules,
                'options' => 'required|array|min:2',
                // At least one option must be marked correct.
                'options.*.is_correct' => 'required|boolean',
            ],
            'drag_drop', 'matching' => [
                ...$rules,
                'metadata.pairs' => 'required|array|min:1',
                'metadata.pairs.*.left' => 'required|string',
                'metadata.pairs.*.right' => 'required|string',
            ],
            'ordering' => [
                ...$rules,
                'metadata.items' => 'required|array|min:2',
                'metadata.items.*' => 'required|string',
            ],
            'fill_blank' => [
                ...$rules,
                'metadata.answers' => 'required|array|min:1',
                'metadata.answers.*' => 'required|string',
            ],
            'writing' => [
                ...$rules,
                'metadata.keywords' => 'sometimes|array',
                'metadata.keywords.*' => 'required|string|max:100',
            ],
            'interactive_html' => [
                ...$rules,
                // Display payload — the only part sent to a student.
                'metadata.interactive' => 'required|array',
                'metadata.interactive.html' => 'required|string|max:200000',
                'metadata.interactive.css' => 'nullable|string|max:100000',
                'metadata.interactive.js' => 'nullable|string|max:100000',
                'metadata.interactive.height' => 'nullable|integer|min:120|max:1600',
                'metadata.interactive.auto_capture' => 'nullable|boolean',
                // Hidden grading rule — never serialized to a student.
                'metadata.grading' => 'nullable|array',
                'metadata.grading.mode' => 'required_with:metadata.grading|in:auto,manual',
                'metadata.grading.rule' => 'nullable|in:'.implode(',', InteractiveHtmlGrader::RULES),
                'metadata.grading.key' => 'nullable',
                'metadata.grading.tolerance' => 'nullable|numeric|min:0',
            ],
            default => $rules,
        };
    }

    /**
     * Enforce "at least one correct option" for multiple choice server-side
     * (the boolean rule alone allows all-false option sets), and require a
     * hidden answer key whenever an interactive_html question claims to be
     * auto-graded.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator) {
            if ($this->input('question_type') === 'interactive_html'
                && $this->input('metadata.grading.mode') === InteractiveHtmlGrader::MODE_AUTO
                && $this->input('metadata.grading.key') === null) {
                $validator->errors()->add(
                    'metadata.grading.key',
                    'An auto-graded interactive question must define a hidden answer key.'
                );
            }

            if (! in_array($this->input('question_type'), ['multiple_choice', 'true_false'], true)) {
                return;
            }

            $options = $this->input('options', []);
            $hasCorrect = collect($options)->contains(fn ($o) => (bool) ($o['is_correct'] ?? false));

            if (! $hasCorrect) {
                $validator->errors()->add('options', 'At least one option must be marked as correct.');
            }
        });
    }
}
