<?php

namespace App\Services\Reporting;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class AdministrativeReportFilterService
{
    public function validate(array $input): array
    {
        return Validator::make($input, [
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'], 'app_id' => ['nullable', 'integer', 'exists:applications,id'],
            'profile_id' => ['nullable', 'integer', 'exists:profiles,id'], 'user_id' => ['nullable', 'integer', 'exists:users,id'], 'establishment_id' => ['nullable', 'integer', 'exists:establishments,id'],
            'search' => ['nullable', 'string', 'max:150'], 'status' => ['nullable', 'string', 'max:80'], 'type' => ['nullable', 'string', 'max:100'], 'outcome' => ['nullable', Rule::in(['success', 'denied', 'error'])],
            'provider' => ['nullable', 'string', 'max:100'], 'method' => ['nullable', 'string', 'max:50'], 'city' => ['nullable', 'string', 'max:120'], 'uf' => ['nullable', 'string', 'size:2'], 'action' => ['nullable', 'string', 'max:150'],
        ])->validate();
    }
}
