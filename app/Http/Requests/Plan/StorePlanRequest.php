<?php

namespace App\Http\Requests\Plan;

use Illuminate\Foundation\Http\FormRequest;

class StorePlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'slug' => 'required|string|max:100|unique:subscription_plans,slug',
            'description' => 'nullable|string',
            'monthly_price' => 'required|numeric|min:0',
            'yearly_price' => 'nullable|numeric|min:0',
            'currency' => 'nullable|string|size:3',
            'trial_days' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
            'is_public' => 'nullable|boolean',
            'is_popular' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0',
            'paddle_price_id_monthly' => 'nullable|string|max:100',
            'paddle_price_id_yearly' => 'nullable|string|max:100',
            'features' => 'nullable|array',
            'features.*.feature_key' => 'required|string|max:100',
            'features.*.feature_value' => 'required|string',
            'features.*.feature_type' => 'nullable|string|max:20',
            'features.*.sort_order' => 'nullable|integer|min:0',
        ];
    }
}
