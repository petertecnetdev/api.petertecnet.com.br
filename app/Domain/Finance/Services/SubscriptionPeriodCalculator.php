<?php

namespace App\Domain\Finance\Services;

use Carbon\Carbon;
use Carbon\CarbonInterface;

final class SubscriptionPeriodCalculator
{
    /**
     * @return array{start: CarbonInterface, end: CarbonInterface}
     */
    public function renewalPeriod(
        CarbonInterface $now,
        mixed $currentPeriodStart,
        mixed $currentPeriodEnd,
        string $billingInterval,
        int $billingIntervalCount,
    ): array {
        $periodStart = $now->copy();
        $anchor = $now->copy();

        if ($currentPeriodEnd) {
            $existingEnd = Carbon::parse($currentPeriodEnd);

            if ($existingEnd->greaterThan($now)) {
                $anchor = $existingEnd;

                if ($currentPeriodStart) {
                    $periodStart = Carbon::parse($currentPeriodStart);
                }
            }
        }

        $count = max(1, $billingIntervalCount);
        $periodEnd = match ($billingInterval) {
            'year' => $anchor->copy()->addYears($count),
            'week' => $anchor->copy()->addWeeks($count),
            'day' => $anchor->copy()->addDays($count),
            default => $anchor->copy()->addMonthsNoOverflow($count),
        };

        return ['start' => $periodStart, 'end' => $periodEnd];
    }
}
