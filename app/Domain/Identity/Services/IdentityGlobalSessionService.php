<?php

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Models\IdentityGlobalSession;
use App\Models\Application;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class IdentityGlobalSessionService
{
    public function __construct(private readonly IdentityDeviceService $devices)
    {
    }

    public function establish(Request $request, User $user, ?Application $application = null): array
    {
        $rawSession = (string) $request->cookie($this->sessionCookieName(), '');
        $current = $this->resolve($request);

        if ($current && (int) $current->user_id === (int) $user->id && $this->validForUser($current, $user)) {
            $this->touch($current, $request, $application);
            $rawRefresh = null;
            if (! $this->refreshMatches($request, $current)) {
                $rawRefresh = $this->replaceRefreshSecret($current);
            }

            return [
                'session' => $current->fresh(['user', 'device.lastApplication']),
                'session_token' => $rawSession,
                'refresh_token' => $rawRefresh,
                'created' => false,
            ];
        }

        if ($current) {
            $this->revoke($current, 'session_replaced');
        }

        return $this->create($request, $user, $application);
    }

    public function create(Request $request, User $user, ?Application $application = null): array
    {
        $rawSession = Str::random(80);
        $rawRefresh = Str::random(96);
        $device = $this->devices->resolve($user, $request, $application);

        $session = IdentityGlobalSession::query()->create([
            'session_id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'device_id' => $device->id,
            'session_token_hash' => hash('sha256', $rawSession),
            'refresh_token_hash' => hash('sha256', $rawRefresh),
            'auth_version' => max((int) ($user->auth_version ?? 1), 1),
            'device_label' => $device->name,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'last_seen_at' => now(),
            'expires_at' => now()->addMinutes($this->sessionTtlMinutes()),
            'refresh_expires_at' => now()->addMinutes($this->refreshTtlMinutes()),
            'metadata' => ['created_origin' => $request->header('Origin')],
        ]);

        $this->cacheSession($session);

        return [
            'session' => $session->load(['user', 'device.lastApplication']),
            'session_token' => $rawSession,
            'refresh_token' => $rawRefresh,
            'created' => true,
        ];
    }

    public function resolve(Request $request): ?IdentityGlobalSession
    {
        $raw = (string) $request->cookie($this->sessionCookieName(), '');
        if ($raw === '') {
            return null;
        }

        $hash = hash('sha256', $raw);
        $cacheKey = $this->cacheKey($hash);
        $id = $this->cacheGet($cacheKey);
        $session = $id
            ? IdentityGlobalSession::query()->with(['user', 'device.lastApplication'])->find($id)
            : IdentityGlobalSession::query()->with(['user', 'device.lastApplication'])->where('session_token_hash', $hash)->first();

        if (! $session) {
            $this->cacheForget($cacheKey);
            return null;
        }

        if (! $session->user || ! $this->validForUser($session, $session->user)) {
            if (! $session->revoked_at) {
                $session->forceFill(['revoked_at' => now(), 'revoke_reason' => 'expired_or_auth_version_changed'])->save();
            }
            $this->cacheForget($cacheKey);
            return null;
        }

        $this->cacheSession($session);
        return $session;
    }

    public function validForUser(IdentityGlobalSession $session, User $user): bool
    {
        return $session->isActive()
            && (int) $session->user_id === (int) $user->id
            && (int) $session->auth_version === max((int) ($user->auth_version ?? 1), 1);
    }

    public function refreshMatches(Request $request, IdentityGlobalSession $session): bool
    {
        $raw = (string) $request->cookie($this->refreshCookieName(), '');
        if ($raw === '') {
            return false;
        }

        $hash = hash('sha256', $raw);
        if (hash_equals((string) $session->refresh_token_hash, $hash)) {
            return true;
        }

        return $session->previous_refresh_token_hash
            && $session->previous_refresh_valid_until?->isFuture()
            && hash_equals((string) $session->previous_refresh_token_hash, $hash);
    }

    public function rotateRefresh(Request $request, IdentityGlobalSession $session): array
    {
        $raw = (string) $request->cookie($this->refreshCookieName(), '');
        if ($raw === '') {
            return ['valid' => false, 'refresh_token' => null, 'rotated' => false];
        }

        $hash = hash('sha256', $raw);
        if (hash_equals((string) $session->refresh_token_hash, $hash)) {
            $newRaw = Str::random(96);
            $session->forceFill([
                'previous_refresh_token_hash' => $session->refresh_token_hash,
                'previous_refresh_valid_until' => now()->addSeconds($this->refreshGraceSeconds()),
                'refresh_token_hash' => hash('sha256', $newRaw),
                'refresh_expires_at' => now()->addMinutes($this->refreshTtlMinutes()),
            ])->save();

            return ['valid' => true, 'refresh_token' => $newRaw, 'rotated' => true];
        }

        if ($session->previous_refresh_token_hash
            && $session->previous_refresh_valid_until?->isFuture()
            && hash_equals((string) $session->previous_refresh_token_hash, $hash)) {
            return ['valid' => true, 'refresh_token' => null, 'rotated' => false];
        }

        return ['valid' => false, 'refresh_token' => null, 'rotated' => false];
    }

    public function replaceRefreshSecret(IdentityGlobalSession $session): string
    {
        $newRaw = Str::random(96);
        $session->forceFill([
            'previous_refresh_token_hash' => $session->refresh_token_hash,
            'previous_refresh_valid_until' => now()->addSeconds($this->refreshGraceSeconds()),
            'refresh_token_hash' => hash('sha256', $newRaw),
            'refresh_expires_at' => now()->addMinutes($this->refreshTtlMinutes()),
        ])->save();
        return $newRaw;
    }

    public function touch(IdentityGlobalSession $session, Request $request, ?Application $application = null): void
    {
        $device = $session->user ? $this->devices->resolve($session->user, $request, $application) : null;
        $session->forceFill([
            'device_id' => $device?->id ?: $session->device_id,
            'device_label' => $device?->name ?: $session->device_label,
            'last_seen_at' => now(),
            'expires_at' => now()->addMinutes($this->sessionTtlMinutes()),
            'ip_address' => $request->ip(),
        ])->save();
        $this->cacheSession($session);
    }

    public function hasHighRiskContextChange(IdentityGlobalSession $session, Request $request): bool
    {
        return ! $this->devices->sameContext($session->user_agent, $request->userAgent());
    }

    public function ipChanged(IdentityGlobalSession $session, Request $request): bool
    {
        $current = (string) $request->ip();
        return $current !== '' && $session->ip_address && ! hash_equals((string) $session->ip_address, $current);
    }

    public function revoke(IdentityGlobalSession $session, string $reason): void
    {
        if (! $session->revoked_at) {
            $session->forceFill(['revoked_at' => now(), 'revoke_reason' => $reason])->save();
        }
        $this->cacheForget($this->cacheKey((string) $session->session_token_hash));
    }

    public function revokeAll(User $user, string $reason): int
    {
        $sessions = IdentityGlobalSession::query()->where('user_id', $user->id)->whereNull('revoked_at')->get();
        foreach ($sessions as $session) {
            $this->revoke($session, $reason);
        }
        return $sessions->count();
    }

    public function issueCsrfToken(IdentityGlobalSession $session, Application $application, Request $request): string
    {
        $payload = [
            'sid' => $session->session_id,
            'app' => (string) $application->slug,
            'origin' => hash('sha256', (string) $request->header('Origin', '')),
            'exp' => now()->addSeconds($this->csrfTtlSeconds())->timestamp,
            'nonce' => Str::random(24),
        ];
        $encoded = rtrim(strtr(base64_encode((string) json_encode($payload, JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');
        return $encoded.'.'.hash_hmac('sha256', $encoded, $this->csrfKey());
    }

    public function validateCsrfToken(string $token, IdentityGlobalSession $session, Application $application, Request $request): bool
    {
        [$encoded, $signature] = array_pad(explode('.', $token, 2), 2, '');
        if ($encoded === '' || $signature === '' || ! hash_equals(hash_hmac('sha256', $encoded, $this->csrfKey()), $signature)) {
            return false;
        }
        $json = $this->base64UrlDecode($encoded);
        $payload = is_string($json) ? json_decode($json, true) : null;
        return is_array($payload)
            && hash_equals((string) $session->session_id, (string) ($payload['sid'] ?? ''))
            && hash_equals((string) $application->slug, (string) ($payload['app'] ?? ''))
            && hash_equals(hash('sha256', (string) $request->header('Origin', '')), (string) ($payload['origin'] ?? ''))
            && (int) ($payload['exp'] ?? 0) >= now()->timestamp;
    }

    public function sessionCookie(string $raw)
    {
        return Cookie::make($this->sessionCookieName(), $raw, $this->sessionTtlMinutes(), '/', null, true, true, false, 'lax');
    }

    public function refreshCookie(string $raw)
    {
        return Cookie::make($this->refreshCookieName(), $raw, $this->refreshTtlMinutes(), '/', null, true, true, false, 'lax');
    }

    public function forgetCookies(): array
    {
        return [Cookie::forget($this->sessionCookieName(), '/', null), Cookie::forget($this->refreshCookieName(), '/', null)];
    }

    public function present(IdentityGlobalSession $session): array
    {
        return [
            'id' => $session->session_id,
            'device' => $session->relationLoaded('device') && $session->device ? $this->devices->present($session->device) : null,
            'ip' => $session->ip_address,
            'last_seen_at' => $session->last_seen_at?->toIso8601String(),
            'created_at' => $session->created_at?->toIso8601String(),
            'expires_at' => $session->expires_at?->toIso8601String(),
            'refresh_expires_at' => $session->refresh_expires_at?->toIso8601String(),
        ];
    }

    public function cacheLatencyMs(): ?float
    {
        $key = 'identity:probe:'.Str::random(16);
        $start = microtime(true);
        try {
            Cache::store($this->cacheStore())->put($key, 1, 10);
            Cache::store($this->cacheStore())->get($key);
            Cache::store($this->cacheStore())->forget($key);
            return round((microtime(true) - $start) * 1000, 2);
        } catch (Throwable) {
            return null;
        }
    }

    private function cacheSession(IdentityGlobalSession $session): void
    {
        $ttl = max(60, now()->diffInSeconds($session->expires_at, false));
        try {
            Cache::store($this->cacheStore())->put($this->cacheKey((string) $session->session_token_hash), $session->id, now()->addSeconds($ttl));
        } catch (Throwable $e) {
            Log::notice('Identity Redis unavailable; global session continues via database fallback.', ['message' => $e->getMessage()]);
        }
    }

    private function cacheGet(string $key): mixed
    {
        try { return Cache::store($this->cacheStore())->get($key); }
        catch (Throwable $e) {
            Log::notice('Identity Redis unavailable; resolving session from database.', ['message' => $e->getMessage()]);
            return null;
        }
    }

    private function cacheForget(string $key): void
    {
        try { Cache::store($this->cacheStore())->forget($key); } catch (Throwable) {}
    }

    private function cacheKey(string $hash): string { return 'identity:global-session:'.$hash; }
    private function csrfKey(): string { return hash('sha256', (string) config('app.key')); }
    private function cacheStore(): string { return (string) config('identity.global_sso.cache_store', app()->environment('testing') ? 'array' : 'redis'); }
    private function sessionCookieName(): string { return (string) config('identity.global_sso.session_cookie', 'peter_ecosystem_session'); }
    private function refreshCookieName(): string { return (string) config('identity.global_sso.refresh_cookie', 'peter_ecosystem_refresh'); }
    private function sessionTtlMinutes(): int { return max((int) config('identity.global_sso.session_ttl_minutes', 10080), 5); }
    private function refreshTtlMinutes(): int { return max((int) config('identity.global_sso.refresh_ttl_minutes', 43200), $this->sessionTtlMinutes()); }
    private function refreshGraceSeconds(): int { return max((int) config('identity.global_sso.refresh_grace_seconds', 30), 5); }
    private function csrfTtlSeconds(): int { return max((int) config('identity.global_sso.csrf_ttl_seconds', 300), 30); }

    private function base64UrlDecode(string $value): string|false
    {
        $padding = strlen($value) % 4;
        if ($padding) $value .= str_repeat('=', 4 - $padding);
        return base64_decode(strtr($value, '-_', '+/'), true);
    }
}
