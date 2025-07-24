<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GoogleAuthRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'token_id' => 'required|string',
        ];
    }

    public function messages()
    {
        return [
            'token_id.required' => 'O token do Google é obrigatório.',
        ];
    }
}
