<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
            'locale' => 'sometimes|string|in:ar,en',
            'tenant_slug' => 'sometimes|string|max:255',
            'phone' => 'sometimes|nullable|string|max:20',
        ];
    }
}
