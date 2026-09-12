<?php

namespace App\Domain\Commerce\Services;

use Illuminate\Support\Facades\DB;
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
                'measured_at' => $current['measured_at'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

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
