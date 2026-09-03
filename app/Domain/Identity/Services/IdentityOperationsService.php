<?php

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Models\IdentityGlobalSession;
use App\Domain\Identity\Models\IdentityRuntimeSetting;
use App\Domain\Identity\Models\IdentitySession;
use App\Domain\Identity\Models\IdentityTrustedDevice;
use App\Models\Application;
use App\Models\EcosystemAuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

class IdentityOperationsService
{
    public function observability(int $minutes = 60): array
    {
        $minutes = max(5, min($minutes, 1440));
        $since = now()->subMinutes($minutes);
        $logs = EcosystemAuditLog::query()
            ->where('created_at', '>=', $since)
            ->where('action', 'like', 'identity.%');

        $total = (clone $logs)->count();
        $failed = (clone $logs)->where(function ($query) {
            $query->where('action', 'like', '%.failed')
                ->orWhere('action', 'like', '%.rejected')
                ->orWhere('action', 'like', '%.rate_limited')
                ->orWhere('action', 'like', '%.context_rejected');
        })->count();
        $stepUpGranted = (clone $logs)->where('action', 'identity.step_up_verified')->count();
        $stepUpFailed = (clone $logs)->where('action', 'identity.step_up_failed')->count();
        $ssoRestores = (clone $logs)->where('action', 'identity.global_sso_exchanged')->count();

        $redisAvailable = null;
        $redisLatency = null;
        try {
            $start = microtime(true);
            $cache = Cache::store((string) config('identity.global_sso.cache_store', 'redis'));
            $key = 'identity:health:'.bin2hex(random_bytes(6));
            $cache->put($key, 'ok', 10);
            $redisAvailable = $cache->get($key) === 'ok';
            $cache->forget($key);
            $redisLatency = round((microtime(true) - $start) * 1000, 2);
        } catch (\Throwable) {
            $redisAvailable = false;
        }

        $globalActive = IdentityGlobalSession::query()
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->where('refresh_expires_at', '>', now())
            ->count();
        $applicationActive = IdentitySession::query()
            ->whereNull('revoked_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->count();
        $trusted = IdentityTrustedDevice::query()
            ->whereNull('revoked_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->count();
        $known = IdentitySession::query()
            ->whereNotNull('user_agent')
            ->distinct('user_agent')
            ->count('user_agent');

        $legacySeen = (clone $logs)->where('action', 'identity.legacy_token_seen')->count();
        $legacyMode = (string) config('identity.legacy.mode', 'observe');

        return [
            'window_minutes' => $minutes,
            'authentication' => [
                'events' => $total,
                'failed' => $failed,
                'success_rate' => $total > 0 ? round((($total - $failed) / $total) * 100, 2) : 100,
                'sso_restores' => $ssoRestores,
                'step_up_granted' => $stepUpGranted,
                'step_up_failed' => $stepUpFailed,
            ],
            'sessions' => [
                'application_active' => $applicationActive,
                'global_active' => $globalActive,
                'trusted_devices' => $trusted,
                'known_devices' => $known,
            ],
            'legacy' => [
                'mode' => $legacyMode,
                'tokens_seen' => $legacySeen,
                'sunset_at' => config('identity.legacy.sunset_at'),
                'ready_to_enforce' => $legacySeen === 0,
            ],
            'infrastructure' => [
                'redis_available' => $redisAvailable,
                'redis_latency_ms' => $redisLatency,
            ],
        ];
    }

    public function rollout(): array
    {
        $stored = IdentityRuntimeSetting::query()->where('key', 'global_sso_rollout')->value('value');
        if (is_string($stored)) {
            $stored = json_decode($stored, true);
        }
        if (is_array($stored)) {
            return $this->normalizeRollout($stored);
        }

        $apps = Application::query()->where('is_active', true)->orderBy('slug')->pluck('slug')->all();
        return $this->normalizeRollout([
            'enabled' => (bool) config('identity.features.global_sso', true),
            'default_percentage' => 100,
            'applications' => array_fill_keys($apps, ['percentage' => 100]),
        ]);
    }

    public function updateRollout(User $actor, array $value): array
    {
        $normalized = $this->normalizeRollout($value);
        IdentityRuntimeSetting::query()->updateOrCreate(
            ['key' => 'global_sso_rollout'],
            ['value' => $normalized, 'updated_by' => $actor->id]
        );
        return $normalized;
    }

    private function normalizeRollout(array $value): array
    {
        $default = max(0, min(100, (int) ($value['default_percentage'] ?? 100)));
        $applications = [];
        foreach ((array) ($value['applications'] ?? []) as $slug => $config) {
            $applications[(string) $slug] = [
                'percentage' => max(0, min(100, (int) data_get($config, 'percentage', $default))),
            ];
        }

        return [
            'enabled' => (bool) ($value['enabled'] ?? true),
            'default_percentage' => $default,
            'applications' => $applications,
        ];
    }
}
