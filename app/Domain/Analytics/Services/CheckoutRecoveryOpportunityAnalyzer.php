<?php

namespace App\Domain\Analytics\Services;

use Carbon\CarbonInterface;

final class CheckoutRecoveryOpportunityAnalyzer
{
    /**
     * @param iterable<object> $orders
     * @return array<string, mixed>
     */
    public function summarize(iterable $orders, ?CarbonInterface $now = null): array
    {
        $now ??= now();
        $summary = $this->emptySummary();

        foreach ($orders as $order) {
            $paymentMethod = trim((string) ($order->payment_method ?? '')) ?: 'unknown';
            $gross = (float) ($order->total ?? 0);
            $platformRevenue = (float) ($order->platform_fee ?? 0);
            $recoveryStarted = $order->recovery_started_at !== null;
            $ageBucket = $this->ageBucket($order->created_at ?? null, $now);

            $this->accumulate($summary, $gross, $platformRevenue, $recoveryStarted);

            if (! isset($summary['by_payment_method'][$paymentMethod])) {
                $summary['by_payment_method'][$paymentMethod] = $this->emptyBucket();
                $summary['by_payment_method'][$paymentMethod]['payment_method'] = $paymentMethod;
            }
            $this->accumulate($summary['by_payment_method'][$paymentMethod], $gross, $platformRevenue, $recoveryStarted);

            if (! isset($summary['by_age_bucket'][$ageBucket])) {
                $summary['by_age_bucket'][$ageBucket] = $this->emptyBucket();
                $summary['by_age_bucket'][$ageBucket]['age_bucket'] = $ageBucket;
            }
            $this->accumulate($summary['by_age_bucket'][$ageBucket], $gross, $platformRevenue, $recoveryStarted);
        }

        $summary = $this->finalize($summary);
        $summary['by_payment_method'] = collect($summary['by_payment_method'])
            ->map(fn (array $row): array => $this->finalize($row))
            ->sortByDesc('unattempted_gross_revenue')
            ->values()
            ->all();
        $summary['by_age_bucket'] = collect($summary['by_age_bucket'])
            ->map(fn (array $row): array => $this->finalize($row))
            ->sortBy(fn (array $row): int => $this->ageBucketPriority((string) $row['age_bucket']))
            ->values()
            ->all();

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
            'recovery_started_orders' => 0,
            'recovery_started_gross_revenue' => 0.0,
        ];
    }

    /** @param array<string, mixed> $row */
    private function accumulate(array &$row, float $gross, float $platformRevenue, bool $recoveryStarted): void
    {
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
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function finalize(array $row): array
    {
        foreach ([
            'gross_revenue',
            'platform_revenue',
            'unattempted_gross_revenue',
            'unattempted_platform_revenue',
            'recovery_started_gross_revenue',
        ] as $key) {
            $row[$key] = round((float) ($row[$key] ?? 0), 2);
        }

        return $row;
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
