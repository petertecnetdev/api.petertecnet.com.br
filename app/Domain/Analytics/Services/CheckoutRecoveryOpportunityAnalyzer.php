<?php

namespace App\Domain\Analytics\Services;

use Carbon\CarbonInterface;

final class CheckoutRecoveryOpportunityAnalyzer
{
    /**
     * @param iterable<object> $orders
     * @return array<string, mixed>
     */
    public function summarize(
        iterable $orders,
        ?CarbonInterface $now = null,
        array $recoveryProbabilityByPaymentMethod = [],
        ?float $fallbackRecoveryProbability = null,
        array $recoveryProbabilityBySegment = [],
        array $platformContributionRateByPaymentMethod = [],
        ?float $fallbackPlatformContributionRate = null,
        array $recoveryAttemptCostByPaymentMethod = [],
        ?float $fallbackRecoveryAttemptCost = null
    ): array {
        $now ??= now();
        $summary = $this->emptySummary();
        $fallbackRecoveryProbability = $fallbackRecoveryProbability !== null
            ? min(max($fallbackRecoveryProbability, 0.0), 1.0)
            : null;
        $fallbackPlatformContributionRate = $fallbackPlatformContributionRate !== null
            ? min((float) $fallbackPlatformContributionRate, 1.0)
            : null;

        if ($recoveryAttemptCostByPaymentMethod === []) {
            $recoveryAttemptCostByPaymentMethod = (array) config('checkout_recovery.attempt_cost_by_payment_method', []);
        }
        if ($fallbackRecoveryAttemptCost === null) {
            $configuredDefaultCost = config('checkout_recovery.default_attempt_cost');
            $fallbackRecoveryAttemptCost = is_numeric($configuredDefaultCost)
                ? (float) $configuredDefaultCost
                : null;
        }
        $fallbackRecoveryAttemptCost = $fallbackRecoveryAttemptCost !== null
            ? max((float) $fallbackRecoveryAttemptCost, 0.0)
            : null;

        foreach ($orders as $order) {
            $paymentMethod = trim((string) ($order->payment_method ?? '')) ?: 'unknown';
            $gross = (float) ($order->total ?? 0);
            $platformRevenue = (float) ($order->platform_fee ?? 0);
            $recoveryStarted = $order->recovery_started_at !== null;
            $ageBucket = $this->ageBucket($order->created_at ?? null, $now);
            $segmentKey = $paymentMethod.'|'.$ageBucket;
            $hasSegmentProbability = array_key_exists($segmentKey, $recoveryProbabilityBySegment);
            $hasMethodProbability = array_key_exists($paymentMethod, $recoveryProbabilityByPaymentMethod);
            $methodProbability = $hasSegmentProbability
                ? min(max((float) $recoveryProbabilityBySegment[$segmentKey], 0.0), 1.0)
                : ($hasMethodProbability
                    ? min(max((float) $recoveryProbabilityByPaymentMethod[$paymentMethod], 0.0), 1.0)
                    : $fallbackRecoveryProbability);
            $expectedPlatformRevenue = $methodProbability !== null
                ? $platformRevenue * $methodProbability
                : $platformRevenue;
            $probabilitySource = $hasSegmentProbability
                ? 'payment_method_age_history'
                : ($hasMethodProbability
                    ? 'payment_method_history'
                    : ($fallbackRecoveryProbability !== null ? 'overall_history' : 'no_history'));

            $hasMethodContributionRate = array_key_exists($paymentMethod, $platformContributionRateByPaymentMethod);
            $contributionRate = $hasMethodContributionRate
                ? min((float) $platformContributionRateByPaymentMethod[$paymentMethod], 1.0)
                : $fallbackPlatformContributionRate;
            $expectedPlatformContribution = $contributionRate !== null
                ? $expectedPlatformRevenue * $contributionRate
                : $expectedPlatformRevenue;
            $contributionRateSource = $hasMethodContributionRate
                ? 'payment_method_history'
                : ($fallbackPlatformContributionRate !== null ? 'overall_history' : 'no_history');

            $hasMethodRecoveryCost = array_key_exists($paymentMethod, $recoveryAttemptCostByPaymentMethod)
                && is_numeric($recoveryAttemptCostByPaymentMethod[$paymentMethod]);
            $recoveryAttemptCost = $hasMethodRecoveryCost
                ? max((float) $recoveryAttemptCostByPaymentMethod[$paymentMethod], 0.0)
                : $fallbackRecoveryAttemptCost;
            $estimatedRecoveryCost = $recoveryAttemptCost ?? 0.0;
            $expectedNetPlatformContribution = $expectedPlatformContribution - $estimatedRecoveryCost;
            $recoveryCostSource = $hasMethodRecoveryCost
                ? 'payment_method_config'
                : ($fallbackRecoveryAttemptCost !== null ? 'default_config' : 'not_configured');
            $economicallyViable = $recoveryAttemptCost !== null
                ? $expectedNetPlatformContribution > 0.0
                : null;

            $this->accumulate(
                $summary,
                $gross,
                $platformRevenue,
                $recoveryStarted,
                $expectedPlatformRevenue,
                $expectedPlatformContribution,
                $estimatedRecoveryCost,
                $expectedNetPlatformContribution
            );

            if (! isset($summary['by_payment_method'][$paymentMethod])) {
                $summary['by_payment_method'][$paymentMethod] = $this->emptyBucket();
                $summary['by_payment_method'][$paymentMethod]['payment_method'] = $paymentMethod;
            }
            $this->accumulate(
                $summary['by_payment_method'][$paymentMethod],
                $gross,
                $platformRevenue,
                $recoveryStarted,
                $expectedPlatformRevenue,
                $expectedPlatformContribution,
                $estimatedRecoveryCost,
                $expectedNetPlatformContribution
            );

            if (! isset($summary['by_age_bucket'][$ageBucket])) {
                $summary['by_age_bucket'][$ageBucket] = $this->emptyBucket();
                $summary['by_age_bucket'][$ageBucket]['age_bucket'] = $ageBucket;
            }
            $this->accumulate(
                $summary['by_age_bucket'][$ageBucket],
                $gross,
                $platformRevenue,
                $recoveryStarted,
                $expectedPlatformRevenue,
                $expectedPlatformContribution,
                $estimatedRecoveryCost,
                $expectedNetPlatformContribution
            );

            if (! $recoveryStarted) {
                $recommendedAction = $economicallyViable === false
                    ? 'skip_negative_expected_value'
                    : 'recover_unattempted_checkout';

                $summary['top_opportunities'][] = [
                    'order_id' => isset($order->id) ? (int) $order->id : null,
                    'payment_method' => $paymentMethod,
                    'age_bucket' => $ageBucket,
                    'gross_revenue' => $gross,
                    'platform_revenue' => $platformRevenue,
                    'recovery_probability' => $methodProbability !== null ? round($methodProbability, 4) : null,
                    'recovery_probability_source' => $probabilitySource,
                    'expected_platform_revenue' => $expectedPlatformRevenue,
                    'platform_contribution_rate' => $contributionRate !== null ? round($contributionRate, 4) : null,
                    'platform_contribution_rate_source' => $contributionRateSource,
                    'expected_platform_contribution' => $expectedPlatformContribution,
                    'estimated_recovery_cost' => $recoveryAttemptCost !== null ? $estimatedRecoveryCost : null,
                    'recovery_cost_source' => $recoveryCostSource,
                    'expected_net_platform_contribution' => $expectedNetPlatformContribution,
                    'economically_viable' => $economicallyViable,
                    'created_at' => $order->created_at instanceof CarbonInterface
                        ? $order->created_at->toIso8601String()
                        : null,
                    'recommended_action' => $recommendedAction,
                ];

                $priorityKey = $paymentMethod.'|'.$ageBucket;
                if (! isset($summary['priority_queue'][$priorityKey])) {
                    $summary['priority_queue'][$priorityKey] = $this->emptyBucket();
                    $summary['priority_queue'][$priorityKey]['payment_method'] = $paymentMethod;
                    $summary['priority_queue'][$priorityKey]['age_bucket'] = $ageBucket;
                }
                $this->accumulate(
                    $summary['priority_queue'][$priorityKey],
                    $gross,
                    $platformRevenue,
                    false,
                    $expectedPlatformRevenue,
                    $expectedPlatformContribution,
                    $estimatedRecoveryCost,
                    $expectedNetPlatformContribution
                );
                $summary['priority_queue'][$priorityKey]['recovery_probability'] = $methodProbability !== null ? round($methodProbability, 4) : null;
                $summary['priority_queue'][$priorityKey]['recovery_probability_source'] = $probabilitySource;
                $summary['priority_queue'][$priorityKey]['platform_contribution_rate'] = $contributionRate !== null ? round($contributionRate, 4) : null;
                $summary['priority_queue'][$priorityKey]['platform_contribution_rate_source'] = $contributionRateSource;
                $summary['priority_queue'][$priorityKey]['recovery_attempt_cost'] = $recoveryAttemptCost;
                $summary['priority_queue'][$priorityKey]['recovery_cost_source'] = $recoveryCostSource;
            }
        }

        $summary = $this->finalize($summary);
        $summary['by_payment_method'] = collect($summary['by_payment_method'])
            ->map(fn (array $row): array => $this->finalize($row))
            ->sort(fn (array $left, array $right): int => $this->compareRecoveryValue($left, $right))
            ->values()->all();
        $summary['by_age_bucket'] = collect($summary['by_age_bucket'])
            ->map(fn (array $row): array => $this->finalize($row))
            ->sortBy(fn (array $row): int => $this->ageBucketPriority((string) $row['age_bucket']))
            ->values()->all();
        $summary['priority_queue'] = collect($summary['priority_queue'])
            ->map(function (array $row): array {
                $row = $this->finalize($row);
                $row['economically_viable'] = $row['recovery_attempt_cost'] !== null
                    ? (float) $row['expected_net_platform_contribution'] > 0.0
                    : null;
                $row['recommended_action'] = $row['economically_viable'] === false
                    ? 'skip_negative_expected_value'
                    : 'recover_unattempted_checkout';
                return $row;
            })
            ->sort(function (array $left, array $right): int {
                $comparison = $this->compareRecoveryValue($left, $right);
                return $comparison !== 0
                    ? $comparison
                    : $this->ageBucketPriority((string) $left['age_bucket']) <=> $this->ageBucketPriority((string) $right['age_bucket']);
            })
            ->values()->all();
        $summary['top_opportunities'] = collect($summary['top_opportunities'])
            ->sort(function (array $left, array $right): int {
                $netComparison = (float) $right['expected_net_platform_contribution'] <=> (float) $left['expected_net_platform_contribution'];
                if ($netComparison !== 0) {
                    return $netComparison;
                }
                $contributionComparison = (float) $right['expected_platform_contribution'] <=> (float) $left['expected_platform_contribution'];
                if ($contributionComparison !== 0) {
                    return $contributionComparison;
                }
                $expectedComparison = (float) $right['expected_platform_revenue'] <=> (float) $left['expected_platform_revenue'];
                if ($expectedComparison !== 0) {
                    return $expectedComparison;
                }
                $platformComparison = (float) $right['platform_revenue'] <=> (float) $left['platform_revenue'];
                if ($platformComparison !== 0) {
                    return $platformComparison;
                }
                $grossComparison = (float) $right['gross_revenue'] <=> (float) $left['gross_revenue'];
                if ($grossComparison !== 0) {
                    return $grossComparison;
                }
                return $this->ageBucketPriority((string) $left['age_bucket']) <=> $this->ageBucketPriority((string) $right['age_bucket']);
            })
            ->take(25)->values()
            ->map(function (array $row, int $index): array {
                foreach (['gross_revenue', 'platform_revenue', 'expected_platform_revenue', 'expected_platform_contribution', 'estimated_recovery_cost', 'expected_net_platform_contribution'] as $key) {
                    if ($row[$key] !== null) {
                        $row[$key] = round((float) $row[$key], 2);
                    }
                }
                $row['priority_rank'] = $index + 1;
                return $row;
            })->all();

        return $summary;
    }

    private function ageBucket(mixed $createdAt, CarbonInterface $now): string
    {
        if (! $createdAt instanceof CarbonInterface) {
            return 'unknown';
        }
        $minutes = max(0, $createdAt->diffInMinutes($now, false));
        return match (true) {
            $minutes < 15 => '0_15m',
            $minutes < 60 => '15_60m',
            $minutes < 360 => '1_6h',
            $minutes < 1440 => '6_24h',
            default => '24h_plus',
        };
    }

    /** @return array<string, mixed> */
    private function emptySummary(): array
    {
        return array_merge($this->emptyBucket(), [
            'by_payment_method' => [],
            'by_age_bucket' => [],
            'priority_queue' => [],
            'top_opportunities' => [],
        ]);
    }

    /** @return array<string, mixed> */
    private function emptyBucket(): array
    {
        return [
            'orders' => 0,
            'gross_revenue' => 0.0,
            'platform_revenue' => 0.0,
            'unattempted_orders' => 0,
            'unattempted_gross_revenue' => 0.0,
            'unattempted_platform_revenue' => 0.0,
            'expected_platform_revenue' => 0.0,
            'expected_platform_contribution' => 0.0,
            'estimated_recovery_cost' => 0.0,
            'expected_net_platform_contribution' => 0.0,
            'recovery_started_orders' => 0,
            'recovery_started_gross_revenue' => 0.0,
        ];
    }

    /** @param array<string, mixed> $row */
    private function accumulate(
        array &$row,
        float $gross,
        float $platformRevenue,
        bool $recoveryStarted,
        float $expectedPlatformRevenue = 0.0,
        float $expectedPlatformContribution = 0.0,
        float $estimatedRecoveryCost = 0.0,
        float $expectedNetPlatformContribution = 0.0
    ): void {
        $row['orders']++;
        $row['gross_revenue'] += $gross;
        $row['platform_revenue'] += $platformRevenue;
        if ($recoveryStarted) {
            $row['recovery_started_orders']++;
            $row['recovery_started_gross_revenue'] += $gross;
            return;
        }
        $row['unattempted_orders']++;
        $row['unattempted_gross_revenue'] += $gross;
        $row['unattempted_platform_revenue'] += $platformRevenue;
        $row['expected_platform_revenue'] += $expectedPlatformRevenue;
        $row['expected_platform_contribution'] += $expectedPlatformContribution;
        $row['estimated_recovery_cost'] += $estimatedRecoveryCost;
        $row['expected_net_platform_contribution'] += $expectedNetPlatformContribution;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function finalize(array $row): array
    {
        foreach (['gross_revenue', 'platform_revenue', 'unattempted_gross_revenue', 'unattempted_platform_revenue', 'expected_platform_revenue', 'expected_platform_contribution', 'estimated_recovery_cost', 'expected_net_platform_contribution', 'recovery_started_gross_revenue'] as $key) {
            $row[$key] = round((float) ($row[$key] ?? 0), 2);
        }
        return $row;
    }

    /** @param array<string, mixed> $left @param array<string, mixed> $right */
    private function compareRecoveryValue(array $left, array $right): int
    {
        $netComparison = (float) ($right['expected_net_platform_contribution'] ?? 0) <=> (float) ($left['expected_net_platform_contribution'] ?? 0);
        if ($netComparison !== 0) {
            return $netComparison;
        }
        $contributionComparison = (float) ($right['expected_platform_contribution'] ?? 0) <=> (float) ($left['expected_platform_contribution'] ?? 0);
        if ($contributionComparison !== 0) {
            return $contributionComparison;
        }
        $expectedComparison = (float) ($right['expected_platform_revenue'] ?? 0) <=> (float) ($left['expected_platform_revenue'] ?? 0);
        if ($expectedComparison !== 0) {
            return $expectedComparison;
        }
        $platformComparison = (float) ($right['unattempted_platform_revenue'] ?? 0) <=> (float) ($left['unattempted_platform_revenue'] ?? 0);
        if ($platformComparison !== 0) {
            return $platformComparison;
        }
        return (float) ($right['unattempted_gross_revenue'] ?? 0) <=> (float) ($left['unattempted_gross_revenue'] ?? 0);
    }

    private function ageBucketPriority(string $bucket): int
    {
        return match ($bucket) {
            '0_15m' => 0,
            '15_60m' => 1,
            '1_6h' => 2,
            '6_24h' => 3,
            '24h_plus' => 4,
            default => 5,
        };
    }
}
