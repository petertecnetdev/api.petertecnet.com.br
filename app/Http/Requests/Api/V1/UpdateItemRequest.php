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
            'short_description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'pricing_model' => ['sometimes', 'nullable', 'string', 'in:fixed,starting_at,range,quote,recurring,setup_recurring'],
            'price_min' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'price_max' => ['sometimes', 'nullable', 'numeric', 'min:0', 'gte:price_min'],
            'setup_price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'recurring_price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'billing_interval' => ['sometimes', 'nullable', 'string', 'in:monthly,quarterly,yearly,once'],
            'sort_order' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000'],
            'is_quote_enabled' => ['sometimes', 'boolean'],
            'is_checkout_enabled' => ['sometimes', 'boolean'],
            'catalog_profile' => ['sometimes', 'nullable', 'array'],
            'seo_title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'seo_description' => ['sometimes', 'nullable', 'string', 'max:320'],
            'canonical_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'og_image' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'archived_at' => ['sometimes', 'nullable', 'date'],
            'image' => ['sometimes', 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'remove_image' => ['sometimes', 'boolean'],
        ];
    }
}
