<?php

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Models\IdentitySession;
use App\Domain\Identity\Models\IdentityStepUpGrant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class IdentityStepUpService
{
    public function issue(User $user, Request $request, string $action, string $method, ?IdentitySession $session = null): array
    {
        $raw = Str::random(96);
        $ttlSeconds = max((int) config('identity.step_up.ttl_seconds', 600), 60);
        $grant = IdentityStepUpGrant::query()->create([
            'grant_id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'session_id' => $session?->id,
            'token_hash' => hash('sha256', $raw),
            'action' => $this->normalizeAction($action),
            'method' => $method,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'expires_at' => now()->addSeconds($ttlSeconds),
            'metadata' => ['issued_at' => now()->toIso8601String()],
        ]);

        return [
            'token' => $raw,
            'grant_id' => $grant->grant_id,
            'action' => $grant->action,
            'method' => $method,
            'expires_in' => $ttlSeconds,
        ];
    }

    public function validate(User $user, Request $request, string $action, bool $consume = false): ?IdentityStepUpGrant
    {
        $raw = trim((string) $request->header('X-Peter-Step-Up', ''));
        if ($raw === '') {
            return null;
        }

        $grant = IdentityStepUpGrant::query()
            ->where('user_id', $user->id)
            ->where('token_hash', hash('sha256', $raw))
            ->where('action', $this->normalizeAction($action))
            ->first();

        if (! $grant || ! $grant->isActive()) {
            return null;
        }

        if ($grant->ip_address && $request->ip() && ! hash_equals((string) $grant->ip_address, (string) $request->ip())) {
            $grant->forceFill(['revoked_at' => now()])->save();
            return null;
        }

        if ($grant->user_agent && $request->userAgent() && ! hash_equals(hash('sha256', $grant->user_agent), hash('sha256', (string) $request->userAgent()))) {
            $grant->forceFill(['revoked_at' => now()])->save();
            return null;
        }

        if ($consume) {
            $grant->forceFill(['consumed_at' => now()])->save();
        }

        return $grant;
    }

    public function revokeAll(User $user): int
    {
        return IdentityStepUpGrant::query()
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->whereNull('consumed_at')
            ->update(['revoked_at' => now(), 'updated_at' => now()]);
    }

    public function normalizeAction(string $action): string
    {
        return Str::lower(trim(preg_replace('/[^a-zA-Z0-9._:-]+/', '-', $action) ?? ''));
    }
}
