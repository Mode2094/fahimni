<?php

namespace App\Http\Requests\Auth;

use App\Rules\ValidPhoneNumber;
use App\Support\PhoneNumber;
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
            'email' => 'required|email|max:255|unique:users,email,NULL,id,deleted_at,NULL',
            'password' => 'required|string|min:8|confirmed',
            'locale' => 'sometimes|string|in:ar,en',
            'tenant_slug' => 'sometimes|string|max:255',
            'role' => 'sometimes|string|in:Student,Teacher',
            // Teachers MUST provide a phone; students may omit it.
            'phone' => ['required_if:role,Teacher', 'nullable', 'string', 'max:25', new ValidPhoneNumber],
        ];
    }

    /**
     * Store one canonical E.164 form. Validating and normalizing on the way in
     * means the DB never holds a raw, country-ambiguous number.
     */
    protected function prepareForValidation(): void
    {
        $phone = $this->input('phone');

        if (is_string($phone) && trim($phone) !== '') {
            $this->merge(['phone' => PhoneNumber::toE164($phone) ?? $phone]);
        }
    }
}
