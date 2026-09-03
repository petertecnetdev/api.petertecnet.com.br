<?php

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Models\IdentityDevice;
use App\Domain\Identity\Models\IdentityGlobalSession;
use App\Domain\Identity\Models\IdentitySession;
use App\Models\EcosystemAuditLog;

class IdentityMetricsService
{
    public function __construct(private readonly IdentityGlobalSessionService $globalSessions)
    {
    }

    public function snapshot(int $minutes = 60): array
    {
        $minutes = max(5, min(1440, $minutes));
        $since = now()->subMinutes($minutes);
        $events = EcosystemAuditLog::query()
            ->where('action', 'like', 'identity.%')
            ->where('created_at', '>=', $since)
            ->latest('id')
            ->limit(5000)
            ->get(['action', 'after', 'created_at']);

        $count = fn (array $names): int => $events->filter(fn ($event) => in_array($event->action, $names, true))->count();
        $successfulLogins = $count(['identity.new_session', 'identity.sso_exchanged', 'identity.global_sso_exchanged']);
        $failedLogins = $count(['identity.login_failed', 'identity.sso_exchange_failed', 'identity.global_sso_failed']);
        $attempts = $successfulLogins + $failedLogins;
        $legacy = $count(['identity.legacy_token_seen']);
        $redisLatency = $this->globalSessions->cacheLatencyMs();

        $apps = [];
        foreach ($events as $event) {
            $after = is_array($event->after) ? $event->after : [];
            $slug = (string) ($after['application_slug'] ?? $after['app'] ?? 'ecosystem');
            $apps[$slug] ??= ['events' => 0, 'failures' => 0, 'legacy_tokens' => 0];
            $apps[$slug]['events']++;
            if (str_contains($event->action, 'failed') || str_contains($event->action, 'rejected')) $apps[$slug]['failures']++;
            if ($event->action === 'identity.legacy_token_seen') $apps[$slug]['legacy_tokens']++;
        }
        ksort($apps);

        return [
            'window_minutes' => $minutes,
            'generated_at' => now()->toIso8601String(),
            'authentication' => [
                'successful' => $successfulLogins,
                'failed' => $failedLogins,
                'success_rate' => $attempts > 0 ? round(($successfulLogins / $attempts) * 100, 2) : 100.0,
                'sso_restores' => $count(['identity.global_sso_exchanged']),
                'step_up_granted' => $count(['identity.step_up_granted']),
                'step_up_failed' => $count(['identity.step_up_failed']),
            ],
            'sessions' => [
                'application_active' => IdentitySession::query()->whereNull('revoked_at')->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->count(),
                'global_active' => IdentityGlobalSession::query()->whereNull('revoked_at')->where('expires_at', '>', now())->where('refresh_expires_at', '>', now())->count(),
                'trusted_devices' => IdentityDevice::query()->where('trusted', true)->count(),
                'known_devices' => IdentityDevice::query()->count(),
            ],
            'legacy' => [
                'tokens_seen' => $legacy,
                'mode' => (string) config('identity.legacy_tokens.mode', 'observe'),
                'sunset_at' => config('identity.legacy_tokens.sunset_at'),
                'ready_to_enforce' => $legacy === 0,
            ],
            'infrastructure' => [
                'redis_latency_ms' => $redisLatency,
                'redis_available' => $redisLatency !== null,
                'database_fallback' => true,
            ],
            'applications' => $apps,
        ];
    }
}
