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
    public function __construct(
        private readonly IdentityDeviceService $devices,
        private readonly IdentityRiskService $risk,
        private readonly IdentityTrustedDeviceService $trustedDevices,
    ) {
    }

    public function establish(Request $request, User $user): array
    {
        $rawSession = (string) $request->cookie($this->sessionCookieName(), '');
        $current = $this->resolve($request);

        if ($current && (int) $current->user_id === (int) $user->id && $this->validForUser($current, $user)) {
            $assessment = $this->assessment($user, $request);
            if ($assessment['score'] >= (int) config('identity.step_up.critical_risk_score', 80)) {
                $this->revoke($current, 'critical_risk_context');
                $current = null;
            } else {
                $this->applyRisk($current, $assessment);
                $this->touch($current, $request);
                $rawRefresh = null;
                if (! $this->refreshMatches($request, $current)) {
                    $rawRefresh = $this->replaceRefreshSecret($current);
                }
                return [
                    'session' => $current->fresh(['user', 'trustedDevice']),
                    'session_token' => $rawSession,
                    'refresh_token' => $rawRefresh,
                    'created' => false,
                ];
            }
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
        $assessment = $this->assessment($user, $request);
        $context = $assessment['context'];
        $trusted = $assessment['trusted_device'];
        $now = now();

        $session = IdentityGlobalSession::query()->create([
            'session_id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'trusted_device_id' => $trusted?->id,
            'session_token_hash' => $this->hash($rawSession),
            'refresh_token_hash' => $this->hash($rawRefresh),
            'auth_version' => max((int) ($user->auth_version ?? 1), 1),
            'device_label' => $context['label'],
            'ip_address' => $context['ip'],
            'country_code' => $context['country'],
            'user_agent' => $context['user_agent'],
            'accept_language' => $context['language'],
            'risk_score' => $assessment['score'],
            'risk_reasons' => $assessment['reasons'],
            'last_seen_at' => $now,
            'idle_expires_at' => $now->copy()->addMinutes($this->idleTtlMinutes()),
            'expires_at' => $now->copy()->addMinutes($this->sessionTtlMinutes()),
            'absolute_expires_at' => $now->copy()->addMinutes($this->absoluteTtlMinutes()),
            'refresh_expires_at' => $now->copy()->addMinutes($this->refreshTtlMinutes()),
            'metadata' => [
                'created_origin' => $request->header('Origin'),
                'used_refresh_hashes' => [],
            ],
        ]);

        $this->cacheSession($session);
        return ['session' => $session->load(['user', 'trustedDevice']), 'session_token' => $rawSession, 'refresh_token' => $rawRefresh, 'created' => true];
    }

    public function resolve(Request $request): ?IdentityGlobalSession
    {
        $raw = (string) $request->cookie($this->sessionCookieName(), '');
        if ($raw === '') return null;

        $hash = $this->hash($raw);
        $cacheKey = $this->cacheKey($hash);
        $id = $this->cacheGet($cacheKey);
        $session = $id
            ? IdentityGlobalSession::query()->with(['user', 'trustedDevice'])->find($id)
            : IdentityGlobalSession::query()->with(['user', 'trustedDevice'])->where('session_token_hash', $hash)->first();

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
        if ($raw === '') return false;
        $hash = $this->hash($raw);
        if (hash_equals((string) $session->refresh_token_hash, $hash)) return true;
        return $session->previous_refresh_token_hash
            && $session->previous_refresh_valid_until?->isFuture()
            && hash_equals((string) $session->previous_refresh_token_hash, $hash);
    }

    public function rotateRefresh(Request $request, IdentityGlobalSession $session): array
    {
        $raw = (string) $request->cookie($this->refreshCookieName(), '');
        if ($raw === '') return ['valid' => false, 'refresh_token' => null, 'rotated' => false, 'reused' => false];

        $hash = $this->hash($raw);
        if (hash_equals((string) $session->refresh_token_hash, $hash)) {
            $newRaw = Str::random(96);
            $this->rememberUsedRefresh($session, (string) $session->refresh_token_hash);
            $session->forceFill([
                'previous_refresh_token_hash' => $session->refresh_token_hash,
                'previous_refresh_valid_until' => now()->addSeconds($this->refreshGraceSeconds()),
                'refresh_token_hash' => $this->hash($newRaw),
                'refresh_expires_at' => now()->addMinutes($this->refreshTtlMinutes()),
            ])->save();
            return ['valid' => true, 'refresh_token' => $newRaw, 'rotated' => true, 'reused' => false];
        }

        if ($session->previous_refresh_token_hash
            && $session->previous_refresh_valid_until?->isFuture()
            && hash_equals((string) $session->previous_refresh_token_hash, $hash)) {
            return ['valid' => true, 'refresh_token' => null, 'rotated' => false, 'reused' => false];
        }

        $history = collect((array) data_get($session->metadata, 'used_refresh_hashes', []))->pluck('hash')->filter()->all();
        $reused = in_array($hash, $history, true)
            || ($session->previous_refresh_token_hash && hash_equals((string) $session->previous_refresh_token_hash, $hash));

        return ['valid' => false, 'refresh_token' => null, 'rotated' => false, 'reused' => $reused];
    }

    public function replaceRefreshSecret(IdentityGlobalSession $session): string
    {
        $newRaw = Str::random(96);
        $this->rememberUsedRefresh($session, (string) $session->refresh_token_hash);
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
        if (! $session->isActive()) {
            $this->revoke($session, 'session_expired');
            return;
        }
        $context = $this->devices->context($request);
        $session->forceFill([
            'last_seen_at' => now(),
            'idle_expires_at' => now()->addMinutes($this->idleTtlMinutes()),
            'expires_at' => now()->addMinutes($this->sessionTtlMinutes()),
            'ip_address' => $context['ip'],
            'country_code' => $context['country'],
            'accept_language' => $context['language'],
        ])->save();
        $this->cacheSession($session);
    }

    public function hasHighRiskContextChange(IdentityGlobalSession $session, Request $request): bool
    {
        return $session->user_agent && $request->userAgent()
            ? ! $this->devices->samePlatformBrowser($session->user_agent, $request)
            : false;
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
        foreach ($sessions as $session) $this->revoke($session, $reason);
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
        if ($encoded === '' || $signature === '') return false;
        if (! hash_equals(hash_hmac('sha256', $encoded, $this->csrfKey()), $signature)) return false;
        $json = $this->base64UrlDecode($encoded);
        $payload = is_string($json) ? json_decode($json, true) : null;
        if (! is_array($payload)) return false;
        return hash_equals((string) $session->session_id, (string) ($payload['sid'] ?? ''))
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
            'device' => $session->device_label,
            'trusted' => (bool) $session->trusted_device_id,
            'risk' => ['score' => (int) $session->risk_score, 'reasons' => $session->risk_reasons ?: []],
            'country' => $session->country_code,
            'ip' => $session->ip_address,
            'last_seen_at' => $session->last_seen_at?->toIso8601String(),
            'idle_expires_at' => $session->idle_expires_at?->toIso8601String(),
            'created_at' => $session->created_at?->toIso8601String(),
            'expires_at' => $session->expires_at?->toIso8601String(),
            'absolute_expires_at' => $session->absolute_expires_at?->toIso8601String(),
            'refresh_expires_at' => $session->refresh_expires_at?->toIso8601String(),
        ];
    }

    private function assessment(User $user, Request $request): array
    {
        $trusted = config('identity.features.trusted_devices', true) ? $this->trustedDevices->resolve($user, $request) : null;
        $result = $this->risk->assess($user, $request, $trusted);
        $result['trusted_device'] = $trusted;
        return $result;
    }

    private function applyRisk(IdentityGlobalSession $session, array $assessment): void
    {
        $session->forceFill([
            'trusted_device_id' => $assessment['trusted_device']?->id,
            'risk_score' => $assessment['score'],
            'risk_reasons' => $assessment['reasons'],
        ])->save();
    }

    private function rememberUsedRefresh(IdentityGlobalSession $session, string $hash): void
    {
        if ($hash === '') return;
        $metadata = $session->metadata ?: [];
        $history = array_values((array) ($metadata['used_refresh_hashes'] ?? []));
        array_unshift($history, ['hash' => $hash, 'used_at' => now()->toIso8601String()]);
        $metadata['used_refresh_hashes'] = array_slice($history, 0, max((int) config('identity.global_sso.refresh_reuse_history', 8), 2));
        $session->metadata = $metadata;
        $session->save();
    }

    private function cacheSession(IdentityGlobalSession $session): void
    {
        $expiries = array_filter([
            $session->expires_at?->timestamp,
            $session->absolute_expires_at?->timestamp,
            $session->idle_expires_at?->timestamp,
        ]);
        $nearest = $expiries ? min($expiries) : now()->addMinutes($this->sessionTtlMinutes())->timestamp;
        $ttl = max(60, $nearest - now()->timestamp);
        $this->cachePut($this->cacheKey((string) $session->session_token_hash), $session->id, $ttl);
    }

    private function cacheKey(string $hash): string { return 'identity:global-session:'.$hash; }
    private function cacheGet(string $key): mixed
    {
        try { return Cache::store($this->cacheStore())->get($key); }
        catch (Throwable $e) { Log::notice('Identity global-session cache unavailable; database fallback enabled.', ['message' => $e->getMessage()]); return null; }
    }
    private function cachePut(string $key, mixed $value, int $ttlSeconds): void
    {
        try { Cache::store($this->cacheStore())->put($key, $value, now()->addSeconds($ttlSeconds)); }
        catch (Throwable $e) { Log::notice('Identity global-session cache unavailable; continuing with database persistence.', ['message' => $e->getMessage()]); }
    }
    private function cacheForget(string $key): void { try { Cache::store($this->cacheStore())->forget($key); } catch (Throwable) {} }
    private function hash(string $value): string { return hash('sha256', $value); }
    private function base64UrlDecode(string $value): string|false
    {
        $padding = strlen($value) % 4;
        if ($padding) $value .= str_repeat('=', 4 - $padding);
        return base64_decode(strtr($value, '-_', '+/'), true);
    }
    private function csrfKey(): string { return hash('sha256', (string) config('app.key')); }
    private function cacheStore(): string { return (string) config('identity.global_sso.cache_store', app()->environment('testing') ? 'array' : 'redis'); }
    private function sessionCookieName(): string { return (string) config('identity.global_sso.session_cookie', 'peter_ecosystem_session'); }
    private function refreshCookieName(): string { return (string) config('identity.global_sso.refresh_cookie', 'peter_ecosystem_refresh'); }
    private function sessionTtlMinutes(): int { return max((int) config('identity.global_sso.session_ttl_minutes', 10080), 5); }
    private function absoluteTtlMinutes(): int { return max((int) config('identity.global_sso.absolute_ttl_minutes', 43200), $this->sessionTtlMinutes()); }
    private function idleTtlMinutes(): int { return max((int) config('identity.global_sso.idle_ttl_minutes', 10080), 5); }
    private function refreshTtlMinutes(): int { return max((int) config('identity.global_sso.refresh_ttl_minutes', 43200), $this->sessionTtlMinutes()); }
    private function refreshGraceSeconds(): int { return max((int) config('identity.global_sso.refresh_grace_seconds', 30), 5); }
    private function csrfTtlSeconds(): int { return max((int) config('identity.global_sso.csrf_ttl_seconds', 300), 60); }
}
