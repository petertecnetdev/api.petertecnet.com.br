<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class UpdateItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth('api')->check();
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'type' => ['sometimes', 'nullable', 'string', 'max:80'],
            'sku' => ['sometimes', 'nullable', 'string', 'max:100'],
            'description' => ['sometimes', 'nullable', 'string'],
            'duration' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'price' => ['sometimes', 'numeric', 'min:0'],
            'stock' => ['sometimes', 'integer', 'min:0'],
            'status' => ['sometimes', 'boolean'],
            'limited_by_user' => ['sometimes', 'boolean'],
            'category' => ['sometimes', 'nullable', 'string', 'max:120'],
            'subcategory' => ['sometimes', 'nullable', 'string', 'max:120'],
            'brand' => ['sometimes', 'nullable', 'string', 'max:120'],
            'availability_start' => ['sometimes', 'nullable', 'date'],
            'availability_end' => ['sometimes', 'nullable', 'date'],
            'tags' => ['sometimes', 'nullable', 'array'],
            'discount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'expiration_date' => ['sometimes', 'nullable', 'date'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'is_featured' => ['sometimes', 'boolean'],
            'display_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
        ];
    }
}
