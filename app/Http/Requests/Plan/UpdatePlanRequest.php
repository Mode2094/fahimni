<?php

namespace App\Http\Requests\Plan;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $planId = $this->route('plan');

        return [
            'name' => 'nullable|string|max:255',
            'slug' => 'nullable|string|max:100|unique:subscription_plans,slug,'.$planId,
            'description' => 'nullable|string',
            'monthly_price' => 'nullable|numeric|min:0',
            'yearly_price' => 'nullable|numeric|min:0',
            'currency' => 'nullable|string|size:3',
            'trial_days' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
            'is_public' => 'nullable|boolean',
            'is_popular' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0',
            'paddle_price_id_monthly' => 'nullable|string|max:100',
            'paddle_price_id_yearly' => 'nullable|string|max:100',
            'lemon_variant_id_monthly' => 'nullable|string|max:20',
            'lemon_variant_id_yearly' => 'nullable|string|max:20',
        ];
    }
}
