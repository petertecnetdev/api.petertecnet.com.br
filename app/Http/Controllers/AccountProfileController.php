<?php

namespace App\Http\Controllers;

use App\Models\AccountDocument;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AccountProfileController extends Controller
{
    public function show()
    {
        /** @var User $user */
        $user = User::query()->findOrFail(Auth::id());

        return response()->json($this->payload($user));
    }

    public function update(Request $request)
    {
        /** @var User $user */
        $user = User::query()->findOrFail(Auth::id());

        $request->merge([
            'cpf' => $request->has('cpf') ? preg_replace('/\D/', '', (string) $request->input('cpf')) : $request->input('cpf'),
            'postal_code' => $request->has('postal_code') ? preg_replace('/\D/', '', (string) $request->input('postal_code')) : $request->input('postal_code'),
            'phone' => $request->has('phone') ? preg_replace('/[^0-9+]/', '', (string) $request->input('phone')) : $request->input('phone'),
            'uf' => $request->has('uf') ? strtoupper(trim((string) $request->input('uf'))) : $request->input('uf'),
        ]);

        $data = $request->validate([
            'first_name' => 'sometimes|required|string|min:2|max:100',
            'last_name' => 'sometimes|nullable|string|max:100',
            'user_name' => ['sometimes', 'nullable', 'string', 'min:3', 'max:100', Rule::unique('users', 'user_name')->ignore($user->id)],
            'cpf' => ['sometimes', 'nullable', 'digits:11', Rule::unique('users', 'cpf')->ignore($user->id)],
            'phone' => 'sometimes|nullable|string|max:30',
            'birthdate' => 'sometimes|nullable|date|before_or_equal:today',
            'gender' => 'sometimes|nullable|in:male,female,other',
            'marital_status' => 'sometimes|nullable|string|max:60',
            'occupation' => 'sometimes|nullable|string|max:180',
            'address' => 'sometimes|nullable|string|max:500',
            'city' => 'sometimes|nullable|string|max:120',
            'uf' => 'sometimes|nullable|string|size:2',
            'postal_code' => 'sometimes|nullable|string|max:8',
            'about' => 'sometimes|nullable|string|max:3000',
            'nationality' => 'sometimes|nullable|string|max:100',
            'birthplace' => 'sometimes|nullable|string|max:160',
            'identity_document_type' => 'sometimes|nullable|string|max:40',
            'identity_document_number' => 'sometimes|nullable|string|max:80',
            'identity_document_issuer' => 'sometimes|nullable|string|max:80',
            'parent_1' => 'sometimes|nullable|string|max:180',
            'parent_2' => 'sometimes|nullable|string|max:180',
        ]);

        $extendedKeys = [
            'nationality', 'birthplace', 'identity_document_type', 'identity_document_number',
            'identity_document_issuer', 'parent_1', 'parent_2',
        ];
        $userKeys = [
            'first_name', 'last_name', 'user_name', 'cpf', 'phone', 'birthdate', 'gender',
            'marital_status', 'occupation', 'address', 'city', 'uf', 'postal_code', 'about',
        ];

        DB::transaction(function () use ($user, $data, $extendedKeys, $userKeys) {
            foreach (Arr::only($data, $userKeys) as $field => $value) {
                $user->{$field} = is_string($value) ? trim($value) : $value;
            }

            $extra = is_array($user->extra_info) ? $user->extra_info : [];
            $profile = is_array($extra['account_profile'] ?? null) ? $extra['account_profile'] : [];
            foreach (Arr::only($data, $extendedKeys) as $field => $value) {
                $profile[$field] = is_string($value) ? trim($value) : $value;
            }
            $extra['account_profile'] = $profile;
            $user->extra_info = $extra;
            $user->save();
        });

        return response()->json([
            'message' => 'Dados pessoais atualizados com sucesso.',
            ...$this->payload($user->fresh()),
        ]);
    }

    private function payload(User $user): array
    {
        $extra = is_array($user->extra_info) ? $user->extra_info : [];
        $extended = is_array($extra['account_profile'] ?? null) ? $extra['account_profile'] : [];
        $documents = AccountDocument::query()
            ->where('user_id', $user->id)
            ->latest()
            ->get();

        $fields = [
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'cpf' => $user->cpf,
            'phone' => $user->phone,
            'birthdate' => $user->birthdate,
            'occupation' => $user->occupation,
            'address' => $user->address,
            'city' => $user->city,
            'uf' => $user->uf,
            'postal_code' => $user->postal_code,
        ];
        $filled = collect($fields)->filter(fn ($value) => $value !== null && trim((string) $value) !== '')->count();
        $completion = (int) round(($filled / count($fields)) * 100);

        return [
            'user' => [
                'id' => $user->id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'user_name' => $user->user_name,
                'email' => $user->email,
                'email_verified_at' => $user->email_verified_at?->toIso8601String(),
                'cpf' => $user->cpf,
                'phone' => $user->phone,
                'birthdate' => $user->birthdate?->toDateString(),
                'gender' => $user->gender,
                'marital_status' => $user->marital_status,
                'occupation' => $user->occupation,
                'address' => $user->address,
                'city' => $user->city,
                'uf' => $user->uf,
                'postal_code' => $user->postal_code,
                'about' => $user->about,
                'avatar' => $user->avatar,
                ...Arr::only($extended, [
                    'nationality', 'birthplace', 'identity_document_type', 'identity_document_number',
                    'identity_document_issuer', 'parent_1', 'parent_2',
                ]),
            ],
            'completion' => [
                'percentage' => $completion,
                'completed_fields' => $filled,
                'total_fields' => count($fields),
                'missing_fields' => collect($fields)->filter(fn ($value) => $value === null || trim((string) $value) === '')->keys()->values(),
                'email_verified' => (bool) $user->email_verified_at,
                'documents_count' => $documents->count(),
                'verified_documents_count' => $documents->filter(fn (AccountDocument $document) => $document->effectiveStatus() === 'verified')->count(),
            ],
        ];
    }
}
