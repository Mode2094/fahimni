<?php

namespace App\Http\Requests\Plan;

use Illuminate\Foundation\Http\FormRequest;

class UpdateFeatureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'feature_key' => 'nullable|string|max:100',
            'feature_value' => 'nullable|string',
            'feature_type' => 'nullable|string|max:20',
            'sort_order' => 'nullable|integer|min:0',
        ];
    }
}
