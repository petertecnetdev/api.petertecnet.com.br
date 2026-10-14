<?php

declare(strict_types=1);

namespace App\Http\Requests\Independence;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Public intake validates contact intent only. It MUST NOT grant ownership.
 * W1 resolves app/entity from trusted routing, rate-limits, atomically
 * deduplicates, verifies the email and records an auditable human decision.
 */
final class SubmitOwnershipClaimRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach (['name', 'email', 'phone', 'message'] as $field) {
            if (! $this->exists($field) || ! is_string($this->input($field))) {
                continue;
            }

            $value = trim($this->input($field));
            if ($field === 'email') {
                $value = strtolower($value);
            }

            $normalized[$field] = $value === '' && in_array($field, ['phone', 'message'], true)
                ? null
                : $value;
        }

        $this->merge($normalized);
    }

    public function rules(): array
    {
        return [
            'entity_type' => ['required', Rule::in(['production', 'event'])],
            'entity_id' => ['required', 'integer', 'min:1'],
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'email' => ['required', 'email:rfc', 'max:254'],
            'phone' => ['nullable', 'string', 'max:32', 'regex:/^[+()0-9 .-]+$/'],
            'message' => ['nullable', 'string', 'max:2000'],
            'consent' => ['required', 'accepted'],
            'website' => ['prohibited'],
            'app_id' => ['missing'],
            'app_slug' => ['missing'],
            'application_id' => ['missing'],
            'user_id' => ['missing'],
            'owner_user_id' => ['missing'],
            'claimant_user_id' => ['missing'],
            'status' => ['missing'],
            'verification_status' => ['missing'],
            'verified_at' => ['missing'],
            'approved_at' => ['missing'],
            'reviewed_by_user_id' => ['missing'],
            'is_verified' => ['missing'],
            'evidence_verified' => ['missing'],
            'documents' => ['missing'],
        ];
    }
}
