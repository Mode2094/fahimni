<?php

namespace App\Http\Requests\Subscription;

use Illuminate\Foundation\Http\FormRequest;

class SubscribeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'plan_id' => 'required|string|exists:subscription_plans,id',
            'billing_cycle' => 'nullable|in:monthly,yearly',
        ];
    }
}
