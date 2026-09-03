<?php

namespace App\Services;

use App\Models\Application;
use App\Models\User;
use App\Models\UserInvitation;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class InvitationService
{
    public function issue(User $user, Application $application, ?User $invitedBy = null, array $metadata = []): array
    {
        UserInvitation::query()
            ->where('user_id', $user->id)
            ->where('application_id', $application->id)
            ->where('status', 'pending')
            ->whereNull('consumed_at')
            ->whereNull('revoked_at')
            ->update([
                'status' => 'revoked',
                'revoked_at' => now(),
                'updated_at' => now(),
            ]);

        $rawToken = Str::random(64);
        $rawCode = $this->newCode(8);

        $invitation = UserInvitation::create([
            'user_id' => $user->id,
            'application_id' => $application->id,
            'invited_by' => $invitedBy?->id,
            'token_hash' => hash('sha256', $rawToken),
            'verification_code_hash' => Hash::make($rawCode),
            'status' => 'pending',
            'expires_at' => now()->addDay(),
            'metadata' => array_merge([
                'source' => 'platform_invitation',
                'issued_at' => now()->toIso8601String(),
            ], $metadata),
        ]);

        return [
            'invitation' => $invitation,
            'token' => $rawToken,
            'code' => $rawCode,
        ];
    }

    public function findUsableByToken(string $rawToken): ?UserInvitation
    {
        $token = trim($rawToken);
        if ($token === '' || strlen($token) < 40 || strlen($token) > 128) {
            return null;
        }

        $invitation = UserInvitation::query()
            ->with(['user', 'application'])
            ->where('token_hash', hash('sha256', $token))
            ->first();

        return $invitation?->isUsable() ? $invitation : null;
    }

    public function codeMatches(UserInvitation $invitation, string $code): bool
    {
        return Hash::check(strtoupper(trim($code)), $invitation->verification_code_hash);
    }

    private function newCode(int $length): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = '';

        for ($i = 0; $i < $length; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $code;
    }
}
