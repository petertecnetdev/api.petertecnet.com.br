<?php

namespace App\Domain\Commerce\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

final class PaymentPendingHealthService
{
    public function forApplication(int $appId, ?CarbonInterface $now = null): array
    {
        $now ??= now();
        $attentionCutoff = $now->copy()->subMinutes(15);
        $criticalCutoff = $now->copy()->subHour();

        $pendingOrders = DB::table('commerce_orders')
            ->where('app_id', $appId)
            ->whereIn('status', ['pending', 'processing']);

        $attention = (clone $pendingOrders)->where('created_at', '<=', $attentionCutoff);
        $critical = (clone $pendingOrders)->where('created_at', '<=', $criticalCutoff);
        $expired = (clone $pendingOrders)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $now);

        $pendingProviderPayments = DB::table('commerce_payments')
            ->where('app_id', $appId)
            ->whereIn('status', ['pending', 'waiting', 'processing']);

        $pendingCount = (clone $pendingOrders)->count();
        $attentionCount = (clone $attention)->count();
        $criticalCount = (clone $critical)->count();
        $pendingVolume = $this->money((clone $pendingOrders)->sum('total'));
        $attentionVolume = $this->money((clone $attention)->sum('total'));
        $criticalVolume = $this->money((clone $critical)->sum('total'));
        $expiredCount = (clone $expired)->count();
        $expiredVolume = $this->money((clone $expired)->sum('total'));
        $providerPendingCount = (clone $pendingProviderPayments)->count();
        $providerPendingVolume = $this->money((clone $pendingProviderPayments)->sum('amount'));
        $oldestPendingAt = (clone $pendingOrders)->min('created_at');
        $oldestPendingAgeMinutes = $oldestPendingAt
            ? max(0, (int) $now->diffInMinutes($oldestPendingAt, absolute: true))
            : null;

        $risk = $this->classifyRisk(
            criticalCount: $criticalCount,
            criticalVolume: $criticalVolume,
            expiredCount: $expiredCount,
            expiredVolume: $expiredVolume,
            providerPendingCount: $providerPendingCount,
            oldestPendingAgeMinutes: $oldestPendingAgeMinutes,
        );

        return [
            'pending_orders' => $pendingCount,
            'pending_volume' => $pendingVolume,
            'attention_after_minutes' => 15,
            'attention_orders' => $attentionCount,
            'attention_volume' => $attentionVolume,
            'attention_rate' => $pendingCount > 0 ? round($attentionCount / $pendingCount, 4) : 0.0,
            'critical_after_minutes' => 60,
            'critical_orders' => $criticalCount,
            'critical_volume' => $criticalVolume,
            'critical_rate' => $pendingCount > 0 ? round($criticalCount / $pendingCount, 4) : 0.0,
            'expired_orders' => $expiredCount,
            'expired_volume' => $expiredVolume,
            'provider_pending_payments' => $providerPendingCount,
            'provider_pending_volume' => $providerPendingVolume,
            'oldest_pending_at' => $oldestPendingAt,
            'oldest_pending_age_minutes' => $oldestPendingAgeMinutes,
            'risk_level' => $risk['level'],
            'requires_attention' => $risk['level'] !== 'healthy',
            'risk_reasons' => $risk['reasons'],
            'at_risk_volume' => max($criticalVolume, $expiredVolume),
            'measured_at' => $now->toIso8601String(),
        ];
    }

    private function classifyRisk(
        int $criticalCount,
        float $criticalVolume,
        int $expiredCount,
        float $expiredVolume,
        int $providerPendingCount,
        ?int $oldestPendingAgeMinutes,
    ): array {
        $reasons = [];
        $level = 'healthy';

        if ($criticalCount > 0) {
            $level = 'attention';
            $reasons[] = 'orders_pending_over_60_minutes';
        }

        if ($providerPendingCount > 0) {
            $level = $level === 'healthy' ? 'attention' : $level;
            $reasons[] = 'provider_payments_still_pending';
        }

        if ($expiredCount > 0) {
            $level = 'critical';
            $reasons[] = 'expired_orders_still_pending';
        }

        if ($criticalCount >= 3 || $criticalVolume >= 500.0) {
            $level = 'critical';
            $reasons[] = 'material_pending_backlog';
        }

        if (($oldestPendingAgeMinutes ?? 0) >= 180) {
            $level = 'critical';
            $reasons[] = 'very_old_pending_order';
        }

        if ($expiredVolume >= 500.0) {
            $level = 'critical';
            $reasons[] = 'material_expired_pending_volume';
        }

        return [
            'level' => $level,
            'reasons' => array_values(array_unique($reasons)),
        ];
    }

    private function money(mixed $value): float
    {
        return round((float) $value, 2);
    }
}
