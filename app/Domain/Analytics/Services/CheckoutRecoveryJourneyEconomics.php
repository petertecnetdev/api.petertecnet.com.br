<?php

namespace App\Domain\Analytics\Services;

use ArrayAccess;

final class CheckoutRecoveryJourneyEconomics
{
    private const STAGES = [
        'opened' => 'frontend_checkout_opened',
        'attempted' => 'frontend_payment_attempted',
        'approved' => 'frontend_payment_approved',
        'fulfilled' => 'frontend_checkout_fulfilled',
    ];

    private const RECOVERY_MARKER = 'frontend_checkout_recovered';

    /**
     * @param iterable<mixed> $interactions Interactions in chronological order.
     * @param iterable<int|string> $eventIds Events in the producer/organization scope.
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

        $acceptedTypes = [...array_values(self::STAGES), self::RECOVERY_MARKER];
        $journeys = [];

        foreach ($interactions as $interaction) {
            $type = trim((string) $this->value($interaction, ['interaction_type']));
            if (! in_array($type, $acceptedTypes, true)) {
                continue;
            }

            $journeyId = trim((string) $this->value($interaction, ['content', 'metadata', 'checkout_journey_id']));
            if (! preg_match('/^[a-zA-Z0-9._:-]{8,120}$/', $journeyId)) {
                continue;
            }

            $journeys[$journeyId] ??= [
                'event_id' => null,
                'amount' => 0.0,
                'payment_method' => 'unknown',
                'recovered' => false,
                'recovery_source' => 'unknown',
                'stages' => array_fill_keys(array_keys(self::STAGES), false),
            ];

            $rawEventId = $this->value($interaction, ['content', 'metadata', 'event_id']);
            if (is_numeric($rawEventId) && (int) $rawEventId > 0) {
                $journeys[$journeyId]['event_id'] ??= (int) $rawEventId;
            }

            $amount = $this->value($interaction, ['content', 'metadata', 'amount']);
            if (is_numeric($amount) && (float) $amount >= 0) {
                $journeys[$journeyId]['amount'] = max((float) $journeys[$journeyId]['amount'], round((float) $amount, 2));
            }

            $paymentMethod = strtolower(trim((string) $this->value($interaction, ['content', 'metadata', 'payment_method'])));
            if (preg_match('/^[a-z][a-z0-9_-]{1,39}$/', $paymentMethod)) {
                $journeys[$journeyId]['payment_method'] = $paymentMethod;
            }

            $attributionSource = strtolower(trim((string) $this->value($interaction, ['content', 'metadata', 'attribution_source'])));
            if ($type === self::RECOVERY_MARKER || $attributionSource === 'checkout_recovery') {
                $journeys[$journeyId]['recovered'] = true;
                $recoverySource = strtolower(trim((string) $this->value($interaction, ['content', 'metadata', 'attribution_recovery_source'])));
                if (! preg_match('/^[a-z][a-z0-9._:-]{1,80}$/', $recoverySource)) {
                    $recoverySource = $type === self::RECOVERY_MARKER ? 'local_resume' : 'unknown';
                }
                if ($journeys[$journeyId]['recovery_source'] === 'unknown' || $recoverySource !== 'unknown') {
                    $journeys[$journeyId]['recovery_source'] = $recoverySource;
                }
            }

            foreach (self::STAGES as $stage => $stageType) {
                if ($type === $stageType) {
                    $journeys[$journeyId]['stages'][$stage] = true;
                    break;
                }
            }
        }

        $summary = $this->emptySummary();
        $standard = $this->emptyCohortSummary();
        $sources = [];
        $methods = [];

        foreach ($journeys as $journey) {
            $eventId = $journey['event_id'];
            if (! $eventId || ! isset($allowedEventIds[$eventId])) {
                continue;
            }

            if (! $journey['recovered']) {
                $this->accumulateCohort($standard, $journey);
                continue;
            }

            $summary['journeys']++;
            $amount = (float) $journey['amount'];
            foreach (array_keys(self::STAGES) as $stage) {
                if ($journey['stages'][$stage]) {
                    $summary[$stage]++;
                }
            }

            if ($journey['stages']['opened']) {
                $summary['gmv']['opened'] += $amount;
            }
            if ($journey['stages']['approved']) {
                $summary['gmv']['approved'] += $amount;
            }
            if ($journey['stages']['fulfilled']) {
                $summary['gmv']['fulfilled'] += $amount;
            }

            $source = (string) $journey['recovery_source'];
            $sources[$source] ??= $this->dimensionRow('recovery_source', $source);
            $this->accumulateDimension($sources[$source], $journey);

            $method = (string) $journey['payment_method'];
            $methods[$method] ??= $this->dimensionRow('payment_method', $method);
            $this->accumulateDimension($methods[$method], $journey);
        }

        $summary['conversion'] = [
            'opened_to_attempted_percent' => $this->rate($summary['attempted'], $summary['opened']),
            'attempted_to_approved_percent' => $this->rate($summary['approved'], $summary['attempted']),
            'approved_to_fulfilled_percent' => $this->rate($summary['fulfilled'], $summary['approved']),
        ];
        $summary['gmv'] = array_map(static fn (float $value): float => round($value, 2), $summary['gmv']);
        $summary['by_recovery_source'] = $this->finalizeDimensions($sources);
        $summary['by_payment_method'] = $this->finalizeDimensions($methods);

        $standard = $this->finalizeCohort($standard);
        $summary['comparison'] = [
            'standard' => $standard,
            'delta_percentage_points' => [
                'opened_to_attempted' => $this->delta(
                    $summary['conversion']['opened_to_attempted_percent'],
                    $standard['conversion']['opened_to_attempted_percent'],
                ),
                'attempted_to_approved' => $this->delta(
                    $summary['conversion']['attempted_to_approved_percent'],
                    $standard['conversion']['attempted_to_approved_percent'],
                ),
                'approved_to_fulfilled' => $this->delta(
                    $summary['conversion']['approved_to_fulfilled_percent'],
                    $standard['conversion']['approved_to_fulfilled_percent'],
                ),
            ],
            'recovered_fulfilled_gmv_share_percent' => $this->rateFloat(
                (float) $summary['gmv']['fulfilled'],
                (float) $summary['gmv']['fulfilled'] + (float) $standard['gmv']['fulfilled'],
            ),
            'interpretation' => 'descriptive_cohort_comparison_not_causal_incremental_lift',
        ];

        return $summary;
    }

    /** @return array<string, mixed> */
    private function emptySummary(): array
    {
        return [
            'journeys' => 0,
            'opened' => 0,
            'attempted' => 0,
            'approved' => 0,
            'fulfilled' => 0,
            'conversion' => [
                'opened_to_attempted_percent' => null,
                'attempted_to_approved_percent' => null,
                'approved_to_fulfilled_percent' => null,
            ],
            'gmv' => [
                'opened' => 0.0,
                'approved' => 0.0,
                'fulfilled' => 0.0,
            ],
            'by_recovery_source' => [],
            'by_payment_method' => [],
            'comparison' => [
                'standard' => $this->emptyCohortSummary(),
                'delta_percentage_points' => [
                    'opened_to_attempted' => null,
                    'attempted_to_approved' => null,
                    'approved_to_fulfilled' => null,
                ],
                'recovered_fulfilled_gmv_share_percent' => null,
                'interpretation' => 'descriptive_cohort_comparison_not_causal_incremental_lift',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function emptyCohortSummary(): array
    {
        return [
            'journeys' => 0,
            'opened' => 0,
            'attempted' => 0,
            'approved' => 0,
            'fulfilled' => 0,
            'conversion' => [
                'opened_to_attempted_percent' => null,
                'attempted_to_approved_percent' => null,
                'approved_to_fulfilled_percent' => null,
            ],
            'gmv' => [
                'opened' => 0.0,
                'approved' => 0.0,
                'fulfilled' => 0.0,
            ],
        ];
    }

    /** @param array<string, mixed> $cohort @param array<string, mixed> $journey */
    private function accumulateCohort(array &$cohort, array $journey): void
    {
        $cohort['journeys']++;
        $amount = (float) $journey['amount'];
        foreach (array_keys(self::STAGES) as $stage) {
            if ($journey['stages'][$stage]) {
                $cohort[$stage]++;
            }
        }
        if ($journey['stages']['opened']) {
            $cohort['gmv']['opened'] += $amount;
        }
        if ($journey['stages']['approved']) {
            $cohort['gmv']['approved'] += $amount;
        }
        if ($journey['stages']['fulfilled']) {
            $cohort['gmv']['fulfilled'] += $amount;
        }
    }

    /** @param array<string, mixed> $cohort @return array<string, mixed> */
    private function finalizeCohort(array $cohort): array
    {
        $cohort['conversion'] = [
            'opened_to_attempted_percent' => $this->rate((int) $cohort['attempted'], (int) $cohort['opened']),
            'attempted_to_approved_percent' => $this->rate((int) $cohort['approved'], (int) $cohort['attempted']),
            'approved_to_fulfilled_percent' => $this->rate((int) $cohort['fulfilled'], (int) $cohort['approved']),
        ];
        $cohort['gmv'] = array_map(static fn (float $value): float => round($value, 2), $cohort['gmv']);

        return $cohort;
    }

    /** @return array<string, mixed> */
    private function dimensionRow(string $keyName, string $key): array
    {
        return [
            $keyName => $key,
            'journeys' => 0,
            'opened' => 0,
            'attempted' => 0,
            'approved' => 0,
            'fulfilled' => 0,
            'gmv_approved' => 0.0,
        ];
    }

    /** @param array<string, mixed> $row @param array<string, mixed> $journey */
    private function accumulateDimension(array &$row, array $journey): void
    {
        $row['journeys']++;
        foreach (array_keys(self::STAGES) as $stage) {
            $row[$stage] += (int) $journey['stages'][$stage];
        }
        if ($journey['stages']['approved']) {
            $row['gmv_approved'] += (float) $journey['amount'];
        }
    }

    /** @param array<string, array<string, mixed>> $rows @return array<int, array<string, mixed>> */
    private function finalizeDimensions(array $rows): array
    {
        $rows = array_values($rows);
        foreach ($rows as &$row) {
            $row['gmv_approved'] = round((float) $row['gmv_approved'], 2);
            $row['attempted_to_approved_percent'] = $this->rate((int) $row['approved'], (int) $row['attempted']);
        }
        unset($row);
        usort($rows, static fn (array $a, array $b): int => [$b['gmv_approved'], $b['approved'], $b['journeys']] <=> [$a['gmv_approved'], $a['approved'], $a['journeys']]);

        return $rows;
    }

    private function rate(int $numerator, int $denominator): ?float
    {
        return $denominator > 0 ? round(($numerator / $denominator) * 100, 2) : null;
    }

    private function rateFloat(float $numerator, float $denominator): ?float
    {
        return $denominator > 0 ? round(($numerator / $denominator) * 100, 2) : null;
    }

    private function delta(?float $recovered, ?float $standard): ?float
    {
        return $recovered !== null && $standard !== null ? round($recovered - $standard, 2) : null;
    }

    private function value(mixed $source, array $path): mixed
    {
        $value = $source;
        foreach ($path as $segment) {
            if (is_array($value) && array_key_exists($segment, $value)) {
                $value = $value[$segment];
                continue;
            }
            if ($value instanceof ArrayAccess && $value->offsetExists($segment)) {
                $value = $value[$segment];
                continue;
            }
            if (is_object($value) && isset($value->{$segment})) {
                $value = $value->{$segment};
                continue;
            }
            return null;
        }

        return $value;
    }
}
