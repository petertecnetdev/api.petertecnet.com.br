<?php

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Models\IdentitySession;
use App\Models\Application;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class IdentitySessionService
{
    public function issue(User $user, Request $request, string $authMethod, ?Application $application = null): array
    {
        $session = IdentitySession::query()->create([
            'session_id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'app_id' => $application?->id,
            'auth_method' => $authMethod,
            'device_label' => $this->deviceLabel($request->userAgent()),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'last_seen_at' => now(),
            'expires_at' => now()->addMinutes(max((int) config('identity.session.ttl_minutes', 43200), 1)),
        ]);

        $token = auth('api')->claims([
            'sid' => $session->session_id,
            'amr' => [$authMethod],
            'ver' => max((int) ($user->auth_version ?? 1), 1),
        ])->login($user);

        return [
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => auth('api')->factory()->getTTL() * 60,
            'session' => $this->present($session),
        ];
    }

    public function current(): ?IdentitySession
    {
        try {
            $sid = auth('api')->payload()->get('sid');
        } catch (\Throwable) {
            return null;
        }

        if (! is_string($sid) || $sid === '') {
            return null;
        }

        return IdentitySession::query()->where('session_id', $sid)->first();
    }

    public function touch(?IdentitySession $session, Request $request): void
    {
        if (! $session || ! $session->isActive()) {
            return;
        }

        $interval = max((int) config('identity.session.touch_interval_minutes', 5), 1);
        if ($session->last_seen_at && $session->last_seen_at->gt(now()->subMinutes($interval))) {
            return;
        }

        $session->forceFill([
            'last_seen_at' => now(),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ])->save();
    }

    public function revoke(IdentitySession $session, string $reason = 'user_revoked'): void
    {
        if ($session->revoked_at) {
            return;
        }

        $session->forceFill([
            'revoked_at' => now(),
            'revoke_reason' => $reason,
        ])->save();
    }

    public function revokeAll(User $user, string $reason = 'user_revoked_all', ?string $exceptSessionId = null): int
    {
        return IdentitySession::query()
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->when($exceptSessionId, fn ($query) => $query->where('session_id', '!=', $exceptSessionId))
            ->update([
                'revoked_at' => now(),
                'revoke_reason' => $reason,
                'updated_at' => now(),
            ]);
    }

    public function activeFor(User $user)
    {
        return IdentitySession::query()
            ->with('application:id,name,slug,url')
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->orderByDesc('last_seen_at')
            ->get();
    }

    public function present(IdentitySession $session): array
    {
        return [
            'id' => $session->session_id,
            'application' => $session->relationLoaded('application') && $session->application
                ? $session->application->only(['id', 'name', 'slug', 'url'])
                : null,
            'auth_method' => $session->auth_method,
            'device' => $session->device_label,
            'ip' => $session->ip_address,
            'last_seen_at' => $session->last_seen_at?->toIso8601String(),
            'created_at' => $session->created_at?->toIso8601String(),
            'expires_at' => $session->expires_at?->toIso8601String(),
            'revoked_at' => $session->revoked_at?->toIso8601String(),
        ];
    }

    private function deviceLabel(?string $userAgent): string
    {
        $ua = strtolower((string) $userAgent);
        $device = str_contains($ua, 'iphone') ? 'iPhone'
            : (str_contains($ua, 'ipad') ? 'iPad'
                : (str_contains($ua, 'android') ? 'Android'
                    : (str_contains($ua, 'windows') ? 'Windows'
                        : (str_contains($ua, 'mac os') || str_contains($ua, 'macintosh') ? 'Mac'
                            : (str_contains($ua, 'linux') ? 'Linux' : 'Dispositivo')))));

        $browser = str_contains($ua, 'edg/') ? 'Edge'
            : (str_contains($ua, 'firefox/') ? 'Firefox'
                : (str_contains($ua, 'chrome/') ? 'Chrome'
                    : (str_contains($ua, 'safari/') ? 'Safari' : null)));

        return trim($device . ($browser ? ' · ' . $browser : ''));
    }
}
