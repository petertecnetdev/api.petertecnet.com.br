<?php

namespace App\Domain\Analytics\Services;

final class ProfitabilityRiskPrioritizer
{
    /**
     * Convert payment-method economics into an actionable queue ordered by realized margin loss.
     *
     * Recovery economics are only evaluated when a real marginal attempt cost is configured.
     * This keeps the default behavior conservative and avoids inventing operational costs.
     *
     * @param array<int, array<string, mixed>> $paymentMethods
     * @param array<string, mixed> $recoveryAttemptCostByPaymentMethod
     * @return array<int, array<string, mixed>>
     */
    public function prioritize(
        array $paymentMethods,
        array $recoveryAttemptCostByPaymentMethod = [],
        ?float $fallbackRecoveryAttemptCost = null,
    ): array {
        $hasConfig = function_exists('app') && app()->bound('config');
        if ($recoveryAttemptCostByPaymentMethod === [] && $hasConfig) {
            $recoveryAttemptCostByPaymentMethod = (array) config('checkout_recovery.attempt_cost_by_payment_method', []);
        }
        if ($fallbackRecoveryAttemptCost === null && $hasConfig) {
            $configuredDefaultCost = config('checkout_recovery.default_attempt_cost');
            $fallbackRecoveryAttemptCost = is_numeric($configuredDefaultCost)
                ? max((float) $configuredDefaultCost, 0.0)
                : null;
        } elseif ($fallbackRecoveryAttemptCost !== null) {
            $fallbackRecoveryAttemptCost = max($fallbackRecoveryAttemptCost, 0.0);
        }

        return collect($paymentMethods)
            ->map(function (array $method) use ($recoveryAttemptCostByPaymentMethod, $fallbackRecoveryAttemptCost): array {
                $paymentMethod = (string) ($method['payment_method'] ?? 'unknown');
                $hasMethodCost = array_key_exists($paymentMethod, $recoveryAttemptCostByPaymentMethod)
                    && is_numeric($recoveryAttemptCostByPaymentMethod[$paymentMethod]);
                $attemptCost = $hasMethodCost
                    ? max((float) $recoveryAttemptCostByPaymentMethod[$paymentMethod], 0.0)
                    : $fallbackRecoveryAttemptCost;
                $attempts = (int) ($method['checkout_recovery_attempts'] ?? 0);
                $recoveredOrders = (int) ($method['checkout_recovered_orders'] ?? 0);
                $recoveredPlatformRevenue = (float) ($method['recovered_platform_revenue'] ?? 0);
                $platformRevenue = (float) ($method['platform_revenue'] ?? 0);
                $platformContribution = (float) ($method['platform_contribution_after_processing'] ?? 0);
                $contributionRate = $platformRevenue != 0.0
                    ? min($platformContribution / $platformRevenue, 1.0)
                    : null;
                $estimatedRecoveredContribution = $contributionRate !== null
                    ? $recoveredPlatformRevenue * $contributionRate
                    : $recoveredPlatformRevenue;
                $totalRecoveryCost = $attemptCost !== null ? $attempts * $attemptCost : null;
                $recoveryNetContribution = $totalRecoveryCost !== null
                    ? $estimatedRecoveredContribution - $totalRecoveryCost
                    : null;
                $recoveryNetShortfall = $recoveryNetContribution !== null && $recoveryNetContribution < 0
                    ? abs($recoveryNetContribution)
                    : 0.0;
                $recoveryRoi = $totalRecoveryCost !== null && $totalRecoveryCost > 0
                    ? ($recoveryNetContribution / $totalRecoveryCost) * 100
                    : null;
                $recoveryContributionPerAttempt = $attempts > 0
                    ? $estimatedRecoveredContribution / $attempts
                    : 0.0;
                $recoveryConversionRate = $attempts > 0
                    ? ($recoveredOrders / $attempts) * 100
                    : 0.0;
                $recoveryContributionPerRecoveredOrder = $recoveredOrders > 0
                    ? $estimatedRecoveredContribution / $recoveredOrders
                    : null;
                $breakEvenConversionRate = $attemptCost !== null
                    && $attemptCost > 0
                    && $recoveryContributionPerRecoveredOrder !== null
                    && $recoveryContributionPerRecoveredOrder > 0
                        ? min(($attemptCost / $recoveryContributionPerRecoveredOrder) * 100, 100.0)
                        : null;
                $conversionSafetyMargin = $breakEvenConversionRate !== null
                    ? $recoveryConversionRate - $breakEvenConversionRate
                    : null;
                $conversionConfidenceLowerBound = $this->wilsonLowerBound($recoveredOrders, $attempts) * 100;
                $confidenceMarginToBreakEven = $breakEvenConversionRate !== null
                    ? $conversionConfidenceLowerBound - $breakEvenConversionRate
                    : null;

                $minimumDecisionSample = 10;
                $minimumScaleSafetyMargin = 10.0;
                $recoveryDecision = 'insufficient_economic_data';
                if ($attemptCost !== null && $attempts >= $minimumDecisionSample && $recoveryRoi !== null) {
                    $recoveryDecision = match (true) {
                        $recoveryRoi < 0 => 'reduce_or_pause',
                        $recoveryRoi >= 100
                            && $conversionSafetyMargin !== null
                            && $conversionSafetyMargin >= $minimumScaleSafetyMargin
                            && $confidenceMarginToBreakEven !== null
                            && $confidenceMarginToBreakEven > 0 => 'scale_carefully',
                        default => 'maintain_and_monitor',
                    };
                } elseif ($attemptCost !== null) {
                    $recoveryDecision = 'collect_more_data';
                }

                return [
                    'payment_method' => $paymentMethod,
                    'platform_contribution_shortfall' => round((float) ($method['platform_contribution_shortfall'] ?? 0), 2),
                    'platform_loss_making_orders' => (int) ($method['platform_loss_making_orders'] ?? 0),
                    'platform_loss_making_gross_revenue' => round((float) ($method['platform_loss_making_gross_revenue'] ?? 0), 2),
                    'platform_collection_effective_fee_rate' => round((float) ($method['platform_collection_effective_fee_rate'] ?? 0), 2),
                    'platform_collection_break_even_fee_rate' => round((float) ($method['platform_collection_break_even_fee_rate'] ?? 0), 2),
                    'platform_collection_fee_rate_gap_to_break_even' => round((float) ($method['platform_collection_fee_rate_gap_to_break_even'] ?? 0), 2),
                    'gross_at_risk' => round((float) ($method['gross_at_risk'] ?? 0), 2),
                    'checkout_recovery_attempts' => $attempts,
                    'checkout_recovered_orders' => $recoveredOrders,
                    'checkout_recovery_conversion_rate' => round($recoveryConversionRate, 2),
                    'checkout_recovery_conversion_confidence_lower_bound' => round($conversionConfidenceLowerBound, 2),
                    'checkout_recovery_break_even_conversion_rate' => $breakEvenConversionRate !== null ? round($breakEvenConversionRate, 2) : null,
                    'checkout_recovery_conversion_safety_margin' => $conversionSafetyMargin !== null ? round($conversionSafetyMargin, 2) : null,
                    'checkout_recovery_confidence_margin_to_break_even' => $confidenceMarginToBreakEven !== null ? round($confidenceMarginToBreakEven, 2) : null,
                    'recovered_platform_revenue' => round($recoveredPlatformRevenue, 2),
                    'estimated_recovered_platform_contribution' => round($estimatedRecoveredContribution, 2),
                    'recovery_contribution_per_attempt' => round($recoveryContributionPerAttempt, 2),
                    'recovery_contribution_per_recovered_order' => $recoveryContributionPerRecoveredOrder !== null ? round($recoveryContributionPerRecoveredOrder, 2) : null,
                    'recovery_attempt_cost' => $attemptCost !== null ? round($attemptCost, 4) : null,
                    'recovery_cost_source' => $hasMethodCost
                        ? 'payment_method_config'
                        : ($fallbackRecoveryAttemptCost !== null ? 'default_config' : 'not_configured'),
                    'recovery_total_attempt_cost' => $totalRecoveryCost !== null ? round($totalRecoveryCost, 2) : null,
                    'recovery_net_platform_contribution' => $recoveryNetContribution !== null ? round($recoveryNetContribution, 2) : null,
                    'recovery_net_shortfall' => round($recoveryNetShortfall, 2),
                    'recovery_roi_percent' => $recoveryRoi !== null ? round($recoveryRoi, 2) : null,
                    'recovery_economically_sustainable' => $recoveryNetContribution !== null
                        ? $recoveryNetContribution >= 0
                        : null,
                    'recovery_decision' => $recoveryDecision,
                    'recovery_decision_minimum_attempts' => $minimumDecisionSample,
                    'recovery_scale_minimum_conversion_safety_margin' => $minimumScaleSafetyMargin,
                    'platform_collection_sustainable' => (bool) ($method['platform_collection_sustainable'] ?? true),
                ];
            })
            ->filter(static fn (array $method): bool =>
                (float) $method['platform_contribution_shortfall'] > 0
                || ! $method['platform_collection_sustainable']
                || (float) $method['recovery_net_shortfall'] > 0
                || $method['recovery_decision'] === 'scale_carefully')
            ->sort(function (array $left, array $right): int {
                $decisionPriority = [
                    'reduce_or_pause' => 4,
                    'scale_carefully' => 3,
                    'maintain_and_monitor' => 2,
                    'collect_more_data' => 1,
                    'insufficient_economic_data' => 0,
                ];

                return ($decisionPriority[$right['recovery_decision']] ?? 0) <=> ($decisionPriority[$left['recovery_decision']] ?? 0)
                    ?: $right['platform_contribution_shortfall'] <=> $left['platform_contribution_shortfall']
                    ?: $right['recovery_net_shortfall'] <=> $left['recovery_net_shortfall']
                    ?: $right['platform_loss_making_gross_revenue'] <=> $left['platform_loss_making_gross_revenue']
                    ?: $right['platform_collection_fee_rate_gap_to_break_even'] <=> $left['platform_collection_fee_rate_gap_to_break_even'];
            })
            ->values()
            ->all();
    }

    private function wilsonLowerBound(int $successes, int $trials): float
    {
        if ($trials <= 0) {
            return 0.0;
        }

        $successes = max(0, min($successes, $trials));
        $proportion = $successes / $trials;
        $z = 1.96;
        $zSquared = $z * $z;
        $denominator = 1 + ($zSquared / $trials);
        $centre = $proportion + ($zSquared / (2 * $trials));
        $adjustment = $z * sqrt((($proportion * (1 - $proportion)) + ($zSquared / (4 * $trials))) / $trials);

        return max(0.0, ($centre - $adjustment) / $denominator);
    }
}
