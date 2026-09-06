<?php

namespace App\Domain\Analytics\Services;

use App\Models\CommerceOrder;
use App\Models\Establishment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

final class RevenueFunnelService
{
    /** @return array<string, mixed> */
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

        $atRiskOrders = (clone $orders)
            ->where('status', 'pending')
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>=', now());
            });
        $atRiskGross = (float) (clone $atRiskOrders)->sum('total');
        $atRiskPlatformRevenue = (float) (clone $atRiskOrders)->sum('platform_fee');

        $lostOrders = (clone $orders)->where(function ($query): void {
            $query->where('status', 'cancelled')
                ->orWhere(function ($expiredQuery): void {
                    $expiredQuery->where('status', 'pending')->where('expires_at', '<', now());
                });
        });
        $lostGross = (float) (clone $lostOrders)->sum('total');
        $lostPlatformRevenue = (float) (clone $lostOrders)->sum('platform_fee');

        $paymentMethods = (clone $orders)
            ->selectRaw("COALESCE(NULLIF(payment_method, ''), 'unknown') payment_method")
            ->selectRaw('COUNT(*) orders_created')
            ->selectRaw("SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) orders_paid")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'paid' THEN total ELSE 0 END), 0) gross_revenue")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'paid' THEN platform_fee ELSE 0 END), 0) platform_revenue")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'pending' AND (expires_at IS NULL OR expires_at >= ?) THEN total ELSE 0 END), 0) gross_at_risk", [now()])
            ->groupBy('payment_method')
            ->get()
            ->map(function ($row): array {
                $createdForMethod = (int) $row->orders_created;
                $paidForMethod = (int) $row->orders_paid;

                return [
                    'payment_method' => (string) $row->payment_method,
                    'orders_created' => $createdForMethod,
                    'orders_paid' => $paidForMethod,
                    'conversion_rate' => $createdForMethod > 0 ? round(($paidForMethod / $createdForMethod) * 100, 2) : 0.0,
                    'gross_revenue' => round((float) $row->gross_revenue, 2),
                    'platform_revenue' => round((float) $row->platform_revenue, 2),
                    'gross_at_risk' => round((float) $row->gross_at_risk, 2),
                ];
            })
            ->sortByDesc('gross_revenue')
            ->values()
            ->all();

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
            'gross_revenue_at_risk' => round($atRiskGross, 2),
            'platform_revenue_at_risk' => round($atRiskPlatformRevenue, 2),
            'gross_revenue_lost_to_abandonment' => round($lostGross, 2),
            'platform_revenue_lost_to_abandonment' => round($lostPlatformRevenue, 2),
            'payment_methods' => $paymentMethods,
        ];
    }
}
