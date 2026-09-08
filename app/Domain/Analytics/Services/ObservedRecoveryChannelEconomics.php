<?php

namespace App\Domain\Analytics\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

final class ObservedRecoveryChannelEconomics
{
    public function __construct(
        private readonly ?RecoveryChannelProfitabilitySelector $selector = null,
    ) {
    }

    /**
     * Build observed recovery economics grouped by payment method and abandonment age.
     *
     * Orders may be Eloquent models or array-like snapshots containing payment_method, status,
     * platform_fee, processor_fee, created_at, recovery_started_at and metadata.
     *
     * @param iterable<mixed> $orders
     * @return array<int, array<string, mixed>>
     */
    public function summarize(iterable $orders): array
    {
        $segments = [];

        foreach ($orders as $order) {
            $recoveryStartedAt = data_get($order, 'recovery_started_at');
            $createdAt = data_get($order, 'created_at');
            if (! $recoveryStartedAt || ! $createdAt) {
                continue;
            }

            $paymentMethod = trim((string) data_get($order, 'payment_method')) ?: 'unknown';
            $channel = trim((string) data_get($order, 'metadata.recovery.channel')) ?: 'unknown';
            $ageBucket = $this->ageBucket($createdAt, $recoveryStartedAt);
            $groupKey = $paymentMethod.'|'.$ageBucket;
            $channelKey = $groupKey.'|'.$channel;

            if (! isset($segments[$channelKey])) {
                $segments[$channelKey] = [
                    'payment_method' => $paymentMethod,
                    'abandonment_age_bucket' => $ageBucket,
                    'channel' => $channel,
                    'attempts' => 0,
                    'recovered_orders' => 0,
                    'known_cost_attempts' => 0,
                    'total_attempt_cost' => 0.0,
                    'recovered_platform_contribution' => 0.0,
                ];
            }

            $segments[$channelKey]['attempts']++;

            $attemptCost = data_get($order, 'metadata.recovery.attempt_cost');
            if (is_numeric($attemptCost) && (float) $attemptCost >= 0) {
                $segments[$channelKey]['known_cost_attempts']++;
                $segments[$channelKey]['total_attempt_cost'] += (float) $attemptCost;
            }

            if ((string) data_get($order, 'status') !== 'paid') {
                continue;
            }

            $segments[$channelKey]['recovered_orders']++;
            $segments[$channelKey]['recovered_platform_contribution'] += $this->platformContribution($order);
        }

        $groups = [];
        foreach ($segments as $segment) {
            $attempts = (int) $segment['attempts'];
            $recovered = (int) $segment['recovered_orders'];
            $knownCostAttempts = (int) $segment['known_cost_attempts'];
            $costCoverage = $attempts > 0 ? $knownCostAttempts / $attempts : 0.0;
            $hasCompleteCostData = $attempts > 0 && $knownCostAttempts === $attempts;
            $averageAttemptCost = $hasCompleteCostData
                ? (float) $segment['total_attempt_cost'] / $attempts
                : null;
            $contributionPerRecoveredOrder = $recovered > 0
                ? (float) $segment['recovered_platform_contribution'] / $recovered
                : 0.0;
            $conversionRate = $attempts > 0 ? ($recovered / $attempts) * 100 : 0.0;

            $candidate = [
                'channel' => $segment['channel'],
                'attempt_cost' => $averageAttemptCost,
                'conversion_rate' => $conversionRate,
                'contribution_per_recovered_order' => $contributionPerRecoveredOrder,
                'attempts' => $attempts,
            ];

            $groupKey = $segment['payment_method'].'|'.$segment['abandonment_age_bucket'];
            $groups[$groupKey] ??= [
                'payment_method' => $segment['payment_method'],
                'abandonment_age_bucket' => $segment['abandonment_age_bucket'],
                'channels' => [],
            ];
            $groups[$groupKey]['channels'][] = [
                ...$candidate,
                'recovered_orders' => $recovered,
                'cost_coverage_percent' => round($costCoverage * 100, 2),
                'total_attempt_cost' => round((float) $segment['total_attempt_cost'], 4),
                'recovered_platform_contribution' => round((float) $segment['recovered_platform_contribution'], 2),
            ];
        }

        $selector = $this->selector ?? new RecoveryChannelProfitabilitySelector();

        return collect($groups)
            ->map(function (array $group) use ($selector): array {
                $rawChannels = $group['channels'];
                $ranked = $selector->rank(array_map(static fn (array $channel): array => [
                    'channel' => $channel['channel'],
                    'attempt_cost' => $channel['attempt_cost'],
                    'conversion_rate' => $channel['conversion_rate'],
                    'contribution_per_recovered_order' => $channel['contribution_per_recovered_order'],
                    'attempts' => $channel['attempts'],
                ], $rawChannels));
                $observedByChannel = collect($rawChannels)->keyBy('channel');
                $channels = collect($ranked)->map(function (array $rank) use ($observedByChannel): array {
                    $observed = $observedByChannel->get($rank['channel'], []);

                    return [
                        ...$rank,
                        'recovered_orders' => (int) ($observed['recovered_orders'] ?? 0),
                        'cost_coverage_percent' => (float) ($observed['cost_coverage_percent'] ?? 0.0),
                        'total_attempt_cost' => (float) ($observed['total_attempt_cost'] ?? 0.0),
                        'recovered_platform_contribution' => (float) ($observed['recovered_platform_contribution'] ?? 0.0),
                    ];
                })->values()->all();

                return [
                    'payment_method' => $group['payment_method'],
                    'abandonment_age_bucket' => $group['abandonment_age_bucket'],
                    'recommended_channel' => collect($channels)->firstWhere('recommended', true)['channel'] ?? null,
                    'channels' => $channels,
                ];
            })
            ->sortBy(fn (array $group): string => $group['payment_method'].'|'.$group['abandonment_age_bucket'])
            ->values()
            ->all();
    }

    private function platformContribution(mixed $order): float
    {
        $platformFee = (float) data_get($order, 'platform_fee', 0);
        $processorFee = (float) data_get($order, 'processor_fee', 0);
        $settlementMode = (string) data_get($order, 'metadata.settlement_mode', 'unknown');

        return $settlementMode === 'platform_collection'
            ? $platformFee - $processorFee
            : $platformFee;
    }

    private function ageBucket(mixed $createdAt, mixed $recoveryStartedAt): string
    {
        $created = $createdAt instanceof CarbonInterface ? $createdAt : Carbon::parse($createdAt);
        $recovery = $recoveryStartedAt instanceof CarbonInterface ? $recoveryStartedAt : Carbon::parse($recoveryStartedAt);
        $minutes = max(0, $created->diffInMinutes($recovery, false));

        return match (true) {
            $minutes < 15 => '0_15m',
            $minutes < 60 => '15_60m',
            $minutes < 360 => '1_6h',
            $minutes < 1440 => '6_24h',
            default => '24h_plus',
        };
    }
}
