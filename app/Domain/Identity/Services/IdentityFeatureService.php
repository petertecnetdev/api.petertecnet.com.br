<?php

namespace App\Domain\Identity\Services;

use App\Models\Application;
use App\Models\EcosystemSetting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

class IdentityFeatureService
{
    private const CACHE_KEY = 'identity:rollout:v1';

    public function globalSsoEnabled(User $user, Application $application): bool
    {
        $rollout = $this->rollout();
        if (! (bool) ($rollout['enabled'] ?? config('identity.rollout.global_sso_enabled', false))) {
            return false;
        }

        $appConfig = is_array($rollout['applications'][$application->slug] ?? null)
            ? $rollout['applications'][$application->slug]
            : [];

        if (array_key_exists('enabled', $appConfig) && ! (bool) $appConfig['enabled']) {
            return false;
        }

        $percentage = max(0, min(100, (int) ($appConfig['percentage']
            ?? $rollout['default_percentage']
            ?? config('identity.rollout.default_percentage', 0))));

        if ($percentage >= 100) {
            return true;
        }
        if ($percentage <= 0) {
            return false;
        }

        $bucket = abs(crc32($user->getKey().'|'.$application->slug.'|peter-identity')) % 100;
        return $bucket < $percentage;
    }

    public function rollout(): array
    {
        return Cache::remember(self::CACHE_KEY, now()->addSeconds(30), function () {
            $setting = EcosystemSetting::query()
                ->where('group', 'identity')
                ->where('key', 'global_sso_rollout')
                ->first();

            if ($setting && is_array($setting->value)) {
                return $setting->value;
            }

            return [
                'enabled' => (bool) config('identity.rollout.global_sso_enabled', false),
                'default_percentage' => (int) config('identity.rollout.default_percentage', 0),
                'applications' => [],
            ];
        });
    }

    public function replaceRollout(array $value, User $actor): array
    {
        $normalized = [
            'enabled' => (bool) ($value['enabled'] ?? false),
            'default_percentage' => max(0, min(100, (int) ($value['default_percentage'] ?? 0))),
            'applications' => [],
        ];

        foreach (($value['applications'] ?? []) as $slug => $config) {
            if (! is_string($slug) || ! is_array($config)) {
                continue;
            }
            $normalized['applications'][$slug] = [
                'enabled' => array_key_exists('enabled', $config) ? (bool) $config['enabled'] : true,
                'percentage' => max(0, min(100, (int) ($config['percentage'] ?? $normalized['default_percentage']))),
            ];
        }

        EcosystemSetting::query()->updateOrCreate(
            ['group' => 'identity', 'key' => 'global_sso_rollout'],
            ['value' => $normalized, 'is_public' => false, 'updated_by' => $actor->id]
        );
        Cache::forget(self::CACHE_KEY);

        return $normalized;
    }
}
