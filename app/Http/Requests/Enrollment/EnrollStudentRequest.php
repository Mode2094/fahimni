<?php

namespace App\Http\Requests\Enrollment;

use Illuminate\Foundation\Http\FormRequest;

class EnrollStudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'course_id' => 'required|string|exists:courses,id',
            'user_id' => 'required|string|exists:users,id',
            'expires_at' => 'nullable|date|after:today',
            'source' => 'nullable|string|max:20',
            'metadata' => 'nullable|array',
        ];
    }
}
