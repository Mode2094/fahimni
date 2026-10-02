<?php

namespace App\Http\Requests\Plan;

use Illuminate\Foundation\Http\FormRequest;

class RecommendPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'answers' => ['required', 'array'],
            'locale' => ['sometimes', 'in:ar,en'],
        ];

        foreach (config('plan-recommender.questions') as $question) {
            $rules['answers.'.$question['key']] = ['required', 'string'];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'answers.required' => 'الإجابات مطلوبة.',
        ];
    }
}
