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
    public function establish(Request $request, User $user): array
    {
        $rawSession = (string) $request->cookie($this->sessionCookieName(), '');
        $current = $this->resolve($request);

        if ($current && (int) $current->user_id === (int) $user->id && $this->validForUser($current, $user)) {
            $this->touch($current, $request);
            $rawRefresh = null;

            if (! $this->refreshMatches($request, $current)) {
                $rawRefresh = $this->replaceRefreshSecret($current);
            }

            return [
                'session' => $current->fresh('user'),
                'session_token' => $rawSession,
                'refresh_token' => $rawRefresh,
                'created' => false,
            ];
        }

        if ($current) {
            $this->revoke($current, 'session_replaced');
        }

        return $this->create($request, $user);
    }

    public function create(Request $request, User $user): array
    {
        $rawSession = Str::random(80);
        $rawRefresh = Str::random(96);

        $session = IdentityGlobalSession::query()->create([
            'session_id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'session_token_hash' => $this->hash($rawSession),
            'refresh_token_hash' => $this->hash($rawRefresh),
            'auth_version' => max((int) ($user->auth_version ?? 1), 1),
            'device_label' => $this->deviceLabel($request->userAgent()),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'last_seen_at' => now(),
            'expires_at' => now()->addMinutes($this->sessionTtlMinutes()),
            'refresh_expires_at' => now()->addMinutes($this->refreshTtlMinutes()),
            'metadata' => [
                'created_origin' => $request->header('Origin'),
            ],
        ]);

        $this->cacheSession($session);

        return [
            'session' => $session->load('user'),
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

        $hash = $this->hash($raw);
        $cacheKey = $this->cacheKey($hash);
        $id = $this->cacheGet($cacheKey);

        $session = $id
            ? IdentityGlobalSession::query()->with('user')->find($id)
            : IdentityGlobalSession::query()->with('user')->where('session_token_hash', $hash)->first();

        if (! $session) {
            $this->cacheForget($cacheKey);
            return null;
        }

        if (! $session->user || ! $this->validForUser($session, $session->user)) {
            if (! $session->revoked_at) {
                $session->forceFill([
                    'revoked_at' => now(),
                    'revoke_reason' => 'expired_or_auth_version_changed',
                ])->save();
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

        $hash = $this->hash($raw);
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

        $hash = $this->hash($raw);
        if (hash_equals((string) $session->refresh_token_hash, $hash)) {
            $newRaw = Str::random(96);
            $session->forceFill([
                'previous_refresh_token_hash' => $session->refresh_token_hash,
                'previous_refresh_valid_until' => now()->addSeconds($this->refreshGraceSeconds()),
                'refresh_token_hash' => $this->hash($newRaw),
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
            'refresh_token_hash' => $this->hash($newRaw),
            'refresh_expires_at' => now()->addMinutes($this->refreshTtlMinutes()),
        ])->save();

        return $newRaw;
    }

    public function touch(IdentityGlobalSession $session, Request $request): void
    {
        $session->forceFill([
            'last_seen_at' => now(),
            'expires_at' => now()->addMinutes($this->sessionTtlMinutes()),
            'ip_address' => $request->ip(),
        ])->save();
        $this->cacheSession($session);
    }

    public function hasHighRiskContextChange(IdentityGlobalSession $session, Request $request): bool
    {
        if (! $session->user_agent || ! $request->userAgent()) {
            return false;
        }

        return ! hash_equals(
            $this->deviceLabel($session->user_agent),
            $this->deviceLabel($request->userAgent())
        );
    }

    public function ipChanged(IdentityGlobalSession $session, Request $request): bool
    {
        $current = (string) $request->ip();
        return $current !== '' && $session->ip_address && ! hash_equals((string) $session->ip_address, $current);
    }

    public function revoke(IdentityGlobalSession $session, string $reason): void
    {
        if (! $session->revoked_at) {
            $session->forceFill([
                'revoked_at' => now(),
                'revoke_reason' => $reason,
            ])->save();
        }

        $this->cacheForget($this->cacheKey((string) $session->session_token_hash));
    }

    public function revokeAll(User $user, string $reason): int
    {
        $sessions = IdentityGlobalSession::query()
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->get();

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
        $signature = hash_hmac('sha256', $encoded, $this->csrfKey());

        return $encoded.'.'.$signature;
    }

    public function validateCsrfToken(
        string $token,
        IdentityGlobalSession $session,
        Application $application,
        Request $request
    ): bool {
        [$encoded, $signature] = array_pad(explode('.', $token, 2), 2, '');
        if ($encoded === '' || $signature === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $encoded, $this->csrfKey());
        if (! hash_equals($expected, $signature)) {
            return false;
        }

        $json = $this->base64UrlDecode($encoded);
        $payload = is_string($json) ? json_decode($json, true) : null;
        if (! is_array($payload)) {
            return false;
        }

        return hash_equals((string) $session->session_id, (string) ($payload['sid'] ?? ''))
            && hash_equals((string) $application->slug, (string) ($payload['app'] ?? ''))
            && hash_equals(
                hash('sha256', (string) $request->header('Origin', '')),
                (string) ($payload['origin'] ?? '')
            )
            && (int) ($payload['exp'] ?? 0) >= now()->timestamp;
    }

    public function sessionCookie(string $raw)
    {
        return Cookie::make(
            $this->sessionCookieName(),
            $raw,
            $this->sessionTtlMinutes(),
            '/',
            null,
            true,
            true,
            false,
            'lax'
        );
    }

    public function refreshCookie(string $raw)
    {
        return Cookie::make(
            $this->refreshCookieName(),
            $raw,
            $this->refreshTtlMinutes(),
            '/',
            null,
            true,
            true,
            false,
            'lax'
        );
    }

    public function forgetCookies(): array
    {
        return [
            Cookie::forget($this->sessionCookieName(), '/', null),
            Cookie::forget($this->refreshCookieName(), '/', null),
        ];
    }

    public function present(IdentityGlobalSession $session): array
    {
        return [
            'id' => $session->session_id,
            'device' => $session->device_label,
            'ip' => $session->ip_address,
            'last_seen_at' => $session->last_seen_at?->toIso8601String(),
            'created_at' => $session->created_at?->toIso8601String(),
            'expires_at' => $session->expires_at?->toIso8601String(),
            'refresh_expires_at' => $session->refresh_expires_at?->toIso8601String(),
        ];
    }

    private function cacheSession(IdentityGlobalSession $session): void
    {
        $ttl = max(60, now()->diffInSeconds($session->expires_at, false));
        $this->cachePut($this->cacheKey((string) $session->session_token_hash), $session->id, $ttl);
    }

    private function cacheKey(string $hash): string
    {
        return 'identity:global-session:'.$hash;
    }

    private function cacheGet(string $key): mixed
    {
        try {
            return Cache::store($this->cacheStore())->get($key);
        } catch (Throwable $e) {
            Log::notice('Identity global-session cache unavailable; database fallback enabled.', [
                'message' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function cachePut(string $key, mixed $value, int $ttlSeconds): void
    {
        try {
            Cache::store($this->cacheStore())->put($key, $value, now()->addSeconds($ttlSeconds));
        } catch (Throwable $e) {
            Log::notice('Identity global-session cache unavailable; continuing with database persistence.', [
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function cacheForget(string $key): void
    {
        try {
            Cache::store($this->cacheStore())->forget($key);
        } catch (Throwable) {
        }
    }

    private function deviceLabel(?string $userAgent): string
    {
        $ua = strtolower((string) $userAgent);
        $platform = str_contains($ua, 'iphone') || str_contains($ua, 'ipad') ? 'iOS'
            : (str_contains($ua, 'android') ? 'Android'
                : (str_contains($ua, 'windows') ? 'Windows'
                    : (str_contains($ua, 'mac os') || str_contains($ua, 'macintosh') ? 'macOS'
                        : (str_contains($ua, 'linux') ? 'Linux' : 'Device'))));

        $browser = str_contains($ua, 'edg/') ? 'Edge'
            : (str_contains($ua, 'opr/') ? 'Opera'
                : (str_contains($ua, 'firefox/') ? 'Firefox'
                    : (str_contains($ua, 'chrome/') ? 'Chrome'
                        : (str_contains($ua, 'safari/') ? 'Safari' : 'Browser'))));

        return $platform.' · '.$browser;
    }

    private function hash(string $value): string
    {
        return hash('sha256', $value);
    }

    private function base64UrlDecode(string $value): string|false
    {
        $padding = strlen($value) % 4;
        if ($padding) {
            $value .= str_repeat('=', 4 - $padding);
        }

        return base64_decode(strtr($value, '-_', '+/'), true);
    }

    private function csrfKey(): string
    {
        return hash('sha256', (string) config('app.key'));
    }

    private function cacheStore(): string
    {
        return (string) config('identity.global_sso.cache_store', app()->environment('testing') ? 'array' : 'redis');
    }

    private function sessionCookieName(): string
    {
        return (string) config('identity.global_sso.session_cookie', 'peter_ecosystem_session');
    }

    private function refreshCookieName(): string
    {
        return (string) config('identity.global_sso.refresh_cookie', 'peter_ecosystem_refresh');
    }

    private function sessionTtlMinutes(): int
    {
        return max((int) config('identity.global_sso.session_ttl_minutes', 10080), 5);
    }

    private function refreshTtlMinutes(): int
    {
        return max((int) config('identity.global_sso.refresh_ttl_minutes', 43200), $this->sessionTtlMinutes());
    }

    private function refreshGraceSeconds(): int
    {
        return max((int) config('identity.global_sso.refresh_grace_seconds', 30), 5);
    }

    private function csrfTtlSeconds(): int
    {
        return max((int) config('identity.global_sso.csrf_ttl_seconds', 300), 30);
    }
}
