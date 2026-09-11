<?php

namespace App\Services;

use App\Models\Application;
use Illuminate\Support\Facades\Cache;

final class ApplicationRuntimeControlService
{
    private const CACHE_SECONDS = 30;

    private const DEFAULTS = [
        'mode' => 'normal',
        'processing_enabled' => true,
        'scheduled_processing_enabled' => true,
        'market_scanner_enabled' => true,
        'ai_enabled' => true,
        'notifications_enabled' => true,
        'emails_enabled' => true,
        'reports_enabled' => true,
        'realtime_enabled' => true,
        'scan_interval_minutes' => 5,
        'idle_timeout_minutes' => 15,
        'reason' => null,
        'updated_by' => null,
        'updated_at' => null,
    ];

    public function settings(Application|string|int|null $application): array
    {
        $model = $this->resolve($application);

        if (! $model) {
            return $this->offSettings('Aplicação não encontrada.');
        }

        return Cache::remember(
            $this->cacheKey((int) $model->id),
            now()->addSeconds(self::CACHE_SECONDS),
            fn (): array => $this->normalize((array) ($model->runtime_settings ?: [])),
        );
    }

    public function update(Application $application, array $changes, ?int $updatedBy = null): array
    {
        $current = $this->normalize((array) ($application->runtime_settings ?: []));
        $mode = (string) ($changes['mode'] ?? $current['mode']);

        $next = array_merge($current, $this->modePreset($mode), $changes);
        $next = $this->normalize($next);

        if ($next['mode'] === 'off') {
            $next = array_merge($next, $this->modePreset('off'));
        }

        $next['updated_by'] = $updatedBy;
        $next['updated_at'] = now()->toIso8601String();

        $application->forceFill(['runtime_settings' => $next])->save();
        Cache::forget($this->cacheKey((int) $application->id));

        return $next;
    }

    public function allows(Application|string|int|null $application, string $feature = 'processing_enabled'): bool
    {
        $settings = $this->settings($application);

        if ($settings['mode'] === 'off' || ! $settings['processing_enabled']) {
            return false;
        }

        return (bool) ($settings[$feature] ?? false);
    }

    public function allowsScheduledMarketProcessing(Application|string|int|null $application): bool
    {
        return $this->allows($application, 'scheduled_processing_enabled')
            && $this->allows($application, 'market_scanner_enabled');
    }

    public function shouldRunScheduledMarketScan(Application|string|int|null $application): bool
    {
        if (! $this->allowsScheduledMarketProcessing($application)) {
            return false;
        }

        $interval = max(1, (int) $this->settings($application)['scan_interval_minutes']);

        return $interval === 1 || ((int) floor(now()->timestamp / 60) % $interval) === 0;
    }

    public function cacheForget(Application|int $application): void
    {
        $id = $application instanceof Application ? (int) $application->id : (int) $application;
        Cache::forget($this->cacheKey($id));
    }

    private function resolve(Application|string|int|null $application): ?Application
    {
        if ($application instanceof Application) {
            return $application;
        }

        if ($application === null || $application === '') {
            return null;
        }

        $query = Application::query();

        if (is_int($application) || ctype_digit((string) $application)) {
            return $query->find((int) $application);
        }

        return $query->where('slug', (string) $application)->first();
    }

    private function normalize(array $settings): array
    {
        $normalized = array_merge(self::DEFAULTS, $settings);
        $normalized['mode'] = in_array($normalized['mode'], ['off', 'on_demand', 'normal', 'realtime'], true)
            ? $normalized['mode']
            : 'normal';

        foreach ([
            'processing_enabled',
            'scheduled_processing_enabled',
            'market_scanner_enabled',
            'ai_enabled',
            'notifications_enabled',
            'emails_enabled',
            'reports_enabled',
            'realtime_enabled',
        ] as $field) {
            $normalized[$field] = (bool) $normalized[$field];
        }

        $normalized['scan_interval_minutes'] = max(1, min((int) $normalized['scan_interval_minutes'], 1440));
        $normalized['idle_timeout_minutes'] = max(1, min((int) $normalized['idle_timeout_minutes'], 1440));
        $normalized['reason'] = $normalized['reason'] !== null ? trim((string) $normalized['reason']) : null;

        return $normalized;
    }

    private function modePreset(string $mode): array
    {
        return match ($mode) {
            'off' => [
                'mode' => 'off',
                'processing_enabled' => false,
                'scheduled_processing_enabled' => false,
                'market_scanner_enabled' => false,
                'ai_enabled' => false,
                'notifications_enabled' => false,
                'emails_enabled' => false,
                'reports_enabled' => false,
                'realtime_enabled' => false,
            ],
            'on_demand' => [
                'mode' => 'on_demand',
                'processing_enabled' => true,
                'scheduled_processing_enabled' => false,
                'market_scanner_enabled' => true,
                'notifications_enabled' => false,
                'emails_enabled' => false,
                'reports_enabled' => false,
                'realtime_enabled' => false,
            ],
            'realtime' => [
                'mode' => 'realtime',
                'processing_enabled' => true,
                'scheduled_processing_enabled' => true,
                'market_scanner_enabled' => true,
                'notifications_enabled' => true,
                'emails_enabled' => true,
                'reports_enabled' => true,
                'realtime_enabled' => true,
                'scan_interval_minutes' => 1,
            ],
            default => [
                'mode' => 'normal',
                'processing_enabled' => true,
                'scheduled_processing_enabled' => true,
                'market_scanner_enabled' => true,
                'notifications_enabled' => true,
                'emails_enabled' => true,
                'reports_enabled' => true,
                'realtime_enabled' => true,
                'scan_interval_minutes' => 5,
            ],
        };
    }

    private function offSettings(?string $reason = null): array
    {
        return $this->normalize(array_merge($this->modePreset('off'), ['reason' => $reason]));
    }

    private function cacheKey(int $applicationId): string
    {
        return 'application-runtime:'.$applicationId;
    }
}
