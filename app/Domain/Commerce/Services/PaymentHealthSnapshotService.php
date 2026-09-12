<?php

namespace App\Domain\Commerce\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

final class PaymentHealthSnapshotService
{
    public function __construct(private readonly PaymentPendingHealthService $health)
    {
    }

    public function captureForApplication(int $appId): array
    {
        $current = $this->health->forApplication($appId);

        if (Schema::hasTable('commerce_payment_health_snapshots')) {
            DB::table('commerce_payment_health_snapshots')->insert([
                'app_id' => $appId,
                'pending_orders' => $current['pending_orders'],
                'pending_volume' => $current['pending_volume'],
                'critical_orders' => $current['critical_orders'],
                'critical_volume' => $current['critical_volume'],
                'expired_orders' => $current['expired_orders'],
                'provider_pending_payments' => $current['provider_pending_payments'],
                'at_risk_volume' => $current['at_risk_volume'],
                'risk_level' => $current['risk_level'],
                'oldest_pending_age_minutes' => $current['oldest_pending_age_minutes'],
                'measured_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $trend = $this->trendForApplication($appId, $current);
        $this->trackAnomalyIncident($appId, $current, $trend);
        $current['trend'] = $trend;

        return $current;
    }

    public function trendForApplication(int $appId, array $current): array
    {
        if (! Schema::hasTable('commerce_payment_health_snapshots')) {
            return $this->emptyTrend('history_unavailable');
        }

        $baseline = DB::table('commerce_payment_health_snapshots')
            ->where('app_id', $appId)
            ->whereBetween('measured_at', [now()->subHours(25), now()->subHour()])
            ->selectRaw('COUNT(*) as samples')
            ->selectRaw('AVG(CASE WHEN pending_orders > 0 THEN critical_orders / pending_orders ELSE 0 END) as avg_critical_rate')
            ->selectRaw('AVG(at_risk_volume) as avg_at_risk_volume')
            ->selectRaw('AVG(pending_orders) as avg_pending_orders')
            ->first();

        $samples = (int) ($baseline->samples ?? 0);
        if ($samples < 6) {
            return $this->emptyTrend('insufficient_history', $samples);
        }

        $baselineCriticalRate = round((float) ($baseline->avg_critical_rate ?? 0), 4);
        $baselineAtRiskVolume = round((float) ($baseline->avg_at_risk_volume ?? 0), 2);
        $baselinePendingOrders = round((float) ($baseline->avg_pending_orders ?? 0), 2);
        $currentCriticalRate = (float) ($current['critical_rate'] ?? 0);
        $currentAtRiskVolume = (float) ($current['at_risk_volume'] ?? 0);

        $criticalRateAnomaly = ($current['critical_orders'] ?? 0) > 0
            && $currentCriticalRate >= max(0.10, $baselineCriticalRate * 2);
        $volumeAnomaly = $currentAtRiskVolume >= max(100.0, $baselineAtRiskVolume * 2);
        $isAnomaly = $criticalRateAnomaly || $volumeAnomaly;

        return [
            'status' => $isAnomaly ? 'anomaly' : 'normal',
            'is_anomaly' => $isAnomaly,
            'samples' => $samples,
            'window_hours' => 24,
            'baseline_critical_rate' => $baselineCriticalRate,
            'baseline_at_risk_volume' => $baselineAtRiskVolume,
            'baseline_pending_orders' => $baselinePendingOrders,
            'critical_rate_multiplier' => $baselineCriticalRate > 0 ? round($currentCriticalRate / $baselineCriticalRate, 2) : null,
            'at_risk_volume_multiplier' => $baselineAtRiskVolume > 0 ? round($currentAtRiskVolume / $baselineAtRiskVolume, 2) : null,
            'signals' => array_values(array_filter([
                $criticalRateAnomaly ? 'critical_rate_above_baseline' : null,
                $volumeAnomaly ? 'at_risk_volume_above_baseline' : null,
            ])),
        ];
    }

    public function incidentHistoryForApplication(int $appId): array
    {
        $active = Cache::get("commerce:payment-health:incident:{$appId}");
        $activeIncident = is_array($active) && ($active['status'] ?? null) === 'anomaly'
            ? [
                'started_at' => $active['started_at'] ?? null,
                'duration_seconds' => ! empty($active['started_at'])
                    ? CarbonImmutable::parse($active['started_at'])->diffInSeconds(now())
                    : null,
                'peak_at_risk_volume' => round((float) ($active['peak_at_risk_volume'] ?? 0), 2),
                'peak_pending_orders' => (int) ($active['peak_pending_orders'] ?? 0),
                'peak_critical_orders' => (int) ($active['peak_critical_orders'] ?? 0),
                'peak_provider_pending_payments' => (int) ($active['peak_provider_pending_payments'] ?? 0),
                'recovered_paid_orders' => (int) ($active['recovered_paid_orders'] ?? 0),
                'recovered_fulfillments' => (int) ($active['recovered_fulfillments'] ?? 0),
                'recovered_delivery_retries' => (int) ($active['recovered_delivery_retries'] ?? 0),
                'recovered_gmv' => round((float) ($active['recovered_gmv'] ?? 0), 2),
                'last_recovery_at' => $active['last_recovery_at'] ?? null,
            ]
            : null;

        if (! Schema::hasTable('commerce_payment_health_incidents')) {
            return [
                'status' => 'history_unavailable',
                'window_days' => 30,
                'active' => $activeIncident,
                'incidents' => 0,
                'average_duration_seconds' => null,
                'max_duration_seconds' => null,
                'peak_at_risk_volume' => 0.0,
                'recovered_paid_orders' => 0,
                'recovered_fulfillments' => 0,
                'recovered_delivery_retries' => 0,
                'recovered_gmv' => 0.0,
                'recent' => [],
            ];
        }

        $windowStart = now()->subDays(30);
        $summary = DB::table('commerce_payment_health_incidents')
            ->where('app_id', $appId)
            ->where('started_at', '>=', $windowStart)
            ->selectRaw('COUNT(*) as incidents')
            ->selectRaw('AVG(duration_seconds) as average_duration_seconds')
            ->selectRaw('MAX(duration_seconds) as max_duration_seconds')
            ->selectRaw('MAX(peak_at_risk_volume) as peak_at_risk_volume')
            ->selectRaw('SUM(recovered_paid_orders) as recovered_paid_orders')
            ->selectRaw('SUM(recovered_fulfillments) as recovered_fulfillments')
            ->selectRaw('SUM(recovered_delivery_retries) as recovered_delivery_retries')
            ->selectRaw('SUM(recovered_gmv) as recovered_gmv')
            ->first();

        $recent = DB::table('commerce_payment_health_incidents')
            ->where('app_id', $appId)
            ->orderByDesc('started_at')
            ->limit(5)
            ->get([
                'started_at',
                'recovered_at',
                'duration_seconds',
                'peak_at_risk_volume',
                'peak_pending_orders',
                'peak_critical_orders',
                'peak_provider_pending_payments',
                'recovered_paid_orders',
                'recovered_fulfillments',
                'recovered_delivery_retries',
                'recovered_gmv',
                'last_recovery_at',
                'closing_at_risk_volume',
                'signals',
            ])
            ->map(fn ($row) => [
                'started_at' => $row->started_at,
                'recovered_at' => $row->recovered_at,
                'duration_seconds' => $row->duration_seconds !== null ? (int) $row->duration_seconds : null,
                'peak_at_risk_volume' => round((float) $row->peak_at_risk_volume, 2),
                'peak_pending_orders' => (int) $row->peak_pending_orders,
                'peak_critical_orders' => (int) $row->peak_critical_orders,
                'peak_provider_pending_payments' => (int) $row->peak_provider_pending_payments,
                'recovered_paid_orders' => (int) $row->recovered_paid_orders,
                'recovered_fulfillments' => (int) $row->recovered_fulfillments,
                'recovered_delivery_retries' => (int) $row->recovered_delivery_retries,
                'recovered_gmv' => round((float) $row->recovered_gmv, 2),
                'last_recovery_at' => $row->last_recovery_at,
                'closing_at_risk_volume' => $row->closing_at_risk_volume !== null ? round((float) $row->closing_at_risk_volume, 2) : null,
                'signals' => is_string($row->signals) ? (json_decode($row->signals, true) ?: []) : ($row->signals ?? []),
            ])
            ->values()
            ->all();

        return [
            'status' => 'available',
            'window_days' => 30,
            'active' => $activeIncident,
            'incidents' => (int) ($summary->incidents ?? 0),
            'average_duration_seconds' => $summary?->average_duration_seconds !== null
                ? (int) round((float) $summary->average_duration_seconds)
                : null,
            'max_duration_seconds' => $summary?->max_duration_seconds !== null
                ? (int) $summary->max_duration_seconds
                : null,
            'peak_at_risk_volume' => round((float) ($summary->peak_at_risk_volume ?? 0), 2),
            'recovered_paid_orders' => (int) ($summary->recovered_paid_orders ?? 0),
            'recovered_fulfillments' => (int) ($summary->recovered_fulfillments ?? 0),
            'recovered_delivery_retries' => (int) ($summary->recovered_delivery_retries ?? 0),
            'recovered_gmv' => round((float) ($summary->recovered_gmv ?? 0), 2),
            'recent' => $recent,
        ];
    }

    private function trackAnomalyIncident(int $appId, array $current, array $trend): void
    {
        if (! in_array($trend['status'] ?? null, ['normal', 'anomaly'], true)) {
            return;
        }

        $cacheKey = "commerce:payment-health:incident:{$appId}";
        $legacyCacheKey = "commerce:payment-health:trend-status:{$appId}";
        $incident = Cache::get($cacheKey);
        $legacyStatus = Cache::get($legacyCacheKey);
        $currentStatus = (string) $trend['status'];

        if (! is_array($incident) && $legacyStatus === 'anomaly') {
            $incident = [
                'status' => 'anomaly',
                'started_at' => null,
                'peak_at_risk_volume' => round((float) ($current['at_risk_volume'] ?? 0), 2),
                'peak_pending_orders' => (int) ($current['pending_orders'] ?? 0),
                'peak_critical_orders' => (int) ($current['critical_orders'] ?? 0),
                'peak_provider_pending_payments' => (int) ($current['provider_pending_payments'] ?? 0),
                'recovered_paid_orders' => 0,
                'recovered_fulfillments' => 0,
                'recovered_delivery_retries' => 0,
                'recovered_gmv' => 0.0,
                'last_recovery_at' => null,
                'signals' => $trend['signals'] ?? [],
            ];
        }

        if ($currentStatus === 'anomaly') {
            $isNewIncident = ! is_array($incident) || ($incident['status'] ?? null) !== 'anomaly';
            $incident = [
                'status' => 'anomaly',
                'started_at' => $isNewIncident ? now()->toIso8601String() : ($incident['started_at'] ?? null),
                'peak_at_risk_volume' => max(
                    round((float) ($current['at_risk_volume'] ?? 0), 2),
                    (float) ($incident['peak_at_risk_volume'] ?? 0)
                ),
                'peak_pending_orders' => max(
                    (int) ($current['pending_orders'] ?? 0),
                    (int) ($incident['peak_pending_orders'] ?? 0)
                ),
                'peak_critical_orders' => max(
                    (int) ($current['critical_orders'] ?? 0),
                    (int) ($incident['peak_critical_orders'] ?? 0)
                ),
                'peak_provider_pending_payments' => max(
                    (int) ($current['provider_pending_payments'] ?? 0),
                    (int) ($incident['peak_provider_pending_payments'] ?? 0)
                ),
                'recovered_paid_orders' => (int) ($incident['recovered_paid_orders'] ?? 0),
                'recovered_fulfillments' => (int) ($incident['recovered_fulfillments'] ?? 0),
                'recovered_delivery_retries' => (int) ($incident['recovered_delivery_retries'] ?? 0),
                'recovered_gmv' => round((float) ($incident['recovered_gmv'] ?? 0), 2),
                'last_recovery_at' => $incident['last_recovery_at'] ?? null,
                'signals' => array_values(array_unique(array_merge(
                    $incident['signals'] ?? [],
                    $trend['signals'] ?? []
                ))),
            ];

            Cache::put($cacheKey, $incident, now()->addDays(7));
            Cache::put($legacyCacheKey, 'anomaly', now()->addDays(7));

            if (! $isNewIncident) {
                return;
            }

            Log::warning('commerce.payment_health.anomaly_detected', [
                'app_id' => $appId,
                'incident_started_at' => $incident['started_at'],
                'risk_level' => $current['risk_level'] ?? 'unknown',
                'pending_orders' => (int) ($current['pending_orders'] ?? 0),
                'critical_orders' => (int) ($current['critical_orders'] ?? 0),
                'provider_pending_payments' => (int) ($current['provider_pending_payments'] ?? 0),
                'at_risk_volume' => round((float) ($current['at_risk_volume'] ?? 0), 2),
                'oldest_pending_age_minutes' => $current['oldest_pending_age_minutes'] ?? null,
                'baseline_samples' => (int) ($trend['samples'] ?? 0),
                'baseline_critical_rate' => $trend['baseline_critical_rate'] ?? null,
                'baseline_at_risk_volume' => $trend['baseline_at_risk_volume'] ?? null,
                'critical_rate_multiplier' => $trend['critical_rate_multiplier'] ?? null,
                'at_risk_volume_multiplier' => $trend['at_risk_volume_multiplier'] ?? null,
                'signals' => $trend['signals'] ?? [],
            ]);

            return;
        }

        Cache::put($legacyCacheKey, 'normal', now()->addDays(7));

        if (! is_array($incident) || ($incident['status'] ?? null) !== 'anomaly') {
            Cache::put($cacheKey, ['status' => 'normal'], now()->addDays(7));

            return;
        }

        $recoveredAt = now();
        $durationSeconds = null;
        if (! empty($incident['started_at'])) {
            $durationSeconds = CarbonImmutable::parse($incident['started_at'])->diffInSeconds($recoveredAt);
        }

        if (Schema::hasTable('commerce_payment_health_incidents') && ! empty($incident['started_at'])) {
            DB::table('commerce_payment_health_incidents')->insert([
                'app_id' => $appId,
                'started_at' => CarbonImmutable::parse($incident['started_at']),
                'recovered_at' => $recoveredAt,
                'duration_seconds' => $durationSeconds,
                'peak_at_risk_volume' => round((float) ($incident['peak_at_risk_volume'] ?? 0), 2),
                'peak_pending_orders' => (int) ($incident['peak_pending_orders'] ?? 0),
                'peak_critical_orders' => (int) ($incident['peak_critical_orders'] ?? 0),
                'peak_provider_pending_payments' => (int) ($incident['peak_provider_pending_payments'] ?? 0),
                'recovered_paid_orders' => (int) ($incident['recovered_paid_orders'] ?? 0),
                'recovered_fulfillments' => (int) ($incident['recovered_fulfillments'] ?? 0),
                'recovered_delivery_retries' => (int) ($incident['recovered_delivery_retries'] ?? 0),
                'recovered_gmv' => round((float) ($incident['recovered_gmv'] ?? 0), 2),
                'last_recovery_at' => ! empty($incident['last_recovery_at']) ? CarbonImmutable::parse($incident['last_recovery_at']) : null,
                'closing_pending_orders' => (int) ($current['pending_orders'] ?? 0),
                'closing_critical_orders' => (int) ($current['critical_orders'] ?? 0),
                'closing_at_risk_volume' => round((float) ($current['at_risk_volume'] ?? 0), 2),
                'signals' => json_encode($incident['signals'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => $recoveredAt,
                'updated_at' => $recoveredAt,
            ]);
        }

        Log::info('commerce.payment_health.anomaly_recovered', [
            'app_id' => $appId,
            'incident_started_at' => $incident['started_at'] ?? null,
            'incident_recovered_at' => $recoveredAt->toIso8601String(),
            'duration_seconds' => $durationSeconds,
            'peak_at_risk_volume' => round((float) ($incident['peak_at_risk_volume'] ?? 0), 2),
            'peak_pending_orders' => (int) ($incident['peak_pending_orders'] ?? 0),
            'peak_critical_orders' => (int) ($incident['peak_critical_orders'] ?? 0),
            'peak_provider_pending_payments' => (int) ($incident['peak_provider_pending_payments'] ?? 0),
            'recovered_paid_orders' => (int) ($incident['recovered_paid_orders'] ?? 0),
            'recovered_fulfillments' => (int) ($incident['recovered_fulfillments'] ?? 0),
            'recovered_delivery_retries' => (int) ($incident['recovered_delivery_retries'] ?? 0),
            'recovered_gmv' => round((float) ($incident['recovered_gmv'] ?? 0), 2),
            'current_pending_orders' => (int) ($current['pending_orders'] ?? 0),
            'current_critical_orders' => (int) ($current['critical_orders'] ?? 0),
            'current_at_risk_volume' => round((float) ($current['at_risk_volume'] ?? 0), 2),
            'baseline_samples' => (int) ($trend['samples'] ?? 0),
        ]);

        Cache::put($cacheKey, [
            'status' => 'normal',
            'recovered_at' => $recoveredAt->toIso8601String(),
        ], now()->addDays(7));
    }

    private function emptyTrend(string $status, int $samples = 0): array
    {
        return [
            'status' => $status,
            'is_anomaly' => false,
            'samples' => $samples,
            'window_hours' => 24,
            'baseline_critical_rate' => null,
            'baseline_at_risk_volume' => null,
            'baseline_pending_orders' => null,
            'critical_rate_multiplier' => null,
            'at_risk_volume_multiplier' => null,
            'signals' => [],
        ];
    }
}
