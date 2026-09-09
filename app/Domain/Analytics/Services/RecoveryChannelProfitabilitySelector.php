<?php

namespace App\Domain\Analytics\Services;

final class RecoveryChannelProfitabilitySelector
{
    public const DEFAULT_MIN_SAMPLE_ATTEMPTS = 30;

    public const DEFAULT_MAX_SAFE_COST_SHARE = 0.80;

    /**
     * Rank recovery channels by conservative expected net contribution per attempt.
     *
     * Required channel keys: channel, attempt_cost, conversion_rate, contribution_per_recovered_order.
     * Optional: attempts, max_safe_attempt_cost, derive_safe_attempt_cost_ceiling and max_safe_cost_share.
     *
     * A channel is only recommended after reaching the minimum sample size. Paid channels require
     * a safe attempt-cost ceiling. The ceiling may be explicitly configured or, when the caller
     * opts in, derived from observed economics using the Wilson 95% lower conversion bound. Derived
     * ceilings reserve part of conservative contribution as margin instead of allowing channel cost
     * to consume all expected contribution. Explicit and derived ceilings are combined by choosing
     * the stricter value. Zero-cost channels remain eligible without an external ceiling.
     *
     * @param array<int, array<string, mixed>> $channels
     * @return array<int, array<string, mixed>>
     */
    public function rank(
        array $channels,
        ?float $maxSafeAttemptCost = null,
        int $minSampleAttempts = self::DEFAULT_MIN_SAMPLE_ATTEMPTS,
    ): array {
        $maxSafeAttemptCost = $maxSafeAttemptCost !== null ? max($maxSafeAttemptCost, 0.0) : null;
        $minSampleAttempts = max($minSampleAttempts, 1);

        return collect($channels)
            ->map(function (array $channel) use ($maxSafeAttemptCost, $minSampleAttempts): array {
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
                $hasMinimumSample = $attempts >= $minSampleAttempts;
                $channelConfiguredCeiling = is_numeric($channel['max_safe_attempt_cost'] ?? null)
                    ? max((float) $channel['max_safe_attempt_cost'], 0.0)
                    : null;
                $configuredCeiling = $channelConfiguredCeiling ?? $maxSafeAttemptCost;
                $deriveSafeCeiling = ($channel['derive_safe_attempt_cost_ceiling'] ?? false) === true;
                $maxSafeCostShare = is_numeric($channel['max_safe_cost_share'] ?? null)
                    ? min(max((float) $channel['max_safe_cost_share'], 0.0), 1.0)
                    : self::DEFAULT_MAX_SAFE_COST_SHARE;

                $confidenceAdjustedConversionRate = $conversionRate;
                if ($conversionRate !== null && $attempts > 0) {
                    $successes = (int) round(($conversionRate / 100) * $attempts);
                    $confidenceAdjustedConversionRate = $this->wilsonLowerBound($successes, $attempts) * 100;
                }

                $expectedContributionPerAttempt = $confidenceAdjustedConversionRate !== null && $contributionPerRecoveredOrder !== null
                    ? ($confidenceAdjustedConversionRate / 100) * $contributionPerRecoveredOrder
                    : null;
                $derivedSafeCeiling = $deriveSafeCeiling
                    && $hasMinimumSample
                    && $attemptCost !== null
                    && $expectedContributionPerAttempt !== null
                    ? $expectedContributionPerAttempt * $maxSafeCostShare
                    : null;
                $channelMaxSafeAttemptCost = match (true) {
                    $configuredCeiling !== null && $derivedSafeCeiling !== null => min($configuredCeiling, $derivedSafeCeiling),
                    $configuredCeiling !== null => $configuredCeiling,
                    $derivedSafeCeiling !== null => $derivedSafeCeiling,
                    default => null,
                };
                $safeCeilingSource = match (true) {
                    $configuredCeiling !== null && $derivedSafeCeiling !== null => 'configured_and_observed_min',
                    $channelConfiguredCeiling !== null => 'channel_configured',
                    $maxSafeAttemptCost !== null => 'global_configured',
                    $derivedSafeCeiling !== null => 'observed_conservative',
                    default => null,
                };

                $expectedNetContributionPerAttempt = $expectedContributionPerAttempt !== null && $attemptCost !== null
                    ? $expectedContributionPerAttempt - $attemptCost
                    : null;
                $roiPercent = $expectedNetContributionPerAttempt !== null && $attemptCost !== null && $attemptCost > 0
                    ? ($expectedNetContributionPerAttempt / $attemptCost) * 100
                    : null;
                $isZeroCostChannel = $attemptCost !== null && $attemptCost === 0.0;
                $hasSafeCostCeiling = $isZeroCostChannel || $channelMaxSafeAttemptCost !== null;
                $withinSafeCostCeiling = match (true) {
                    $isZeroCostChannel => true,
                    $attemptCost !== null && $channelMaxSafeAttemptCost !== null => $attemptCost <= $channelMaxSafeAttemptCost,
                    default => null,
                };
                $economicallyViable = $expectedNetContributionPerAttempt !== null
                    ? $expectedNetContributionPerAttempt > 0
                    : null;
                $recommended = $economicallyViable === true
                    && $withinSafeCostCeiling !== false
                    && $hasSafeCostCeiling
                    && $hasMinimumSample;

                $recommendationBlockers = [];
                if (! $hasMinimumSample) {
                    $recommendationBlockers[] = 'insufficient_sample';
                }
                if ($attemptCost !== null && $attemptCost > 0 && $channelMaxSafeAttemptCost === null) {
                    $recommendationBlockers[] = 'missing_safe_cost_ceiling';
                }
                if ($withinSafeCostCeiling === false) {
                    $recommendationBlockers[] = 'above_safe_cost_ceiling';
                }
                if ($economicallyViable === false) {
                    $recommendationBlockers[] = 'non_positive_expected_net_contribution';
                }
                if ($economicallyViable === null) {
                    $recommendationBlockers[] = 'incomplete_economics';
                }

                return [
                    'channel' => $name,
                    'attempt_cost' => $attemptCost !== null ? round($attemptCost, 4) : null,
                    'observed_conversion_rate' => $conversionRate !== null ? round($conversionRate, 2) : null,
                    'confidence_adjusted_conversion_rate' => $confidenceAdjustedConversionRate !== null ? round($confidenceAdjustedConversionRate, 2) : null,
                    'contribution_per_recovered_order' => $contributionPerRecoveredOrder !== null ? round($contributionPerRecoveredOrder, 2) : null,
                    'expected_contribution_per_attempt' => $expectedContributionPerAttempt !== null ? round($expectedContributionPerAttempt, 4) : null,
                    'expected_net_contribution_per_attempt' => $expectedNetContributionPerAttempt !== null ? round($expectedNetContributionPerAttempt, 4) : null,
                    'confidence_adjusted_roi_percent' => $roiPercent !== null ? round($roiPercent, 2) : null,
                    'derived_safe_attempt_cost_ceiling' => $derivedSafeCeiling !== null ? round($derivedSafeCeiling, 4) : null,
                    'safe_attempt_cost_ceiling' => $channelMaxSafeAttemptCost !== null ? round($channelMaxSafeAttemptCost, 4) : null,
                    'safe_attempt_cost_ceiling_source' => $safeCeilingSource,
                    'max_safe_cost_share' => $deriveSafeCeiling ? round($maxSafeCostShare, 4) : null,
                    'has_safe_cost_ceiling' => $hasSafeCostCeiling,
                    'within_safe_cost_ceiling' => $withinSafeCostCeiling,
                    'economically_viable' => $economicallyViable,
                    'has_minimum_sample' => $hasMinimumSample,
                    'minimum_sample_attempts' => $minSampleAttempts,
                    'recommendation_blockers' => $recommendationBlockers,
                    'recommended' => $recommended,
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
