<?php

namespace App\Services;

use App\Models\Application;
use App\Models\IdentityAuthEvent;
use App\Models\IdentityDevice;
use App\Models\IdentitySession;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class IdentitySessionService
{
    public function establish(Request $request, User $user, ?Application $application = null): array
    {
        $rawSessionToken = (string) $request->cookie($this->sessionCookieName(), '');
        $session = $this->resolve($request);

        if ($session && (int) $session->user_id === (int) $user->id && $this->validForUser($session, $user)) {
            $this->touch($session, $request, $application);
            $rawRefresh = null;
            if (! $this->refreshMatches($request, $session)) {
                $rawRefresh = $this->replaceRefreshSecret($session);
            }

            return [
                'session' => $session->fresh(['device', 'lastApplication']),
                'session_token' => $rawSessionToken,
                'refresh_token' => $rawRefresh,
                'created' => false,
            ];
        }

        if ($session) {
            $this->revoke($session, 'session_replaced', $request);
        }

        return $this->create($request, $user, $application);
    }

    public function create(Request $request, User $user, ?Application $application = null): array
    {
        $device = $this->resolveDevice($request, $user);
        $rawSessionToken = Str::random(80);
        $rawRefreshToken = Str::random(96);

        $session = IdentitySession::query()->create([
            'user_id' => $user->id,
            'device_id' => $device?->id,
            'last_application_id' => $application?->id,
            'auth_version' => max((int) ($user->auth_version ?? 1), 1),
            'session_token_hash' => $this->hash($rawSessionToken),
            'refresh_token_hash' => $this->hash($rawRefreshToken),
            'last_seen_at' => now(),
            'expires_at' => now()->addMinutes($this->sessionTtlMinutes()),
            'refresh_expires_at' => now()->addMinutes($this->refreshTtlMinutes()),
            'metadata' => [
                'created_from_origin' => $request->header('Origin'),
            ],
        ]);

        $this->cacheSession($session, $rawSessionToken);
        $this->audit($request, 'session_created', 'success', $user, $session, $application, [
            'device_id' => $device?->uuid,
        ]);

        return [
            'session' => $session->loadMissing(['device', 'lastApplication']),
            'session_token' => $rawSessionToken,
            'refresh_token' => $rawRefreshToken,
            'created' => true,
        ];
    }

    public function resolve(Request $request): ?IdentitySession
    {
        $rawSessionToken = (string) $request->cookie($this->sessionCookieName(), '');
        if ($rawSessionToken === '') {
            return null;
        }

        $tokenHash = $this->hash($rawSessionToken);
        $cacheKey = $this->cacheKey($tokenHash);
        $sessionId = $this->cacheGet($cacheKey);

        $session = $sessionId
            ? IdentitySession::query()->with(['user', 'device', 'lastApplication'])->find($sessionId)
            : IdentitySession::query()->with(['user', 'device', 'lastApplication'])
                ->where('session_token_hash', $tokenHash)
                ->first();

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

        $this->cacheSession($session, $rawSessionToken);
        return $session;
    }

    public function validForUser(IdentitySession $session, User $user): bool
    {
        return $session->active()
            && (int) $session->user_id === (int) $user->id
            && (int) $session->auth_version === max((int) ($user->auth_version ?? 1), 1);
    }

    public function rotateRefresh(Request $request, IdentitySession $session): ?string
    {
        $raw = (string) $request->cookie($this->refreshCookieName(), '');
        if ($raw === '') {
            return null;
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

            return $newRaw;
        }

        if ($session->previous_refresh_token_hash
            && $session->previous_refresh_valid_until?->isFuture()
            && hash_equals((string) $session->previous_refresh_token_hash, $hash)) {
            return null;
        }

        return null;
    }

    public function refreshMatches(Request $request, IdentitySession $session): bool
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

    public function replaceRefreshSecret(IdentitySession $session): string
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

    public function touch(IdentitySession $session, Request $request, ?Application $application = null): void
    {
        $session->forceFill([
            'last_application_id' => $application?->id ?? $session->last_application_id,
            'last_seen_at' => now(),
            'expires_at' => now()->addMinutes($this->sessionTtlMinutes()),
        ])->save();

        if ($session->device) {
            $session->device->forceFill([
                'last_seen_at' => now(),
                'last_ip_address' => $request->ip(),
            ])->save();
        }
    }

    public function revoke(IdentitySession $session, string $reason, ?Request $request = null): void
    {
        if (! $session->revoked_at) {
            $session->forceFill([
                'revoked_at' => now(),
                'revoke_reason' => $reason,
            ])->save();
        }

        $this->cacheForget($this->cacheKey((string) $session->session_token_hash));

        if ($request) {
            $this->audit($request, 'session_revoked', 'success', $session->user, $session, $session->lastApplication, [
                'reason' => $reason,
            ]);
        }
    }

    public function revokeUserSessions(User $user, string $reason, ?string $exceptSessionId = null, ?Request $request = null): int
    {
        $query = IdentitySession::query()
            ->where('user_id', $user->id)
            ->whereNull('revoked_at');

        if ($exceptSessionId) {
            $query->where('id', '!=', $exceptSessionId);
        }

        $sessions = $query->get();
        foreach ($sessions as $session) {
            $this->revoke($session, $reason, $request);
        }

        return $sessions->count();
    }

    public function issueCsrfToken(IdentitySession $session, Application $application, Request $request): string
    {
        $payload = [
            'sid' => $session->id,
            'app' => (string) $application->slug,
            'ori' => hash('sha256', (string) $request->header('Origin', '')),
            'exp' => now()->addSeconds((int) config('identity.csrf_ttl_seconds', 300))->timestamp,
            'n' => Str::random(24),
        ];

        $encoded = rtrim(strtr(base64_encode(json_encode($payload, JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');
        $signature = hash_hmac('sha256', $encoded, $this->csrfKey());
        return $encoded.'.'.$signature;
    }

    public function validateCsrfToken(string $token, IdentitySession $session, Application $application, Request $request): bool
    {
        [$encoded, $signature] = array_pad(explode('.', $token, 2), 2, '');
        if ($encoded === '' || $signature === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $encoded, $this->csrfKey());
        if (! hash_equals($expected, $signature)) {
            return false;
        }

        $json = base64_decode(strtr($encoded, '-_', '+/'), true);
        $payload = is_string($json) ? json_decode($json, true) : null;
        if (! is_array($payload)) {
            return false;
        }

        return hash_equals((string) $session->id, (string) ($payload['sid'] ?? ''))
            && hash_equals((string) $application->slug, (string) ($payload['app'] ?? ''))
            && hash_equals(hash('sha256', (string) $request->header('Origin', '')), (string) ($payload['ori'] ?? ''))
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

    public function audit(
        Request $request,
        string $eventType,
        string $outcome,
        ?User $user = null,
        ?IdentitySession $session = null,
        ?Application $application = null,
        array $metadata = []
    ): void {
        try {
            IdentityAuthEvent::query()->create([
                'user_id' => $user?->id,
                'identity_session_id' => $session?->id,
                'application_id' => $application?->id,
                'event_type' => $eventType,
                'outcome' => $outcome,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'metadata' => $metadata ?: null,
                'occurred_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::warning('Identity audit event could not be persisted.', [
                'event_type' => $eventType,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function resolveDevice(Request $request, User $user): ?IdentityDevice
    {
        $requestedUuid = trim((string) $request->header('X-Peter-Device', ''));
        $uuid = Str::isUuid($requestedUuid) ? $requestedUuid : (string) Str::uuid();
        [$browser, $platform] = $this->parseUserAgent((string) $request->userAgent());

        $device = IdentityDevice::query()->firstOrNew([
            'user_id' => $user->id,
            'uuid' => $uuid,
        ]);

        $isNew = ! $device->exists;
        $device->fill([
            'name' => trim((string) $request->header('X-Peter-Device-Name', '')) ?: trim($browser.' '.$platform),
            'platform' => $platform,
            'browser' => $browser,
            'user_agent' => $request->userAgent(),
            'last_ip_address' => $request->ip(),
            'first_seen_at' => $device->first_seen_at ?: now(),
            'last_seen_at' => now(),
        ]);
        $device->save();

        if ($isNew) {
            $this->audit($request, 'new_device', 'success', $user, null, null, [
                'device_uuid' => $uuid,
                'browser' => $browser,
                'platform' => $platform,
            ]);
        }

        return $device;
    }

    private function parseUserAgent(string $ua): array
    {
        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'OPR/') => 'Opera',
            str_contains($ua, 'Chrome/') => 'Chrome',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Safari/') => 'Safari',
            default => 'Browser',
        };

        $platform = match (true) {
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'iPhone'), str_contains($ua, 'iPad') => 'iOS',
            str_contains($ua, 'Mac OS') => 'macOS',
            str_contains($ua, 'Linux') => 'Linux',
            default => 'Device',
        };

        return [$browser, $platform];
    }

    private function cacheSession(IdentitySession $session, string $rawSessionToken): void
    {
        $ttl = max(60, now()->diffInSeconds($session->expires_at, false));
        $this->cachePut($this->cacheKey($this->hash($rawSessionToken)), $session->id, $ttl);
    }

    private function cacheKey(string $tokenHash): string
    {
        return 'identity:session:'.$tokenHash;
    }

    private function cacheGet(string $key): mixed
    {
        try {
            return Cache::store((string) config('identity.cache_store', 'redis'))->get($key);
        } catch (Throwable $e) {
            Log::notice('Identity cache unavailable; using database fallback.', ['message' => $e->getMessage()]);
            return null;
        }
    }

    private function cachePut(string $key, mixed $value, int $ttlSeconds): void
    {
        try {
            Cache::store((string) config('identity.cache_store', 'redis'))->put($key, $value, now()->addSeconds($ttlSeconds));
        } catch (Throwable $e) {
            Log::notice('Identity cache unavailable while persisting session index.', ['message' => $e->getMessage()]);
        }
    }

    private function cacheForget(string $key): void
    {
        try {
            Cache::store((string) config('identity.cache_store', 'redis'))->forget($key);
        } catch (Throwable $e) {
            Log::notice('Identity cache unavailable while revoking session index.', ['message' => $e->getMessage()]);
        }
    }

    private function hash(string $value): string
    {
        return hash('sha256', $value);
    }

    private function csrfKey(): string
    {
        return hash('sha256', (string) config('app.key').'|peter-identity-csrf');
    }

    private function sessionCookieName(): string
    {
        return (string) config('identity.session_cookie', 'peter_ecosystem_session');
    }

    private function refreshCookieName(): string
    {
        return (string) config('identity.refresh_cookie', 'peter_ecosystem_refresh');
    }

    private function sessionTtlMinutes(): int
    {
        return max(5, (int) config('identity.session_ttl_minutes', 10080));
    }

    private function refreshTtlMinutes(): int
    {
        return max($this->sessionTtlMinutes(), (int) config('identity.refresh_ttl_minutes', 43200));
    }

    private function refreshGraceSeconds(): int
    {
        return max(5, (int) config('identity.refresh_grace_seconds', 30));
    }
}
