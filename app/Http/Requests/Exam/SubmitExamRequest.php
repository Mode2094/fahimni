<?php

namespace App\Http\Requests\Exam;

use Illuminate\Foundation\Http\FormRequest;

class SubmitExamRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'answers' => 'required|array|min:1',
            'answers.*.exam_question_id' => 'required|string|exists:exam_questions,id',
            'answers.*.question_id' => 'required|string|exists:questions,id',
            'answers.*.given_answer' => 'required',
            'answers.*.time_spent_seconds' => 'nullable|integer|min:0',
            'time_spent_seconds' => 'nullable|integer|min:0',
            'anti_cheat_violations' => 'nullable|integer|min:0',
        ];
    }
}
