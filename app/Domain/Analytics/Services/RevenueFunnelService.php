<?php

namespace App\Domain\Analytics\Services;

use App\Models\CommerceOrder;
use App\Models\Establishment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

final class RevenueFunnelService
{
    /** @return array<string, int|float> */
    public function metrics(int $appId, int $organizationId, ?User $user, int $days): array
    {
        $organization = Establishment::query()
            ->whereKey($organizationId)
            ->where('app_id', $appId)
            ->firstOrFail();

        if (! $user || (! $user->hasProfile('Administrador') && (int) $organization->user_id !== (int) $user->id)) {
            throw new AuthorizationException('Sem permissão para acessar as métricas desta organização.');
        }

        $days = min(max($days, 1), 365);
        $orders = CommerceOrder::query()
            ->where('app_id', $appId)
            ->where('production_id', $organizationId)
            ->where('created_at', '>=', now()->subDays($days));

        $created = (clone $orders)->count();
        $paid = (clone $orders)->where('status', 'paid')->count();
        $pending = (clone $orders)->where('status', 'pending')->count();
        $cancelled = (clone $orders)->where('status', 'cancelled')->count();
        $expired = (clone $orders)->where('status', 'pending')->where('expires_at', '<', now())->count();
        $paidOrders = (clone $orders)->where('status', 'paid');
        $gross = (float) (clone $paidOrders)->sum('total');
        $platformRevenue = (float) (clone $paidOrders)->sum('platform_fee');
        $processorFees = (float) (clone $paidOrders)->sum('processor_fee');
        $discounts = (float) (clone $paidOrders)->sum('discount_amount');
        $producerNet = (float) (clone $paidOrders)->sum('producer_net');

        $recoveryAttempts = (clone $orders)->whereNotNull('recovery_started_at')->count();
        $recoveredOrders = (clone $orders)
            ->whereNotNull('recovery_started_at')
            ->where('status', 'paid')
            ->count();
        $recoveredPaidOrders = (clone $paidOrders)->whereNotNull('recovery_started_at');
        $recoveredGross = (float) (clone $recoveredPaidOrders)->sum('total');
        $recoveredPlatformRevenue = (float) (clone $recoveredPaidOrders)->sum('platform_fee');

        return [
            'period_days' => $days,
            'orders_created' => $created,
            'orders_paid' => $paid,
            'orders_pending' => $pending,
            'orders_cancelled' => $cancelled,
            'orders_expired_unpaid' => $expired,
            'checkout_conversion_rate' => $created > 0 ? round(($paid / $created) * 100, 2) : 0.0,
            'abandonment_rate' => $created > 0 ? round((($cancelled + $expired) / $created) * 100, 2) : 0.0,
            'gross_revenue' => round($gross, 2),
            'platform_revenue' => round($platformRevenue, 2),
            'processor_fees' => round($processorFees, 2),
            'discounts' => round($discounts, 2),
            'producer_net' => round($producerNet, 2),
            'average_paid_order' => $paid > 0 ? round($gross / $paid, 2) : 0.0,
            'checkout_recovery_attempts' => $recoveryAttempts,
            'checkout_recovered_orders' => $recoveredOrders,
            'checkout_recovery_conversion_rate' => $recoveryAttempts > 0 ? round(($recoveredOrders / $recoveryAttempts) * 100, 2) : 0.0,
            'recovered_gross_revenue' => round($recoveredGross, 2),
            'recovered_platform_revenue' => round($recoveredPlatformRevenue, 2),
        ];
    }
}
