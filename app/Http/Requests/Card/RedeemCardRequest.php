<?php

namespace App\Http\Requests\Card;

use Illuminate\Foundation\Http\FormRequest;

class RedeemCardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => 'required|string|max:50',
        ];
    }
}
