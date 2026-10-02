<?php

namespace App\Http\Requests\Card;

use Illuminate\Foundation\Http\FormRequest;

class GenerateCardsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'course_id' => 'required|string|exists:courses,id',
            'count' => 'required|integer|min:1|max:1000',
            'batch_id' => 'nullable|string|max:100',
            'expires_at' => 'nullable|date',
            'metadata' => 'nullable|array',
        ];
    }
}
