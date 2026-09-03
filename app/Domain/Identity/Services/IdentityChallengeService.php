<?php

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Models\IdentityChallenge;
use App\Models\Application;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class IdentityChallengeService
{
    public function issue(
        string $purpose,
        ?User $user,
        ?Application $application,
        array $payload,
        int $ttlMinutes,
        Request $request,
        int $bytes = 32,
    ): array {
        $token = $this->base64Url(random_bytes($bytes));

        $challenge = IdentityChallenge::query()->create([
            'token_hash' => hash('sha256', $token),
            'purpose' => $purpose,
            'user_id' => $user?->id,
            'app_id' => $application?->id,
            'payload' => $payload,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'expires_at' => now()->addMinutes(max($ttlMinutes, 1)),
        ]);

        return ['token' => $token, 'challenge' => $challenge];
    }

    public function consume(string $purpose, string $token, bool $markConsumed = true): ?IdentityChallenge
    {
        return DB::transaction(function () use ($purpose, $token, $markConsumed) {
            $challenge = IdentityChallenge::query()
                ->where('token_hash', hash('sha256', $token))
                ->where('purpose', $purpose)
                ->lockForUpdate()
                ->first();

            if (! $challenge || ! $challenge->canBeConsumed()) {
                return null;
            }

            $challenge->increment('attempts');

            if ($markConsumed) {
                $challenge->forceFill(['consumed_at' => now()])->save();
            }

            return $challenge->fresh();
        });
    }

    public function consumeModel(IdentityChallenge $challenge): bool
    {
        return DB::transaction(function () use ($challenge) {
            $locked = IdentityChallenge::query()->lockForUpdate()->find($challenge->id);
            if (! $locked || ! $locked->canBeConsumed()) {
                return false;
            }

            $locked->forceFill(['consumed_at' => now()])->save();
            return true;
        });
    }

    public function purgeExpired(): int
    {
        return IdentityChallenge::query()
            ->where('expires_at', '<', now()->subDay())
            ->delete();
    }

    public function base64Url(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }

    public function decodeBase64Url(string $value): string|false
    {
        $padding = strlen($value) % 4;
        if ($padding > 0) {
            $value .= str_repeat('=', 4 - $padding);
        }

        return base64_decode(strtr($value, '-_', '+/'), true);
    }
}
