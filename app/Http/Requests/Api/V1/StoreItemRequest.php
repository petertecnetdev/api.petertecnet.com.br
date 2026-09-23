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
            'short_description' => ['nullable', 'string', 'max:1000'],
            'pricing_model' => ['nullable', 'string', 'in:fixed,starting_at,range,quote,recurring,setup_recurring'],
            'price_min' => ['nullable', 'numeric', 'min:0'],
            'price_max' => ['nullable', 'numeric', 'min:0', 'gte:price_min'],
            'setup_price' => ['nullable', 'numeric', 'min:0'],
            'recurring_price' => ['nullable', 'numeric', 'min:0'],
            'billing_interval' => ['nullable', 'string', 'in:monthly,quarterly,yearly,once'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'is_quote_enabled' => ['boolean'],
            'is_checkout_enabled' => ['boolean'],
            'catalog_profile' => ['nullable', 'array'],
            'seo_title' => ['nullable', 'string', 'max:255'],
            'seo_description' => ['nullable', 'string', 'max:320'],
            'canonical_url' => ['nullable', 'url', 'max:2048'],
            'og_image' => ['nullable', 'url', 'max:2048'],
            'archived_at' => ['nullable', 'date'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ];
    }
}
