<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class SyncEmployerItemsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth('api')->check();
    }

    public function rules(): array
    {
        return [
            'item_ids' => ['required', 'array'],
            'item_ids.*' => ['integer', 'distinct'],
        ];
    }
}
