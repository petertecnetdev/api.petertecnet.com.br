<?php

namespace App\Domain\Analytics\Services;

final class ProfitabilityRiskPrioritizer
{
    /**
     * Convert payment-method economics into an actionable queue ordered by realized margin loss.
     *
     * @param array<int, array<string, mixed>> $paymentMethods
     * @return array<int, array<string, mixed>>
     */
    public function prioritize(array $paymentMethods): array
    {
        return collect($paymentMethods)
            ->filter(static fn (array $method): bool =>
                (float) ($method['platform_contribution_shortfall'] ?? 0) > 0
                || ! (bool) ($method['platform_collection_sustainable'] ?? true))
            ->map(static fn (array $method): array => [
                'payment_method' => (string) ($method['payment_method'] ?? 'unknown'),
                'platform_contribution_shortfall' => round((float) ($method['platform_contribution_shortfall'] ?? 0), 2),
                'platform_loss_making_orders' => (int) ($method['platform_loss_making_orders'] ?? 0),
                'platform_loss_making_gross_revenue' => round((float) ($method['platform_loss_making_gross_revenue'] ?? 0), 2),
                'platform_collection_effective_fee_rate' => round((float) ($method['platform_collection_effective_fee_rate'] ?? 0), 2),
                'platform_collection_break_even_fee_rate' => round((float) ($method['platform_collection_break_even_fee_rate'] ?? 0), 2),
                'platform_collection_fee_rate_gap_to_break_even' => round((float) ($method['platform_collection_fee_rate_gap_to_break_even'] ?? 0), 2),
                'gross_at_risk' => round((float) ($method['gross_at_risk'] ?? 0), 2),
            ])
            ->sort(function (array $left, array $right): int {
                return $right['platform_contribution_shortfall'] <=> $left['platform_contribution_shortfall']
                    ?: $right['platform_loss_making_gross_revenue'] <=> $left['platform_loss_making_gross_revenue']
                    ?: $right['platform_collection_fee_rate_gap_to_break_even'] <=> $left['platform_collection_fee_rate_gap_to_break_even'];
            })
            ->values()
            ->all();
    }
}
