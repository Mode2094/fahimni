<?php

namespace App\Http\Requests\Question;

use App\Models\Question;
use App\Rules\TenantScopedExists;
use App\Services\InteractiveHtmlGrader;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateQuestionRequest extends FormRequest
{
    /** Resolved once per request; see effectiveType(). */
    private bool $typeResolved = false;

    private ?string $resolvedType = null;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * The type the question will have after this update. A partial update may
     * omit `question_type`, so we fall back to the persisted type — otherwise
     * the interactive_html rules would silently not apply.
     */
    private function effectiveType(): ?string
    {
        if ($this->typeResolved) {
            return $this->resolvedType;
        }

        $this->typeResolved = true;

        $type = $this->input('question_type');
        if (! is_string($type) || $type === '') {
            $type = Question::query()->whereKey($this->route('question'))->value('question_type');
        }

        return $this->resolvedType = is_string($type) ? $type : null;
    }

    public function rules(): array
    {
        $questionType = $this->effectiveType();

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
            // Every metadata key the app uses needs a rule, because the
            // controller persists the *validated* metadata (so an oversized or
            // unknown key can never be smuggled through the raw payload).
            'metadata.image_url' => 'nullable|string|max:2048',
            'metadata.pairs' => 'nullable|array|max:200',
            'metadata.pairs.*.left' => 'nullable|string|max:1000',
            'metadata.pairs.*.right' => 'nullable|string|max:1000',
            'metadata.items' => 'nullable|array|max:200',
            'metadata.items.*' => 'nullable|string|max:2000',
            'metadata.answers' => 'nullable|array|max:200',
            'metadata.answers.*' => 'nullable|string|max:2000',
            'metadata.keywords' => 'nullable|array|max:100',
            'metadata.keywords.*' => 'nullable|string|max:100',
            // Options are replaced wholesale when present, mirroring the create
            // path so editing an MCQ actually persists its answers.
            'options' => 'sometimes|array|min:2',
            'options.*.option_text' => 'required|string|max:5000',
            'options.*.is_correct' => 'nullable|boolean',
            'options.*.sort_order' => 'nullable|integer|min:0',
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
            if ($this->effectiveType() !== 'interactive_html' || ! $this->exists('metadata')) {
                return;
            }

            // Metadata is a full replacement on update, so an interactive
            // payload must be present — it may not be dropped to bypass the
            // size/shape checks or to strip the hidden grading rule.
            if (! is_array($this->input('metadata.interactive'))) {
                $validator->errors()->add(
                    'metadata.interactive',
                    'An interactive question update must include its HTML payload.'
                );
            }

            if ($this->input('metadata.grading.mode') === InteractiveHtmlGrader::MODE_AUTO
                && $this->input('metadata.grading.key') === null) {
                $validator->errors()->add(
                    'metadata.grading.key',
                    'An auto-graded interactive question must define a hidden answer key.'
                );
            }
        });

        $validator->after(function ($validator) {
            if (! $this->has('options') || ! in_array($this->effectiveType(), ['multiple_choice', 'true_false'], true)) {
                return;
            }

            $hasCorrect = collect($this->input('options', []))
                ->contains(fn ($o) => (bool) ($o['is_correct'] ?? false));

            if (! $hasCorrect) {
                $validator->errors()->add('options', 'At least one option must be marked as correct.');
            }
        });
    }
}
