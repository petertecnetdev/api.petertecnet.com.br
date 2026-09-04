<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class UpdateEstablishmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth('api')->check();
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'fantasy' => ['sometimes', 'nullable', 'string', 'max:255'],
            'cnpj' => ['sometimes', 'nullable', 'string', 'max:32'],
            'type' => ['sometimes', 'nullable', 'string', 'max:100'],
            'category' => ['sometimes', 'nullable', 'string', 'max:100'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'additional_info' => ['sometimes', 'nullable', 'string'],
            'city' => ['sometimes', 'nullable', 'string', 'max:120'],
            'uf' => ['sometimes', 'nullable', 'string', 'max:10'],
            'location' => ['sometimes', 'nullable', 'string'],
            'cep' => ['sometimes', 'nullable', 'string', 'max:20'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'website_url' => ['sometimes', 'nullable', 'url', 'max:255'],
            'facebook_url' => ['sometimes', 'nullable', 'url', 'max:255'],
            'instagram_url' => ['sometimes', 'nullable', 'url', 'max:255'],
            'twitter_url' => ['sometimes', 'nullable', 'url', 'max:255'],
            'youtube_url' => ['sometimes', 'nullable', 'url', 'max:255'],
            'segments' => ['sometimes', 'nullable', 'array'],
            'segments.*' => ['string', 'max:100'],
            'logo' => ['sometimes', 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'background' => ['sometimes', 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'is_published' => ['sometimes', 'boolean'],
            'business_profile' => ['sometimes', 'nullable', 'array'],
            'business_profile.opening_hours' => ['nullable', 'array'],
            'business_profile.payment_methods' => ['nullable', 'array', 'max:30'],
            'business_profile.payment_methods.*' => ['string', 'max:80'],
            'business_profile.delivery_available' => ['nullable', 'boolean'],
            'business_profile.pickup_available' => ['nullable', 'boolean'],
            'business_profile.open_24_hours' => ['nullable', 'boolean'],
            'business_profile.service_area' => ['nullable', 'string', 'max:255'],
            'business_profile.accessibility' => ['nullable', 'boolean'],
            'business_profile.parking' => ['nullable', 'boolean'],
        ];
    }
}
