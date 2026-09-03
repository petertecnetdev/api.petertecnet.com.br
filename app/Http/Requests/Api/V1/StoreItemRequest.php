<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class StoreItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth('api')->check();
    }

    public function rules(): array
    {
        return [
            'establishment_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['nullable', 'string', 'max:80'],
            'sku' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'duration' => ['nullable', 'integer', 'min:0'],
            'price' => ['required', 'numeric', 'min:0'],
            'stock' => ['nullable', 'integer', 'min:0'],
            'status' => ['nullable', 'boolean'],
            'limited_by_user' => ['nullable', 'boolean'],
            'category' => ['nullable', 'string', 'max:120'],
            'subcategory' => ['nullable', 'string', 'max:120'],
            'brand' => ['nullable', 'string', 'max:120'],
            'availability_start' => ['nullable', 'date'],
            'availability_end' => ['nullable', 'date', 'after_or_equal:availability_start'],
            'tags' => ['nullable', 'array'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'expiration_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'is_featured' => ['nullable', 'boolean'],
            'display_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ];
    }
}
