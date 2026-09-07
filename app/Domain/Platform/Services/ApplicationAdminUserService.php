<?php

namespace App\Domain\Platform\Services;

use App\Mail\WelcomeMail;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class ApplicationAdminUserService
{
    public function paginate(int $applicationId, ?string $search = null, int $perPage = 25): LengthAwarePaginator
    {
        $needle = trim((string) $search);

        $query = User::query()
            ->select(['id', 'first_name', 'last_name', 'user_name', 'email', 'phone', 'city', 'uf', 'avatar', 'created_at'])
            ->whereHas('applications', fn ($applicationQuery) => $applicationQuery->whereKey($applicationId))
            ->with(['applications' => fn ($applicationQuery) => $applicationQuery
                ->whereKey($applicationId)
                ->select(['applications.id', 'applications.slug', 'applications.name'])]);

        if ($needle !== '') {
            $query->where(function ($userQuery) use ($needle) {
                $userQuery->where('first_name', 'like', "%{$needle}%")
                    ->orWhere('last_name', 'like', "%{$needle}%")
                    ->orWhere('email', 'like', "%{$needle}%")
                    ->orWhere('user_name', 'like', "%{$needle}%");
            });
        }

        return $query->latest('id')->paginate(max(1, min($perPage, 100)));
    }

    public function createOrAttach(int $applicationId, array $data): array
    {
        $email = strtolower(trim((string) $data['email']));
        $role = (string) ($data['role'] ?? 'participant');
        $temporaryPassword = Str::random(14).'Aa1!';
        $verificationCode = strtoupper(Str::random(6));
        $created = false;

        $user = DB::transaction(function () use (
            $applicationId,
            $data,
            $email,
            $role,
            $temporaryPassword,
            $verificationCode,
            &$created,
        ) {
            $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();

            if (! $user) {
                $created = true;
                $user = User::query()->create([
                    'first_name' => trim((string) $data['first_name']),
                    'last_name' => trim((string) ($data['last_name'] ?? '')) ?: null,
                    'email' => $email,
                    'user_name' => $this->uniqueUsername((string) $data['first_name']),
                    'password' => Hash::make($temporaryPassword),
                    'verification_code' => Hash::make($verificationCode),
                    'verification_code_expires_at' => now()->addDay(),
                    'is_participant' => true,
                    'is_producer' => in_array($role, ['producer', 'production_manager', 'ticket_manager'], true),
                    'is_promoter' => $role === 'promoter',
                ]);
            }

            $user->applications()->syncWithoutDetaching([
                $applicationId => [
                    'role' => $role,
                    'status' => 'active',
                    'metadata' => json_encode(['roles' => [$role]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'joined_at' => now(),
                ],
            ]);

            return $user->fresh(['applications']);
        });

        if ($created) {
            Mail::to($user->email)->send(new WelcomeMail($verificationCode, $user, $temporaryPassword));
        }

        return [
            'user' => $user,
            'created' => $created,
        ];
    }

    private function uniqueUsername(string $name): string
    {
        $base = Str::slug($name) ?: 'user';

        do {
            $username = $base.'-'.strtolower(Str::random(6));
        } while (User::query()->where('user_name', $username)->exists());

        return $username;
    }
}
