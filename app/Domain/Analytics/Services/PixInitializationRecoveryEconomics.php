<?php

namespace App\Domain\Analytics\Services;

final class PixInitializationRecoveryEconomics
{
    private const STARTED = 'frontend_pix_initialization_recovery_started';
    private const RESUMED = 'frontend_pix_initialization_resumed';
    private const FAILED = 'frontend_pix_initialization_resume_failed';

    /**
     * Attribute observed PIX initialization recovery outcomes to preserved CommerceOrders.
     * This is descriptive attribution, not causal incremental lift.
     *
     * @param iterable<mixed> $interactions
     * @param iterable<mixed> $orders
     * @return array<string, mixed>
     */
    public function summarize(iterable $interactions, iterable $orders): array
    {
        $journeys = [];
        foreach ($interactions as $interaction) {
            $type = trim((string) data_get($interaction, 'interaction_type'));
            if (! in_array($type, [self::STARTED, self::RESUMED, self::FAILED], true)) {
                continue;
            }

            $publicId = trim((string) data_get($interaction, 'content.metadata.order_public_id'));
            if ($publicId === '') {
                continue;
            }

            $journeys[$publicId] ??= [
                'started' => false,
                'resumed' => false,
                'retryable_failure' => false,
                'terminal_failure' => false,
                'source' => 'unknown',
            ];

            $source = $this->dimension(data_get($interaction, 'content.metadata.source'));
            if ($source !== 'unknown') {
                $journeys[$publicId]['source'] = $source;
            }

            if ($type === self::STARTED) {
                $journeys[$publicId]['started'] = true;
            } elseif ($type === self::RESUMED) {
                $journeys[$publicId]['resumed'] = true;
            } else {
                if (data_get($interaction, 'content.metadata.retryable') === true) {
                    $journeys[$publicId]['retryable_failure'] = true;
                } else {
                    $journeys[$publicId]['terminal_failure'] = true;
                }
            }
        }

        $summary = $this->emptySummary();
        $sources = [];

        foreach ($orders as $order) {
            $publicId = trim((string) data_get($order, 'public_id'));
            $journey = $journeys[$publicId] ?? null;
            if (! $journey) {
                continue;
            }

            $summary['orders_observed']++;
            $summary['started_orders'] += (int) $journey['started'];
            $summary['resumed_orders'] += (int) $journey['resumed'];
            $summary['retryable_failed_orders'] += (int) $journey['retryable_failure'];
            $summary['terminal_failed_orders'] += (int) $journey['terminal_failure'];

            $source = (string) $journey['source'];
            $sources[$source] ??= $this->emptySource($source);
            $sources[$source]['orders_observed']++;
            $sources[$source]['resumed_orders'] += (int) $journey['resumed'];

            if (! $journey['resumed'] || (string) data_get($order, 'status') !== 'paid') {
                continue;
            }

            $grossRevenue = max(0.0, (float) data_get($order, 'total', 0));
            $platformRevenue = max(0.0, (float) data_get($order, 'platform_fee', 0));
            $platformContribution = $this->platformContribution($order);

            $summary['paid_after_resume_orders']++;
            $summary['recovered_gmv'] += $grossRevenue;
            $summary['recovered_platform_revenue'] += $platformRevenue;
            $summary['recovered_platform_contribution'] += $platformContribution;

            $sources[$source]['paid_after_resume_orders']++;
            $sources[$source]['recovered_gmv'] += $grossRevenue;
            $sources[$source]['recovered_platform_revenue'] += $platformRevenue;
            $sources[$source]['recovered_platform_contribution'] += $platformContribution;
        }

        $summary['resume_to_paid_percent'] = $this->rate($summary['paid_after_resume_orders'], $summary['resumed_orders']);
        $summary['observed_platform_take_rate_percent'] = $summary['recovered_gmv'] > 0
            ? round(($summary['recovered_platform_revenue'] / $summary['recovered_gmv']) * 100, 2)
            : null;
        $summary['recovered_gmv'] = round($summary['recovered_gmv'], 2);
        $summary['recovered_platform_revenue'] = round($summary['recovered_platform_revenue'], 2);
        $summary['recovered_platform_contribution'] = round($summary['recovered_platform_contribution'], 2);
        $summary['by_source'] = $this->finalizeSources($sources);

        return $summary;
    }

    /** @return array<string, mixed> */
    private function emptySummary(): array
    {
        return [
            'orders_observed' => 0,
            'started_orders' => 0,
            'resumed_orders' => 0,
            'retryable_failed_orders' => 0,
            'terminal_failed_orders' => 0,
            'paid_after_resume_orders' => 0,
            'resume_to_paid_percent' => null,
            'recovered_gmv' => 0.0,
            'recovered_platform_revenue' => 0.0,
            'recovered_platform_contribution' => 0.0,
            'observed_platform_take_rate_percent' => null,
            'by_source' => [],
            'interpretation' => 'descriptive_observed_recovery_not_causal_incremental_lift',
        ];
    }

    /** @return array<string, mixed> */
    private function emptySource(string $source): array
    {
        return [
            'source' => $source,
            'orders_observed' => 0,
            'resumed_orders' => 0,
            'paid_after_resume_orders' => 0,
            'resume_to_paid_percent' => null,
            'recovered_gmv' => 0.0,
            'recovered_platform_revenue' => 0.0,
            'recovered_platform_contribution' => 0.0,
        ];
    }

    /** @param array<string, array<string, mixed>> $sources @return array<int, array<string, mixed>> */
    private function finalizeSources(array $sources): array
    {
        $rows = array_values($sources);
        foreach ($rows as &$row) {
            $row['resume_to_paid_percent'] = $this->rate((int) $row['paid_after_resume_orders'], (int) $row['resumed_orders']);
            $row['recovered_gmv'] = round((float) $row['recovered_gmv'], 2);
            $row['recovered_platform_revenue'] = round((float) $row['recovered_platform_revenue'], 2);
            $row['recovered_platform_contribution'] = round((float) $row['recovered_platform_contribution'], 2);
        }
        unset($row);

        usort($rows, static fn (array $a, array $b): int => [$b['recovered_gmv'], $b['paid_after_resume_orders']] <=> [$a['recovered_gmv'], $a['paid_after_resume_orders']]);

        return $rows;
    }

    private function platformContribution(mixed $order): float
    {
        $platformFee = max(0.0, (float) data_get($order, 'platform_fee', 0));
        $processorFee = max(0.0, (float) data_get($order, 'processor_fee', 0));

        return (string) data_get($order, 'metadata.settlement_mode', 'unknown') === 'platform_collection'
            ? $platformFee - $processorFee
            : $platformFee;
    }

    private function dimension(mixed $value): string
    {
        $value = strtolower(trim((string) $value));

        return preg_match('/^[a-z][a-z0-9_-]{0,39}$/', $value) ? $value : 'unknown';
    }

    private function rate(int $numerator, int $denominator): ?float
    {
        return $denominator > 0 ? round(($numerator / $denominator) * 100, 2) : null;
    }
}
