<?php

namespace App\Domain\Analytics\Services;

final class RecoveryChannelProfitabilitySelector
{
    /**
     * Rank recovery channels by conservative expected net contribution per attempt.
     *
     * Required channel keys: channel, attempt_cost, conversion_rate, contribution_per_recovered_order.
     * Optional: attempts (used for Wilson 95% lower bound when >= 1).
     *
     * @param array<int, array<string, mixed>> $channels
     * @return array<int, array<string, mixed>>
     */
    public function rank(array $channels, ?float $maxSafeAttemptCost = null): array
    {
        $maxSafeAttemptCost = $maxSafeAttemptCost !== null ? max($maxSafeAttemptCost, 0.0) : null;

        return collect($channels)
            ->map(function (array $channel) use ($maxSafeAttemptCost): array {
                $name = trim((string) ($channel['channel'] ?? '')) ?: 'unknown';
                $attemptCost = is_numeric($channel['attempt_cost'] ?? null)
                    ? max((float) $channel['attempt_cost'], 0.0)
                    : null;
                $conversionRate = is_numeric($channel['conversion_rate'] ?? null)
                    ? min(max((float) $channel['conversion_rate'], 0.0), 100.0)
                    : null;
                $contributionPerRecoveredOrder = is_numeric($channel['contribution_per_recovered_order'] ?? null)
                    ? max((float) $channel['contribution_per_recovered_order'], 0.0)
                    : null;
                $attempts = max((int) ($channel['attempts'] ?? 0), 0);

                $confidenceAdjustedConversionRate = $conversionRate;
                if ($conversionRate !== null && $attempts > 0) {
                    $successes = (int) round(($conversionRate / 100) * $attempts);
                    $confidenceAdjustedConversionRate = $this->wilsonLowerBound($successes, $attempts) * 100;
                }

                $expectedContributionPerAttempt = $confidenceAdjustedConversionRate !== null && $contributionPerRecoveredOrder !== null
                    ? ($confidenceAdjustedConversionRate / 100) * $contributionPerRecoveredOrder
                    : null;
                $expectedNetContributionPerAttempt = $expectedContributionPerAttempt !== null && $attemptCost !== null
                    ? $expectedContributionPerAttempt - $attemptCost
                    : null;
                $roiPercent = $expectedNetContributionPerAttempt !== null && $attemptCost !== null && $attemptCost > 0
                    ? ($expectedNetContributionPerAttempt / $attemptCost) * 100
                    : null;
                $withinSafeCostCeiling = $attemptCost !== null && $maxSafeAttemptCost !== null
                    ? $attemptCost <= $maxSafeAttemptCost
                    : null;
                $economicallyViable = $expectedNetContributionPerAttempt !== null
                    ? $expectedNetContributionPerAttempt > 0
                    : null;

                return [
                    'channel' => $name,
                    'attempt_cost' => $attemptCost !== null ? round($attemptCost, 4) : null,
                    'observed_conversion_rate' => $conversionRate !== null ? round($conversionRate, 2) : null,
                    'confidence_adjusted_conversion_rate' => $confidenceAdjustedConversionRate !== null ? round($confidenceAdjustedConversionRate, 2) : null,
                    'contribution_per_recovered_order' => $contributionPerRecoveredOrder !== null ? round($contributionPerRecoveredOrder, 2) : null,
                    'expected_contribution_per_attempt' => $expectedContributionPerAttempt !== null ? round($expectedContributionPerAttempt, 4) : null,
                    'expected_net_contribution_per_attempt' => $expectedNetContributionPerAttempt !== null ? round($expectedNetContributionPerAttempt, 4) : null,
                    'confidence_adjusted_roi_percent' => $roiPercent !== null ? round($roiPercent, 2) : null,
                    'within_safe_cost_ceiling' => $withinSafeCostCeiling,
                    'economically_viable' => $economicallyViable,
                    'recommended' => $economicallyViable === true && $withinSafeCostCeiling !== false,
                    'sample_attempts' => $attempts,
                ];
            })
            ->sort(function (array $left, array $right): int {
                $recommended = (int) $right['recommended'] <=> (int) $left['recommended'];
                if ($recommended !== 0) {
                    return $recommended;
                }

                return ($right['expected_net_contribution_per_attempt'] ?? -INF)
                    <=> ($left['expected_net_contribution_per_attempt'] ?? -INF);
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
