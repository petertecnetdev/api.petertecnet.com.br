<?php

namespace App\Domain\Analytics\Services;

final class CheckoutJourneyFunnel
{
    private const STAGES = [
        'opened' => 'frontend_checkout_opened',
        'mobile_payment_cta' => 'frontend_checkout_mobile_payment_cta_clicked',
        'payment_attempted' => 'frontend_payment_attempted',
        'payment_approved' => 'frontend_payment_approved',
        'fulfilled' => 'frontend_checkout_fulfilled',
    ];

    private const TERMINAL_FAILURES = [
        'frontend_checkout_abandoned',
        'frontend_payment_failed',
    ];

    /**
     * @param iterable<mixed> $interactions Interactions in chronological order.
     * @param iterable<int|string> $eventIds Events that belong to the producer/organization in scope.
     * @return array<string, mixed>
     */
    public function summarize(iterable $interactions, iterable $eventIds): array
    {
        $allowedEventIds = [];
        foreach ($eventIds as $eventId) {
            if (is_numeric($eventId) && (int) $eventId > 0) {
                $allowedEventIds[(int) $eventId] = true;
            }
        }

        if ($allowedEventIds === []) {
            return $this->emptySummary();
        }

        $journeys = [];

        foreach ($interactions as $interaction) {
            $type = trim((string) data_get($interaction, 'interaction_type'));
            if (! in_array($type, array_merge(array_values(self::STAGES), self::TERMINAL_FAILURES), true)) {
                continue;
            }

            $journeyId = trim((string) data_get($interaction, 'content.metadata.checkout_journey_id'));
            if (! preg_match('/^[a-zA-Z0-9._:-]{8,120}$/', $journeyId)) {
                continue;
            }

            $rawEventId = data_get($interaction, 'content.metadata.event_id');
            $eventId = is_numeric($rawEventId) ? (int) $rawEventId : null;
            $knownEventId = $journeys[$journeyId]['event_id'] ?? null;
            $effectiveEventId = $eventId ?: $knownEventId;

            if (! $effectiveEventId || ! isset($allowedEventIds[$effectiveEventId])) {
                continue;
            }

            $journeys[$journeyId] ??= $this->newJourney($effectiveEventId);
            $journeys[$journeyId]['event_id'] = $effectiveEventId;

            $amount = data_get($interaction, 'content.metadata.amount');
            if (is_numeric($amount) && (float) $amount >= 0) {
                $journeys[$journeyId]['amount'] ??= round((float) $amount, 2);
            }

            $paymentMethod = strtolower(trim((string) data_get($interaction, 'content.metadata.payment_method')));
            if (preg_match('/^[a-z][a-z0-9_-]{1,39}$/', $paymentMethod)) {
                $journeys[$journeyId]['payment_method'] = $paymentMethod;
            }

            foreach (self::STAGES as $stage => $stageType) {
                if ($type === $stageType) {
                    $journeys[$journeyId]['stages'][$stage] = true;
                    break;
                }
            }

            if ($type === 'frontend_checkout_abandoned') {
                $journeys[$journeyId]['abandoned'] = true;
            } elseif ($type === 'frontend_payment_failed') {
                $journeys[$journeyId]['payment_failed'] = true;
            }
        }

        if ($journeys === []) {
            return $this->emptySummary();
        }

        $stageCounts = array_fill_keys(array_keys(self::STAGES), 0);
        $methods = [];
        $openedGmv = 0.0;
        $approvedGmv = 0.0;
        $abandonedGmv = 0.0;
        $abandoned = 0;
        $paymentFailed = 0;

        foreach ($journeys as $journey) {
            foreach (array_keys(self::STAGES) as $stage) {
                if ($journey['stages'][$stage]) {
                    $stageCounts[$stage]++;
                }
            }

            $amount = (float) ($journey['amount'] ?? 0.0);
            if ($journey['stages']['opened']) {
                $openedGmv += $amount;
            }
            if ($journey['stages']['payment_approved']) {
                $approvedGmv += $amount;
            }
            if ($journey['abandoned'] && ! $journey['stages']['payment_approved']) {
                $abandoned++;
                $abandonedGmv += $amount;
            }
            if ($journey['payment_failed'] && ! $journey['stages']['payment_approved']) {
                $paymentFailed++;
            }

            $method = $journey['payment_method'] ?: 'unknown';
            $methods[$method] ??= ['journeys' => 0, 'attempted' => 0, 'approved' => 0, 'fulfilled' => 0];
            $methods[$method]['journeys']++;
            $methods[$method]['attempted'] += (int) $journey['stages']['payment_attempted'];
            $methods[$method]['approved'] += (int) $journey['stages']['payment_approved'];
            $methods[$method]['fulfilled'] += (int) $journey['stages']['fulfilled'];
        }

        $opened = $stageCounts['opened'];
        $steps = [
            $this->step('checkout_opened', $opened, $stageCounts['payment_attempted']),
            $this->step('payment_attempted', $stageCounts['payment_attempted'], $stageCounts['payment_approved']),
            $this->step('payment_approved', $stageCounts['payment_approved'], $stageCounts['fulfilled']),
        ];
        $largestDropoff = collect($steps)->sortByDesc('dropoff_journeys')->first();

        $byPaymentMethod = collect($methods)
            ->map(function (array $row, string $method): array {
                return [
                    'payment_method' => $method,
                    ...$row,
                    'attempt_to_approved_rate_percent' => $row['attempted'] > 0
                        ? round(($row['approved'] / $row['attempted']) * 100, 2)
                        : null,
                ];
            })
            ->sortByDesc('attempted')
            ->values()
            ->all();

        return [
            'journeys' => count($journeys),
            'stages' => $stageCounts,
            'conversion' => [
                'opened_to_attempted_percent' => $this->rate($stageCounts['payment_attempted'], $opened),
                'opened_to_approved_percent' => $this->rate($stageCounts['payment_approved'], $opened),
                'attempted_to_approved_percent' => $this->rate($stageCounts['payment_approved'], $stageCounts['payment_attempted']),
                'approved_to_fulfilled_percent' => $this->rate($stageCounts['fulfilled'], $stageCounts['payment_approved']),
            ],
            'dropoff' => [
                'explicit_abandoned_journeys' => $abandoned,
                'payment_failed_journeys' => $paymentFailed,
                'steps' => $steps,
                'largest_step' => $largestDropoff,
            ],
            'gmv' => [
                'opened' => round($openedGmv, 2),
                'approved' => round($approvedGmv, 2),
                'explicit_abandoned_at_risk' => round($abandonedGmv, 2),
            ],
            'by_payment_method' => $byPaymentMethod,
        ];
    }

    /** @return array<string, mixed> */
    private function newJourney(int $eventId): array
    {
        return [
            'event_id' => $eventId,
            'amount' => null,
            'payment_method' => null,
            'abandoned' => false,
            'payment_failed' => false,
            'stages' => array_fill_keys(array_keys(self::STAGES), false),
        ];
    }

    /** @return array<string, mixed> */
    private function step(string $from, int $fromCount, int $toCount): array
    {
        $dropoff = max(0, $fromCount - $toCount);

        return [
            'from' => $from,
            'to' => match ($from) {
                'checkout_opened' => 'payment_attempted',
                'payment_attempted' => 'payment_approved',
                default => 'checkout_fulfilled',
            },
            'from_journeys' => $fromCount,
            'to_journeys' => $toCount,
            'dropoff_journeys' => $dropoff,
            'dropoff_percent' => $fromCount > 0 ? round(($dropoff / $fromCount) * 100, 2) : null,
        ];
    }

    private function rate(int $numerator, int $denominator): ?float
    {
        return $denominator > 0 ? round(($numerator / $denominator) * 100, 2) : null;
    }

    /** @return array<string, mixed> */
    private function emptySummary(): array
    {
        return [
            'journeys' => 0,
            'stages' => array_fill_keys(array_keys(self::STAGES), 0),
            'conversion' => [
                'opened_to_attempted_percent' => null,
                'opened_to_approved_percent' => null,
                'attempted_to_approved_percent' => null,
                'approved_to_fulfilled_percent' => null,
            ],
            'dropoff' => ['explicit_abandoned_journeys' => 0, 'payment_failed_journeys' => 0, 'steps' => [], 'largest_step' => null],
            'gmv' => ['opened' => 0.0, 'approved' => 0.0, 'explicit_abandoned_at_risk' => 0.0],
            'by_payment_method' => [],
        ];
    }
}
