<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GoogleAuthRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'token_id' => 'required|string',
        ];
    }

    public function messages(): array
    {
        return [
            'token_id.required' => 'O token do Google é obrigatório.',
            'token_id.string'   => 'O token do Google deve ser uma string.',
        ];
    }
}
