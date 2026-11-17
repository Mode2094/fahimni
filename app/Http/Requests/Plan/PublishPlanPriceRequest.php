<?php

namespace App\Http\Requests\Plan;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a "save & publish price" request from the Super Admin plans panel.
 *
 * At least one billing cycle must be supplied. Prices are always platform
 * USD and must be positive; the currency is never taken from the client.
 */
class PublishPlanPriceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // guarded by the admin middleware + SubscriptionPlanPolicy
    }

    public function rules(): array
    {
        return [
            'monthly_price' => 'nullable|numeric|min:0.01|max:1000000',
            'yearly_price' => 'nullable|numeric|min:0.01|max:1000000',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($this->input('monthly_price') === null && $this->input('yearly_price') === null) {
                $validator->errors()->add('monthly_price', 'Provide a monthly and/or a yearly price.');
            }
        });
    }
}
