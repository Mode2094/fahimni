<?php

namespace App\Http\Requests\Bundle;

use Illuminate\Foundation\Http\FormRequest;

class StoreBundleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => 'required|string|max:191',
            'slug' => 'nullable|string|max:191|regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
            'description' => 'nullable|string|max:5000',
            'short_description' => 'nullable|string|max:500',
            'thumbnail_url' => 'nullable|string|max:500',
            'price' => 'nullable|numeric|min:0',
            'currency' => 'nullable|string|max:8',
            'is_published' => 'nullable|boolean',
            'is_featured' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0',
            'metadata' => 'nullable|array',
            'course_ids' => 'nullable|array|max:100',
            'course_ids.*' => 'string|exists:courses,id',
        ];
    }
}
