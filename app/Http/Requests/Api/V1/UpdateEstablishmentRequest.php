<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'is_published' => ['sometimes', 'boolean'],
            'profile_settings' => ['sometimes', 'nullable', 'array'],
            'profile_settings.cover_position_y' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'profile_settings.primary_cta' => ['sometimes', 'nullable', Rule::in(['catalog', 'buy', 'schedule', 'quote', 'contact'])],
            'profile_settings.capabilities' => ['sometimes', 'array'],
            'profile_settings.capabilities.*' => [Rule::in(['catalog', 'commerce', 'scheduling', 'quotes', 'contact'])],
            'profile_settings.payment_methods' => ['sometimes', 'array'],
            'profile_settings.payment_methods.*' => [Rule::in(['pix', 'credit_card', 'debit_card', 'cash', 'bank_transfer', 'payment_link'])],
            'profile_settings.business_hours' => ['sometimes', 'array'],
            'profile_settings.business_hours.*.enabled' => ['sometimes', 'boolean'],
            'profile_settings.business_hours.*.open' => ['sometimes', 'nullable', 'date_format:H:i'],
            'profile_settings.business_hours.*.close' => ['sometimes', 'nullable', 'date_format:H:i'],
        ];
    }
}
