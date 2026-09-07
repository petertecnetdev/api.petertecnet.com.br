<?php

namespace App\Domain\Analytics\Services;

use App\Models\CommerceOrder;
use App\Models\Establishment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;

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
        $paidProfitability = $this->settlementProfitability((clone $paidOrders)->get([
            'payment_method', 'total', 'platform_fee', 'processor_fee', 'producer_net', 'metadata',
        ]));

        $recoveryAttempts = (clone $orders)->whereNotNull('recovery_started_at')->count();
        $recoveredOrders = (clone $orders)
            ->whereNotNull('recovery_started_at')
            ->where('status', 'paid')
            ->count();
        $recoveredPaidOrders = (clone $paidOrders)->whereNotNull('recovery_started_at');
        $recoveredGross = (float) (clone $recoveredPaidOrders)->sum('total');
        $recoveredPlatformRevenue = (float) (clone $recoveredPaidOrders)->sum('platform_fee');
        $recoveredProcessorFees = (float) (clone $recoveredPaidOrders)->sum('processor_fee');
        $recoveredProfitability = $this->settlementProfitability((clone $recoveredPaidOrders)->get([
            'payment_method', 'total', 'platform_fee', 'processor_fee', 'producer_net', 'metadata',
        ]));

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

        $normalizedPaymentMethodSql = "COALESCE(NULLIF(payment_method, ''), 'unknown')";
        $profitabilityByPaymentMethod = $paidProfitability['by_payment_method'];
        $paymentMethods = (clone $orders)
            ->selectRaw("{$normalizedPaymentMethodSql} payment_method")
            ->selectRaw('COUNT(*) orders_created')
            ->selectRaw("SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) orders_paid")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'paid' THEN total ELSE 0 END), 0) gross_revenue")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'paid' THEN platform_fee ELSE 0 END), 0) platform_revenue")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'paid' THEN processor_fee ELSE 0 END), 0) processor_fees")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'paid' THEN producer_net ELSE 0 END), 0) organization_net_before_processing")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'pending' AND (expires_at IS NULL OR expires_at >= ?) THEN total ELSE 0 END), 0) gross_at_risk", [now()])
            ->groupByRaw($normalizedPaymentMethodSql)
            ->get()
            ->map(function ($row) use ($profitabilityByPaymentMethod): array {
                $createdForMethod = (int) $row->orders_created;
                $paidForMethod = (int) $row->orders_paid;
                $grossForMethod = (float) $row->gross_revenue;
                $processorFeesForMethod = (float) $row->processor_fees;
                $method = (string) $row->payment_method;
                $profitability = $profitabilityByPaymentMethod[$method] ?? $this->emptyProfitability();

                return [
                    'payment_method' => $method,
                    'orders_created' => $createdForMethod,
                    'orders_paid' => $paidForMethod,
                    'conversion_rate' => $createdForMethod > 0 ? round(($paidForMethod / $createdForMethod) * 100, 2) : 0.0,
                    'gross_revenue' => round($grossForMethod, 2),
                    'platform_revenue' => round((float) $row->platform_revenue, 2),
                    'processor_fees' => round($processorFeesForMethod, 2),
                    'processor_fee_rate' => $grossForMethod > 0 ? round(($processorFeesForMethod / $grossForMethod) * 100, 2) : 0.0,
                    'processor_fees_borne_by_platform' => $profitability['processor_fees_borne_by_platform'],
                    'processor_fees_borne_by_organization' => $profitability['processor_fees_borne_by_organization'],
                    'organization_net_after_processing' => $profitability['organization_net_after_processing'],
                    'organization_net_margin' => $profitability['organization_net_margin'],
                    'platform_contribution_after_processing' => $profitability['platform_contribution_after_processing'],
                    'platform_contribution_margin' => $profitability['platform_contribution_margin'],
                    'settlement_modes' => $profitability['settlement_modes'],
                    'gross_at_risk' => round((float) $row->gross_at_risk, 2),
                ];
            })
            ->sortByDesc('platform_contribution_after_processing')
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
            'processor_fee_rate' => $gross > 0 ? round(($processorFees / $gross) * 100, 2) : 0.0,
            'processor_fees_borne_by_platform' => $paidProfitability['processor_fees_borne_by_platform'],
            'processor_fees_borne_by_organization' => $paidProfitability['processor_fees_borne_by_organization'],
            'discounts' => round($discounts, 2),
            'producer_net' => round($producerNet, 2),
            'organization_net_after_processing' => $paidProfitability['organization_net_after_processing'],
            'organization_net_margin' => $paidProfitability['organization_net_margin'],
            'platform_contribution_after_processing' => $paidProfitability['platform_contribution_after_processing'],
            'platform_contribution_margin' => $paidProfitability['platform_contribution_margin'],
            'settlement_modes' => $paidProfitability['settlement_modes'],
            'average_paid_order' => $paid > 0 ? round($gross / $paid, 2) : 0.0,
            'checkout_recovery_attempts' => $recoveryAttempts,
            'checkout_recovered_orders' => $recoveredOrders,
            'checkout_recovery_conversion_rate' => $recoveryAttempts > 0 ? round(($recoveredOrders / $recoveryAttempts) * 100, 2) : 0.0,
            'recovered_gross_revenue' => round($recoveredGross, 2),
            'recovered_platform_revenue' => round($recoveredPlatformRevenue, 2),
            'recovered_processor_fees' => round($recoveredProcessorFees, 2),
            'recovered_processor_fees_borne_by_platform' => $recoveredProfitability['processor_fees_borne_by_platform'],
            'recovered_processor_fees_borne_by_organization' => $recoveredProfitability['processor_fees_borne_by_organization'],
            'recovered_organization_net_after_processing' => $recoveredProfitability['organization_net_after_processing'],
            'recovered_organization_net_margin' => $recoveredProfitability['organization_net_margin'],
            'recovered_platform_contribution_after_processing' => $recoveredProfitability['platform_contribution_after_processing'],
            'recovered_platform_contribution_margin' => $recoveredProfitability['platform_contribution_margin'],
            'gross_revenue_at_risk' => round($atRiskGross, 2),
            'platform_revenue_at_risk' => round($atRiskPlatformRevenue, 2),
            'gross_revenue_lost_to_abandonment' => round($lostGross, 2),
            'platform_revenue_lost_to_abandonment' => round($lostPlatformRevenue, 2),
            'payment_methods' => $paymentMethods,
        ];
    }

    /**
     * Attribute payment processing cost to the party that actually collects the payment.
     *
     * automatic_split: the merchant receives the payment and bears provider processing cost.
     * platform_collection: Peter Tecnet receives the payment, so provider processing cost reduces
     * the platform contribution while the organization's contractual net is preserved.
     * Unknown legacy rows are treated conservatively as merchant-borne to avoid overstating platform margin.
     *
     * @param Collection<int, CommerceOrder> $orders
     * @return array<string, mixed>
     */
    private function settlementProfitability(Collection $orders): array
    {
        $summary = $this->emptyProfitability(false);
        $byPaymentMethod = [];

        foreach ($orders as $order) {
            $gross = (float) $order->total;
            $platformFee = (float) $order->platform_fee;
            $processorFee = (float) $order->processor_fee;
            $producerNet = (float) $order->producer_net;
            $settlementMode = (string) data_get($order->metadata, 'settlement_mode', 'unknown');
            $settlementMode = in_array($settlementMode, ['automatic_split', 'platform_collection'], true)
                ? $settlementMode
                : 'unknown';
            $paymentMethod = trim((string) $order->payment_method) ?: 'unknown';

            $platformBearsProcessing = $settlementMode === 'platform_collection';
            $organizationNet = $platformBearsProcessing
                ? $producerNet
                : max(0, $producerNet - $processorFee);
            $platformContribution = $platformBearsProcessing
                ? $platformFee - $processorFee
                : $platformFee;

            $this->accumulateProfitability($summary, $gross, $processorFee, $organizationNet, $platformContribution, $settlementMode, $platformBearsProcessing);

            if (! isset($byPaymentMethod[$paymentMethod])) {
                $byPaymentMethod[$paymentMethod] = $this->emptyProfitability(false);
            }
            $this->accumulateProfitability($byPaymentMethod[$paymentMethod], $gross, $processorFee, $organizationNet, $platformContribution, $settlementMode, $platformBearsProcessing);
        }

        $summary = $this->finalizeProfitability($summary);
        $summary['by_payment_method'] = collect($byPaymentMethod)
            ->map(fn (array $row): array => $this->finalizeProfitability($row))
            ->all();

        return $summary;
    }

    /** @return array<string, mixed> */
    private function emptyProfitability(bool $finalized = true): array
    {
        $row = [
            'gross_revenue' => 0.0,
            'processor_fees_borne_by_platform' => 0.0,
            'processor_fees_borne_by_organization' => 0.0,
            'organization_net_after_processing' => 0.0,
            'organization_net_margin' => 0.0,
            'platform_contribution_after_processing' => 0.0,
            'platform_contribution_margin' => 0.0,
            'settlement_modes' => [],
        ];

        return $finalized ? $row : $row;
    }

    /** @param array<string, mixed> $row */
    private function accumulateProfitability(array &$row, float $gross, float $processorFee, float $organizationNet, float $platformContribution, string $settlementMode, bool $platformBearsProcessing): void
    {
        $row['gross_revenue'] += $gross;
        $row['organization_net_after_processing'] += $organizationNet;
        $row['platform_contribution_after_processing'] += $platformContribution;
        $row[$platformBearsProcessing ? 'processor_fees_borne_by_platform' : 'processor_fees_borne_by_organization'] += $processorFee;
        $row['settlement_modes'][$settlementMode] = ($row['settlement_modes'][$settlementMode] ?? 0) + 1;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function finalizeProfitability(array $row): array
    {
        $gross = (float) $row['gross_revenue'];
        $row['processor_fees_borne_by_platform'] = round((float) $row['processor_fees_borne_by_platform'], 2);
        $row['processor_fees_borne_by_organization'] = round((float) $row['processor_fees_borne_by_organization'], 2);
        $row['organization_net_after_processing'] = round((float) $row['organization_net_after_processing'], 2);
        $row['organization_net_margin'] = $gross > 0 ? round(((float) $row['organization_net_after_processing'] / $gross) * 100, 2) : 0.0;
        $row['platform_contribution_after_processing'] = round((float) $row['platform_contribution_after_processing'], 2);
        $row['platform_contribution_margin'] = $gross > 0 ? round(((float) $row['platform_contribution_after_processing'] / $gross) * 100, 2) : 0.0;
        ksort($row['settlement_modes']);
        unset($row['gross_revenue']);

        return $row;
    }
}
