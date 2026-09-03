<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class StoreEstablishmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth('api')->check();
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'fantasy' => ['nullable', 'string', 'max:255'],
            'cnpj' => ['nullable', 'string', 'max:32'],
            'type' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255'],
            'description' => ['nullable', 'string'],
            'additional_info' => ['nullable', 'string'],
            'city' => ['nullable', 'string', 'max:120'],
            'uf' => ['nullable', 'string', 'max:10'],
            'location' => ['nullable', 'string'],
            'cep' => ['nullable', 'string', 'max:20'],
            'address' => ['nullable', 'string', 'max:255'],
            'website_url' => ['nullable', 'url', 'max:255'],
            'facebook_url' => ['nullable', 'url', 'max:255'],
            'instagram_url' => ['nullable', 'url', 'max:255'],
            'twitter_url' => ['nullable', 'url', 'max:255'],
            'youtube_url' => ['nullable', 'url', 'max:255'],
            'segments' => ['nullable', 'array'],
            'business_profile' => ['nullable', 'array'],
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
