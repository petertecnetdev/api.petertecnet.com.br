<?php

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Models\IdentityIdentifier;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

class IdentityIdentifierService
{
    public function resolve(string $input): ?User
    {
        [$type, $normalized] = $this->classify($input);
        $identifier = IdentityIdentifier::query()
            ->with('user')
            ->where('type', $type)
            ->where('fingerprint', $this->fingerprint($type, $normalized))
            ->whereNull('revoked_at')
            ->first();

        if ($identifier?->user) {
            return $identifier->user;
        }

        $user = $this->resolveLegacy($type, $normalized);
        if ($user) {
            $this->syncUser($user);
        }
        return $user;
    }

    public function syncUser(User $user): void
    {
        $values = [
            'email' => $user->email,
            'username' => $user->user_name,
            'cpf' => $user->cpf,
            'phone' => $user->phone,
            'google' => $user->google_id,
        ];

        foreach ($values as $type => $value) {
            if ($value === null || trim((string) $value) === '') {
                continue;
            }
            $normalized = $this->normalize($type, (string) $value);
            if ($normalized === '') {
                continue;
            }

            $fingerprint = $this->fingerprint($type, $normalized);
            $existing = IdentityIdentifier::query()->where('type', $type)->where('fingerprint', $fingerprint)->first();
            if ($existing && (int) $existing->user_id !== (int) $user->id) {
                continue;
            }

            IdentityIdentifier::query()->updateOrCreate(
                ['type' => $type, 'fingerprint' => $fingerprint],
                [
                    'user_id' => $user->id,
                    'value_encrypted' => Crypt::encryptString($normalized),
                    'display_hint' => $this->hint($type, $normalized),
                    'is_primary' => in_array($type, ['email', 'username'], true),
                    'verified_at' => $this->verifiedAt($user, $type),
                    'revoked_at' => null,
                ]
            );
        }
    }

    public function add(User $user, string $type, string $value, bool $verified = false, bool $primary = false): IdentityIdentifier
    {
        $type = Str::lower(trim($type));
        $normalized = $this->normalize($type, $value);
        abort_if($normalized === '', 422, 'Identificador inválido.');
        $fingerprint = $this->fingerprint($type, $normalized);

        $conflict = IdentityIdentifier::query()->where('type', $type)->where('fingerprint', $fingerprint)->first();
        abort_if($conflict && (int) $conflict->user_id !== (int) $user->id, 409, 'Este identificador já pertence a outra Conta Peter Tecnet.');

        if ($primary) {
            IdentityIdentifier::query()->where('user_id', $user->id)->where('type', $type)->update(['is_primary' => false]);
        }

        return IdentityIdentifier::query()->updateOrCreate(
            ['type' => $type, 'fingerprint' => $fingerprint],
            [
                'user_id' => $user->id,
                'value_encrypted' => Crypt::encryptString($normalized),
                'display_hint' => $this->hint($type, $normalized),
                'is_primary' => $primary,
                'verified_at' => $verified ? now() : null,
                'revoked_at' => null,
            ]
        );
    }

    public function verifiedValue(IdentityIdentifier $identifier): ?string
    {
        if (! $identifier->verified_at || $identifier->revoked_at || ! $identifier->value_encrypted) {
            return null;
        }
        try {
            return Crypt::decryptString($identifier->value_encrypted);
        } catch (\Throwable) {
            return null;
        }
    }

    public function duplicateCandidates(User $user): array
    {
        $this->syncUser($user);
        $fingerprints = IdentityIdentifier::query()
            ->where('user_id', $user->id)
            ->whereNotNull('verified_at')
            ->whereNull('revoked_at')
            ->get(['type', 'fingerprint']);

        if ($fingerprints->isEmpty()) {
            return [];
        }

        return IdentityIdentifier::query()
            ->with('user:id,first_name,last_name,email,user_name')
            ->where('user_id', '!=', $user->id)
            ->whereNotNull('verified_at')
            ->whereNull('revoked_at')
            ->where(function ($query) use ($fingerprints) {
                foreach ($fingerprints as $identifier) {
                    $query->orWhere(fn ($q) => $q->where('type', $identifier->type)->where('fingerprint', $identifier->fingerprint));
                }
            })
            ->get()
            ->map(fn ($identifier) => [
                'user_id' => $identifier->user_id,
                'type' => $identifier->type,
                'hint' => $identifier->display_hint,
                'user' => $identifier->user,
            ])
            ->unique('user_id')
            ->values()
            ->all();
    }

    public function classify(string $input): array
    {
        $input = trim($input);
        if (filter_var($input, FILTER_VALIDATE_EMAIL)) {
            return ['email', $this->normalize('email', $input)];
        }
        $digits = preg_replace('/\D/', '', $input) ?? '';
        if (strlen($digits) === 11 && $this->looksLikeCpf($digits)) {
            return ['cpf', $digits];
        }
        if (strlen($digits) >= 10 && strlen($digits) <= 15 && preg_match('/^[+()\s\-.0-9]+$/', $input)) {
            return ['phone', $this->normalize('phone', $input)];
        }
        return ['username', $this->normalize('username', $input)];
    }

    public function normalize(string $type, string $value): string
    {
        $value = trim($value);
        return match (Str::lower($type)) {
            'email', 'username', 'google' => Str::lower($value),
            'cpf' => substr(preg_replace('/\D/', '', $value) ?? '', -11),
            'phone' => $this->normalizePhone($value),
            default => $value,
        };
    }

    public function fingerprint(string $type, string $normalized): string
    {
        return hash_hmac('sha256', Str::lower($type).'|'.$normalized, hash('sha256', (string) config('app.key')));
    }

    private function resolveLegacy(string $type, string $normalized): ?User
    {
        return match ($type) {
            'email' => User::query()->whereRaw('LOWER(email) = ?', [$normalized])->first(),
            'username' => User::query()->whereRaw('LOWER(user_name) = ?', [$normalized])->first(),
            'cpf' => User::query()->where('cpf', $normalized)->first(),
            'phone' => User::query()->whereRaw("REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, '+', ''), '(', ''), ')', ''), '-', ''), ' ', '') = ?", [$normalized])->first(),
            'google' => User::query()->where('google_id', $normalized)->first(),
            default => null,
        };
    }

    private function normalizePhone(string $value): string
    {
        $digits = preg_replace('/\D/', '', $value) ?? '';
        if (strlen($digits) >= 10 && strlen($digits) <= 11) {
            return '55'.$digits;
        }
        return $digits;
    }

    private function looksLikeCpf(string $digits): bool
    {
        return strlen($digits) === 11 && count(array_unique(str_split($digits))) > 1;
    }

    private function verifiedAt(User $user, string $type)
    {
        return match ($type) {
            'email' => $user->email_verified_at,
            'google' => $user->google_id ? now() : null,
            'username', 'cpf' => now(),
            default => null,
        };
    }

    private function hint(string $type, string $value): string
    {
        if ($type === 'email') {
            [$local, $domain] = array_pad(explode('@', $value, 2), 2, '');
            return mb_substr($local, 0, min(2, mb_strlen($local))).'***@'.$domain;
        }
        if ($type === 'phone') {
            return '•••• '.substr($value, -4);
        }
        if ($type === 'cpf') {
            return '•••.•••.•••-'.substr($value, -2);
        }
        return $value;
    }
}
