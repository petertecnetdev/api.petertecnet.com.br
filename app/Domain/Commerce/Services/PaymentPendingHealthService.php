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

        return [
            'pending_orders' => $pendingCount,
            'pending_volume' => $this->money((clone $pendingOrders)->sum('total')),
            'attention_after_minutes' => 15,
            'attention_orders' => $attentionCount,
            'attention_volume' => $this->money((clone $attention)->sum('total')),
            'attention_rate' => $pendingCount > 0 ? round($attentionCount / $pendingCount, 4) : 0.0,
            'critical_after_minutes' => 60,
            'critical_orders' => $criticalCount,
            'critical_volume' => $this->money((clone $critical)->sum('total')),
            'critical_rate' => $pendingCount > 0 ? round($criticalCount / $pendingCount, 4) : 0.0,
            'expired_orders' => (clone $expired)->count(),
            'expired_volume' => $this->money((clone $expired)->sum('total')),
            'provider_pending_payments' => (clone $pendingProviderPayments)->count(),
            'provider_pending_volume' => $this->money((clone $pendingProviderPayments)->sum('amount')),
            'oldest_pending_at' => (clone $pendingOrders)->min('created_at'),
            'measured_at' => $now->toIso8601String(),
        ];
    }

    private function money(mixed $value): float
    {
        return round((float) $value, 2);
    }
}
