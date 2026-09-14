<?php

namespace App\Domain\Commerce\Services;

use App\Models\CommerceOrder;
use Illuminate\Support\Carbon;

final class PaidTicketFulfillmentRecoveryMetricsService
{
    public function forApplication(int $appId, int $days = 30): array
    {
        $days = max(1, min($days, 30));
        $since = now()->subDays($days);
        $sinceDate = $since->toDateString();
        $automatic = $this->emptyBucket();
        $manual = $this->emptyBucket();
        $attemptedOrders = 0;
        $attempts = 0;
        $completedAttempts = 0;
        $failedAttempts = 0;
        $unresolvedOrders = 0;
        $successfulAttemptedOrders = 0;
        $lastAttemptAt = null;

        $orders = CommerceOrder::query()
            ->where('app_id', $appId)
            ->where('status', 'paid')
            ->where('updated_at', '>=', $since)
            ->select(['id', 'total', 'platform_fee', 'paid_at', 'metadata'])
            ->orderBy('id')
            ->cursor();

        foreach ($orders as $order) {
            $metadata = (array) $order->metadata;
            $daily = (array) ($metadata['fulfillment_auto_recovery_daily'] ?? []);
            $orderAttempts = 0;
            $orderCompletedAttempts = 0;
            $orderFailedAttempts = 0;
            foreach ($daily as $day => $dailyRow) {
                if (! is_string($day) || $day < $sinceDate) {
                    continue;
                }
                $dailyRow = (array) $dailyRow;
                $orderAttempts += max(0, (int) ($dailyRow['attempts'] ?? 0));
                $orderCompletedAttempts += max(0, (int) ($dailyRow['completed_attempts'] ?? 0));
                $orderFailedAttempts += max(0, (int) ($dailyRow['failures'] ?? 0));
            }
            if ($orderAttempts > 0) {
                $attemptedOrders++;
                $attempts += $orderAttempts;
                $completedAttempts += $orderCompletedAttempts;
                $failedAttempts += $orderFailedAttempts;
                if ($orderCompletedAttempts > 0) {
                    $successfulAttemptedOrders++;
                }
                if (($metadata['fulfillment_status'] ?? null) !== 'completed') {
                    $unresolvedOrders++;
                }
                $lastAttemptAt = $this->latest($lastAttemptAt, $metadata['fulfillment_auto_recovery_last_attempt_at'] ?? null);
            }

            $source = (string) ($metadata['fulfillment_recovery_source'] ?? '');
            $recoveredAt = $this->parseDate($metadata['fulfillment_recovered_at'] ?? null);
            if (! in_array($source, ['automatic', 'manual'], true) || ! $recoveredAt || $recoveredAt->lt($since)) {
                continue;
            }

            if ($source === 'automatic' && $orderAttempts <= 0) {
                continue;
            }

            $bucket = $source === 'automatic' ? $automatic : $manual;
            $bucket['recovered_orders']++;
            $bucket['recovered_passes'] += max(0, (int) ($metadata['fulfillment_recovered_passes'] ?? 0));
            $bucket['protected_gmv'] += max(0, (float) $order->total);
            $bucket['protected_platform_revenue'] += max(0, (float) $order->platform_fee);
            if ($order->paid_at && $recoveredAt->gte($order->paid_at)) {
                $bucket['resolution_seconds'][] = $order->paid_at->diffInSeconds($recoveredAt);
            }

            if ($source === 'automatic') {
                $automatic = $bucket;
            } else {
                $manual = $bucket;
            }
        }

        return [
            'window_days' => $days,
            'automatic_attempted_orders' => $attemptedOrders,
            'automatic_attempts' => $attempts,
            'automatic_completed_attempts' => $completedAttempts,
            'automatic_failed_attempts' => $failedAttempts,
            'automatic_unresolved_orders' => $unresolvedOrders,
            'automatic_success_rate' => $attemptedOrders > 0 ? round($successfulAttemptedOrders / $attemptedOrders, 4) : null,
            'last_automatic_attempt_at' => $lastAttemptAt,
            'automatic' => $this->finalize($automatic),
            'manual' => $this->finalize($manual),
            'measured_at' => now()->toIso8601String(),
            'attribution' => 'observed_fulfillment_recovery',
        ];
    }

    private function emptyBucket(): array
    {
        return [
            'recovered_orders' => 0,
            'recovered_passes' => 0,
            'protected_gmv' => 0.0,
            'protected_platform_revenue' => 0.0,
            'resolution_seconds' => [],
        ];
    }

    private function finalize(array $bucket): array
    {
        $durations = array_values(array_map('intval', $bucket['resolution_seconds'] ?? []));
        sort($durations);
        $count = count($durations);
        $average = $count > 0 ? (int) round(array_sum($durations) / $count) : null;
        $p95 = $count > 0 ? $durations[(int) min($count - 1, ceil($count * 0.95) - 1)] : null;

        return [
            'recovered_orders' => (int) ($bucket['recovered_orders'] ?? 0),
            'recovered_passes' => (int) ($bucket['recovered_passes'] ?? 0),
            'protected_gmv' => round((float) ($bucket['protected_gmv'] ?? 0), 2),
            'protected_platform_revenue' => round((float) ($bucket['protected_platform_revenue'] ?? 0), 2),
            'average_resolution_seconds' => $average,
            'p95_resolution_seconds' => $p95,
        ];
    }

    private function parseDate(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function latest(?string $current, mixed $candidate): ?string
    {
        $candidateAt = $this->parseDate($candidate);
        if (! $candidateAt) {
            return $current;
        }
        $currentAt = $this->parseDate($current);

        return ! $currentAt || $candidateAt->gt($currentAt)
            ? $candidateAt->toIso8601String()
            : $current;
    }
}
