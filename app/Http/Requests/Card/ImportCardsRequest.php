<?php

namespace App\Http\Requests\Card;

use Illuminate\Foundation\Http\FormRequest;

class ImportCardsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'course_id' => 'required|string|exists:courses,id',
            'file' => 'required|file|max:10240',
            'batch_id' => 'nullable|string|max:100',
            'expires_at' => 'nullable|date',
            'metadata' => 'nullable|array',
        ];
    }
}
