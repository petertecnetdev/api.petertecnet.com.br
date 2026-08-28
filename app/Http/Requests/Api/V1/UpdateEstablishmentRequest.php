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
            'is_published' => ['sometimes', 'boolean'],
        ];
    }
}
