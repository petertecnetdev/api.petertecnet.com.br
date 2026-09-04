<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class ContextualStepUpService
{
    private const TTL_SECONDS = 300;

    public function issue(User $user, string $password, string $purpose = 'access_admin'): string
    {
        if (! $user->password || ! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages(['password' => ['Credencial de confirmação inválida.']]);
        }

        return Crypt::encryptString(json_encode([
            'user_id' => $user->id,
            'purpose' => $purpose,
            'issued_at' => now()->timestamp,
            'expires_at' => now()->addSeconds(self::TTL_SECONDS)->timestamp,
            'nonce' => bin2hex(random_bytes(12)),
        ], JSON_THROW_ON_ERROR));
    }

    public function valid(?string $token, User $user, string $purpose = 'access_admin'): bool
    {
        if (! $token) return false;

        try {
            $payload = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
            return (int) ($payload['user_id'] ?? 0) === (int) $user->id
                && ($payload['purpose'] ?? null) === $purpose
                && (int) ($payload['expires_at'] ?? 0) >= now()->timestamp;
        } catch (\Throwable) {
            return false;
        }
    }
}
